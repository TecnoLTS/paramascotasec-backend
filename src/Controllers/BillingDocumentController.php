<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\OrderRepository;
use App\Services\FacturadorApiException;
use App\Services\FacturadorApiService;

class BillingDocumentController {
    private function authenticate(): void {
        Auth::requireAdmin();
    }

    public function rides(): void {
        $this->authenticate();

        try {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $includeCancelled = filter_var($_GET['include_cancelled'] ?? $_GET['includeCancelled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $facturador = new FacturadorApiService();
            Response::json($this->enrichRidesWithAccountingDates($facturador->listRidePdfs($limit, $includeCancelled)));
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'BILLING_RIDES_LIST_FAILED');
        }
    }

    public function ridePdf(string $accessKey): void {
        $this->authenticate();

        try {
            $facturador = new FacturadorApiService();
            $pdf = $facturador->getRidePdf($accessKey);
            $content = (string)($pdf['content'] ?? '');
            $filename = (string)($pdf['filename'] ?? 'RIDE.pdf');

            if ($content === '') {
                Response::error('RIDE PDF vacío o no disponible', 404, 'BILLING_RIDE_PDF_EMPTY');
                return;
            }

            Response::noStore();
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
            header('Content-Length: ' . strlen($content));
            echo $content;
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'BILLING_RIDE_PDF_INVALID_KEY');
        } catch (FacturadorApiException $e) {
            $status = $e->httpStatusCode();
            if ($status === 404) {
                Response::error($e->getMessage(), 404, 'BILLING_RIDE_PDF_NOT_FOUND');
                return;
            }
            if ($status === 409) {
                Response::error($e->getMessage(), 409, 'BILLING_RIDE_PDF_NOT_AVAILABLE');
                return;
            }

            Response::error($e->getMessage(), 502, 'BILLING_RIDE_PDF_UPSTREAM_FAILED');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'BILLING_RIDE_PDF_FAILED');
        }
    }

    public function cancelAndReissue(string $accessKey): void {
        $this->authenticate();

        try {
            $rawInput = file_get_contents('php://input');
            $data = is_string($rawInput) && trim($rawInput) !== '' ? json_decode($rawInput, true) : [];
            if (!is_array($data)) {
                Response::error('JSON inválido', 400, 'BILLING_REISSUE_INVALID_JSON');
                return;
            }

            $reason = trim((string)($data['reason'] ?? ''));
            $confirmation = trim((string)($data['confirm_reissue'] ?? ''));
            if ($confirmation !== 'REEMITIR') {
                Response::error('Confirmación requerida para anular y reemitir. Esta acción puede generar un nuevo comprobante SRI.', 409, 'BILLING_REISSUE_CONFIRMATION_REQUIRED');
                return;
            }

            $ambiente = trim((string)($data['ambiente'] ?? ''));
            $facturador = new FacturadorApiService();
            $result = $facturador->cancelAndReissueInvoice($accessKey, $reason, $ambiente !== '' ? $ambiente : null);
            $this->syncOrderBillingMetadata($result);

            Response::json($result);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'BILLING_REISSUE_INVALID_KEY');
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 409, 'BILLING_REISSUE_CONFLICT');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'BILLING_REISSUE_FAILED');
        }
    }

    private function syncOrderBillingMetadata(array $result): void {
        $oldInvoice = is_array($result['old_invoice'] ?? null) ? $result['old_invoice'] : [];
        $newInvoice = is_array($result['new_invoice'] ?? null) ? $result['new_invoice'] : [];
        $orderId = trim((string)($newInvoice['source_reference'] ?? $oldInvoice['source_reference'] ?? ''));
        if ($orderId === '') {
            return;
        }

        $repository = new OrderRepository();
        $accountingDates = $repository->getAccountingDatesByOrderIds([$orderId]);
        $accountingDate = is_array($accountingDates[$orderId] ?? null) ? $accountingDates[$orderId] : [];

        $metadata = [
            'provider' => 'facturador',
            'status' => 'reissued',
            'invoice_status' => $newInvoice['sri_status'] ?? null,
            'access_key' => $newInvoice['access_key'] ?? null,
            'sequential' => $this->formatSequential($newInvoice),
            'issue_date' => $newInvoice['issue_date'] ?? null,
            'total' => $newInvoice['total'] ?? null,
            'authorization_number' => $newInvoice['authorization_number'] ?? null,
            'authorization_date' => $newInvoice['authorization_date'] ?? null,
            'reissued_at' => date('c'),
            'reissued_from_access_key' => $oldInvoice['access_key'] ?? null,
            'last_attempt_at' => date('c'),
            'last_error' => null,
        ];

        foreach (['accounting_date', 'order_created_at', 'financial_period_key'] as $field) {
            if (!empty($accountingDate[$field])) {
                $metadata[$field] = $accountingDate[$field];
            }
        }
        foreach ([
            'operational_error',
            'operational_error_code',
            'operational_error_label',
            'operational_error_reason',
            'operational_error_marked_at',
            'operational_error_actor',
        ] as $field) {
            if (array_key_exists($field, $newInvoice)) {
                $metadata[$field] = $newInvoice[$field];
            }
        }

        $repository->updateBillingMetadata($orderId, $metadata);
    }

    private function enrichRidesWithAccountingDates(array $rides): array {
        $orderIds = [];
        foreach ($rides as $ride) {
            if (!is_array($ride)) {
                continue;
            }

            $sourceReference = trim((string)($ride['source_reference'] ?? ''));
            if ($sourceReference !== '') {
                $orderIds[] = $sourceReference;
            }
        }

        if (count($orderIds) === 0) {
            return $rides;
        }

        $repository = new OrderRepository();
        $datesByOrderId = $repository->getAccountingDatesByOrderIds($orderIds);
        if (count($datesByOrderId) === 0) {
            return $rides;
        }

        foreach ($rides as $index => $ride) {
            if (!is_array($ride)) {
                continue;
            }

            $sourceReference = trim((string)($ride['source_reference'] ?? ''));
            $dates = is_array($datesByOrderId[$sourceReference] ?? null) ? $datesByOrderId[$sourceReference] : null;
            if ($dates === null) {
                continue;
            }

            $rides[$index]['accounting_date'] = $dates['accounting_date'] ?? null;
            $rides[$index]['order_created_at'] = $dates['order_created_at'] ?? null;
            $rides[$index]['financial_period_key'] = $dates['financial_period_key'] ?? null;
        }

        return $rides;
    }

    private function formatSequential(array $invoice): ?string {
        $parts = [
            $invoice['establishment_code'] ?? null,
            $invoice['emission_point'] ?? null,
            $invoice['sequential'] ?? null,
        ];
        $parts = array_values(array_filter($parts, static fn($value) => is_string($value) && trim($value) !== ''));
        return count($parts) === 3 ? implode('-', $parts) : ($invoice['sequential'] ?? null);
    }
}

<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\TenantContext;
use App\Repositories\OrderRepository;
use App\Repositories\QuotationRepository;
use App\Services\MailService;
use Dompdf\Dompdf;
use Dompdf\Options;

class QuotationController {
    private $quotationRepository;
    private $orderRepository;

    public function __construct() {
        $this->quotationRepository = new QuotationRepository();
        $this->orderRepository = new OrderRepository();
    }

    private function getAdminUser(): array {
        return Auth::requireUser();
    }

    private function normalizeDiscountCodeValue($value): ?string {
        if ($value === null) {
            return null;
        }
        $normalized = strtoupper(trim((string)$value));
        if ($normalized === '') {
            return null;
        }
        return preg_replace('/\s+/', '', $normalized);
    }

    private function resolveBaseUrl(): string {
        $baseUrl = TenantContext::appUrl() ?? ($_ENV['APP_URL'] ?? null);
        if ($baseUrl) {
            return $baseUrl;
        }

        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $proto . '://' . $host;
    }

    private function splitName(string $name): array {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = trim((string)($parts[0] ?? 'Cliente'));
        $last = trim(implode(' ', array_slice($parts, 1)));
        return [
            'first' => $first !== '' ? $first : 'Cliente',
            'last' => $last !== '' ? $last : 'Local',
        ];
    }

    private function buildQuotationEmailBody(array $quotation): string {
        $customerName = trim((string)($quotation['customer_name'] ?? 'cliente'));
        $quoteId = trim((string)($quotation['id'] ?? ''));

        return implode("\n", [
            "Hola {$customerName},",
            '',
            "Te enviamos adjunta tu cotización {$quoteId} en PDF.",
            'Si necesitas ajustes o deseas convertirla en pedido, podemos ayudarte.',
            '',
            'Saludos,',
            'ParaMascotas',
        ]);
    }

    private function formatQuotationDate(?string $value, bool $includeTime = true): string {
        $raw = trim((string)$value);
        if ($raw === '') {
            return 'No definida';
        }

        try {
            $date = new \DateTimeImmutable($raw);
            return $date->format($includeTime ? 'd/m/Y, h:i a' : 'd/m/Y');
        } catch (\Throwable $e) {
            return $raw;
        }
    }

    private function buildQuotationPdfHtml(array $quotation): string {
        $snapshot = is_array($quotation['quote_snapshot'] ?? null) ? $quotation['quote_snapshot'] : [];
        $items = is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [];
        $address = is_array($quotation['customer_address'] ?? null) ? $quotation['customer_address'] : [];
        $frontendBase = TenantContext::appUrl() ?? ($_ENV['FRONTEND_URL'] ?? ($_ENV['APP_URL'] ?? 'https://paramascotasec.com'));
        if (strpos($frontendBase, 'api.') !== false) {
            $frontendBase = str_replace('://api.', '://', $frontendBase);
        }
        $logoUrl = rtrim($frontendBase, '/') . '/images/brand/LogoVerde150.png';
        $logoPath = __DIR__ . '/../../public/images/brand/LogoVerde150.png';
        if (file_exists($logoPath)) {
            $logoUrl = 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoPath));
        }

        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr>'
                . '<td style="padding:12px 14px;border-bottom:1px solid #e2e8f0;vertical-align:top;">'
                . '<div style="font-weight:700;color:#0f172a;font-size:14px;line-height:1.35;">' . htmlspecialchars((string)($item['product_name'] ?? 'Producto')) . '</div>'
                . '</td>'
                . '<td style="padding:12px 10px;border-bottom:1px solid #e2e8f0;text-align:center;vertical-align:top;">' . max(0, (int)($item['quantity'] ?? 0)) . '</td>'
                . '<td style="padding:12px 14px;border-bottom:1px solid #e2e8f0;text-align:right;vertical-align:top;">$' . number_format((float)($item['price'] ?? 0), 2, ',', '.') . '</td>'
                . '<td style="padding:12px 14px;border-bottom:1px solid #e2e8f0;text-align:right;vertical-align:top;font-weight:700;">$' . number_format((float)($item['total'] ?? 0), 2, ',', '.') . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4" style="padding:18px 14px;text-align:center;color:#64748b;">Sin artículos en esta cotización.</td></tr>';
        }

        $notesHtml = trim((string)($quotation['notes'] ?? '')) !== ''
            ? '<div style="margin-top:18px;padding:16px 18px;border:1px solid #dbe3ee;border-radius:14px;background:#f8fbff;">
                    <div style="font-size:11px;text-transform:uppercase;font-weight:700;color:#64748b;letter-spacing:0.04em;margin-bottom:8px;">Observaciones</div>
                    <div style="font-size:13px;color:#0f172a;white-space:pre-wrap;line-height:1.55;">' . nl2br(htmlspecialchars(trim((string)$quotation['notes']))) . '</div>
               </div>'
            : '';

        return '<!doctype html>
        <html lang="es">
        <head>
            <meta charset="utf-8" />
            <title>Cotización ' . htmlspecialchars((string)$quotation['id']) . '</title>
            <style>
                @page { margin: 28px 34px; }
                body { font-family: DejaVu Sans, Arial, sans-serif; color: #0f172a; margin: 0; font-size: 13px; line-height: 1.45; background: #ffffff; }
                .sheet { width: 100%; }
                .header-table, .info-table, .items-table, .totals-table { width: 100%; border-collapse: collapse; }
                .brand-logo { height: 46px; }
                .brand-name { font-size: 22px; font-weight: 800; color: #0b8ca8; line-height: 1; margin: 0 0 4px 0; }
                .brand-subtitle { color:#64748b; font-size: 13px; }
                .quote-code { font-size: 24px; font-weight: 800; color:#0f172a; line-height:1.2; margin-bottom:6px; }
                .muted { color:#64748b; font-size: 12px; line-height: 1.5; }
                .section-card { border:1px solid #dbe3ee; border-radius: 14px; padding: 16px 18px; background:#fff; }
                .section-title { font-size:11px; text-transform:uppercase; font-weight:700; color:#64748b; letter-spacing:0.04em; margin-bottom:8px; }
                .customer-name { font-size:18px; font-weight:700; color:#1e293b; margin:0 0 6px 0; }
                .items-wrap { border:1px solid #dbe3ee; border-radius: 14px; padding: 14px 16px 16px 16px; }
                .items-table th { text-align:left; font-size:11px; text-transform:uppercase; color:#64748b; letter-spacing:0.04em; padding:10px 14px; border-bottom:1px solid #cbd5e1; }
                .summary-box { width: 310px; margin-left:auto; margin-top: 18px; }
                .totals-table td { padding: 8px 0; border-bottom:1px solid #e2e8f0; font-size:14px; }
                .totals-table td:last-child { text-align:right; font-weight:700; }
                .totals-table .grand td { font-size:20px; font-weight:800; color:#0f172a; padding-top: 12px; }
                .footer { margin-top:24px; font-size:12px; color:#64748b; }
            </style>
        </head>
        <body>
            <div class="sheet">
                <table class="header-table" style="margin-bottom:24px;">
                    <tr>
                        <td style="width:55%; vertical-align:top;">
                            <table style="border-collapse:collapse;">
                                <tr>
                                    <td style="vertical-align:top; padding-right:12px;">
                                        <img class="brand-logo" src="' . htmlspecialchars($logoUrl) . '" alt="ParaMascotas" />
                                    </td>
                                    <td style="vertical-align:top;">
                                        <div class="brand-name">ParaMascotas</div>
                                        <div class="brand-subtitle">Cotización comercial de productos</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                        <td style="width:45%; text-align:right; vertical-align:top;">
                            <div class="quote-code">' . htmlspecialchars((string)$quotation['id']) . '</div>
                            <div class="muted">Emitida: ' . htmlspecialchars($this->formatQuotationDate((string)($quotation['created_at'] ?? ''), true)) . '</div>
                            <div class="muted">Válida hasta: ' . htmlspecialchars($this->formatQuotationDate((string)($quotation['valid_until'] ?? ''), false)) . '</div>
                        </td>
                    </tr>
                </table>
                <table class="info-table" style="margin-bottom:20px;">
                    <tr>
                        <td style="width:52%; vertical-align:top; padding-right:8px;">
                            <div class="section-card">
                                <div class="section-title">Cliente</div>
                                <div class="customer-name">' . htmlspecialchars((string)($quotation['customer_name'] ?? 'Cliente')) . '</div>
                                <div class="muted">Documento: ' . htmlspecialchars((string)($quotation['customer_document_number'] ?? 'No indicado')) . '</div>
                                <div class="muted">Teléfono: ' . htmlspecialchars((string)($quotation['customer_phone'] ?? 'No indicado')) . '</div>
                                <div class="muted">Correo: ' . htmlspecialchars((string)($quotation['customer_email'] ?? 'No indicado')) . '</div>
                            </div>
                        </td>
                        <td style="width:48%; vertical-align:top; padding-left:8px;">
                            <div class="section-card">
                                <div class="section-title">Entrega y condiciones</div>
                                <div class="muted">Modalidad: Retiro en tienda</div>
                                <div class="muted">Dirección: ' . htmlspecialchars((string)($address['street'] ?? 'No indicada')) . '</div>
                                <div class="muted">Ciudad: ' . htmlspecialchars((string)($address['city'] ?? 'No indicada')) . '</div>
                                <div class="muted">Descuento: ' . htmlspecialchars((string)($quotation['discount_code'] ?? 'Sin código')) . '</div>
                            </div>
                        </td>
                    </tr>
                </table>
                <div class="items-wrap">
                    <div class="section-title" style="margin-bottom:10px;">Detalle cotizado</div>
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th style="width:52%;">Producto</th>
                                <th style="width:12%; text-align:center;">Cant.</th>
                                <th style="width:18%; text-align:right;">PVP</th>
                                <th style="width:18%; text-align:right;">Total</th>
                            </tr>
                        </thead>
                        <tbody>' . $rows . '</tbody>
                    </table>
                </div>
                <div class="summary-box">
                    <table class="totals-table">
                        <tr><td>Subtotal</td><td>$' . number_format((float)($snapshot['vat_subtotal'] ?? 0), 2, ',', '.') . '</td></tr>
                        <tr><td>IVA</td><td>$' . number_format((float)($snapshot['vat_amount'] ?? 0), 2, ',', '.') . '</td></tr>
                        <tr class="grand"><td>Total</td><td>$' . number_format((float)($snapshot['total'] ?? 0), 2, ',', '.') . '</td></tr>
                    </table>
                </div>
                ' . $notesHtml . '
                <div class="footer">
                    Esta cotización es informativa y no descuenta inventario ni genera pedido hasta confirmar la venta.
                </div>
            </div>
        </body>
        </html>';
    }

    private function generateQuotationPdf(array $quotation): string {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->buildQuotationPdfHtml($quotation), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        return $dompdf->output();
    }

    private function sendQuotationEmail(array $quotation): array {
        $recipient = trim((string)($quotation['customer_email'] ?? ''));
        if ($recipient === '') {
            return ['requested' => true, 'sent' => false, 'recipient' => null, 'message' => 'No se indicó correo para enviar la cotización.'];
        }
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return ['requested' => true, 'sent' => false, 'recipient' => $recipient, 'message' => 'El correo indicado no es válido.'];
        }

        $subject = 'Cotización ' . trim((string)($quotation['id'] ?? '')) . ' - ParaMascotas';
        $message = $this->buildQuotationEmailBody($quotation);
        $attachmentName = trim((string)($quotation['id'] ?? 'cotizacion')) . '.pdf';
        $pdfBinary = $this->generateQuotationPdf($quotation);
        $sent = MailService::sendWithAttachment($recipient, $subject, $message, $attachmentName, $pdfBinary, 'application/pdf');

        return [
            'requested' => true,
            'sent' => $sent,
            'recipient' => $recipient,
            'message' => $sent
                ? 'Cotización enviada correctamente por correo con PDF adjunto.'
                : 'No se pudo enviar la cotización por correo.',
        ];
    }

    public function index() {
        $this->getAdminUser();
        try {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            Response::json($this->quotationRepository->listRecent($limit));
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'QUOTATION_LIST_FAILED');
        }
    }

    public function store() {
        $user = $this->getAdminUser();
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            if (count($items) === 0) {
                Response::error('Agrega al menos un producto para cotizar.', 400, 'QUOTATION_ITEMS_REQUIRED');
                return;
            }

            $customerName = trim((string)($data['customer_name'] ?? ''));
            if (mb_strlen($customerName) < 3) {
                Response::error('Ingresa el nombre del cliente para generar la cotización.', 400, 'QUOTATION_CUSTOMER_REQUIRED');
                return;
            }

            $discountCode = $this->normalizeDiscountCodeValue($data['discount_code'] ?? null);
            $quote = $this->orderRepository->calculateQuote(
                $items,
                $data['delivery_method'] ?? 'pickup',
                $discountCode,
                'quote',
                null,
                null
            );

            $createdAt = new \DateTimeImmutable('now');
            $validUntil = $createdAt->modify('+7 days');
            $quotationId = 'COT-' . $createdAt->format('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));

            $quotation = $this->quotationRepository->create([
                'id' => $quotationId,
                'status' => 'quoted',
                'customer_name' => $customerName,
                'customer_document_type' => trim((string)($data['customer_document_type'] ?? '')) ?: null,
                'customer_document_number' => trim((string)($data['customer_document_number'] ?? '')) ?: null,
                'customer_email' => trim((string)($data['customer_email'] ?? '')) ?: null,
                'customer_phone' => trim((string)($data['customer_phone'] ?? '')) ?: null,
                'customer_address' => is_array($data['customer_address'] ?? null) ? $data['customer_address'] : [],
                'delivery_method' => 'pickup',
                'payment_method' => trim((string)($data['payment_method'] ?? '')) ?: null,
                'discount_code' => $discountCode,
                'notes' => trim((string)($data['notes'] ?? '')) ?: null,
                'items' => $items,
                'quote_snapshot' => $quote,
                'created_by_user_id' => (string)($user['sub'] ?? 'service'),
                'valid_until' => $validUntil->format(DATE_ATOM),
            ]);

            $emailDelivery = [
                'requested' => false,
                'sent' => false,
                'recipient' => null,
                'message' => null,
            ];
            if (!empty($data['send_email'])) {
                $emailDelivery = $this->sendQuotationEmail($quotation);
            }

            Response::json([
                ...$quotation,
                'email_delivery' => $emailDelivery,
            ], 201);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'QUOTATION_CREATE_FAILED');
        }
    }

    public function convert($id) {
        $user = $this->getAdminUser();
        try {
            $quotation = $this->quotationRepository->getById((string)$id);
            if (!$quotation) {
                Response::error('Cotización no encontrada.', 404, 'QUOTATION_NOT_FOUND');
                return;
            }

            if (($quotation['status'] ?? 'quoted') === 'converted' && !empty($quotation['converted_order_id'])) {
                Response::error('Esta cotización ya fue convertida a venta.', 409, 'QUOTATION_ALREADY_CONVERTED');
                return;
            }

            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $paymentMethod = trim((string)($data['payment_method'] ?? ''));
            if ($paymentMethod === '') {
                Response::error('Método de pago requerido para convertir la cotización.', 400, 'QUOTATION_PAYMENT_METHOD_REQUIRED');
                return;
            }

            $paymentDetails = is_array($data['payment_details'] ?? null) ? $data['payment_details'] : [];
            $customerAddress = is_array($quotation['customer_address'] ?? null) ? $quotation['customer_address'] : [];
            $customerName = trim((string)($quotation['customer_name'] ?? 'Cliente local'));
            $nameParts = $this->splitName($customerName);

            $orderAddress = [
                'firstName' => $nameParts['first'],
                'lastName' => $nameParts['last'],
                'phone' => trim((string)($quotation['customer_phone'] ?? '')) ?: null,
                'email' => trim((string)($quotation['customer_email'] ?? '')) ?: null,
                'street' => trim((string)($customerAddress['street'] ?? '')) ?: null,
                'city' => trim((string)($customerAddress['city'] ?? '')) ?: null,
                'state' => trim((string)($customerAddress['state'] ?? '')) ?: null,
                'country' => trim((string)($customerAddress['country'] ?? 'EC')) ?: 'EC',
                'zip' => trim((string)($customerAddress['zip'] ?? '')) ?: null,
                'documentType' => trim((string)($quotation['customer_document_type'] ?? '')) ?: null,
                'documentNumber' => trim((string)($quotation['customer_document_number'] ?? '')) ?: null,
            ];

            $orderPayload = [
                'id' => 'ORD-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4))),
                'user_id' => (string)($user['sub'] ?? 'service'),
                'status' => 'completed',
                'delivery_method' => 'pickup',
                'payment_method' => $paymentMethod,
                'shipping_address' => $orderAddress,
                'billing_address' => $orderAddress,
                'order_notes' => trim((string)($quotation['notes'] ?? 'Cotización convertida a venta')) ?: 'Cotización convertida a venta',
                'coupon_code' => $this->normalizeDiscountCodeValue($quotation['discount_code'] ?? null),
                'payment_details' => array_merge($paymentDetails, [
                    'channel' => 'local_pos',
                    'quotation_id' => (string)$quotation['id'],
                    'converted_from_quote' => true,
                ]),
                'items' => array_map(static function ($item): array {
                    return [
                        'product_id' => (string)($item['product_id'] ?? ''),
                        'quantity' => max(0, (int)($item['quantity'] ?? 0)),
                    ];
                }, is_array($quotation['items'] ?? null) ? $quotation['items'] : []),
            ];

            $order = $this->orderRepository->create($orderPayload, $this->resolveBaseUrl());
            $updatedQuotation = $this->quotationRepository->markConverted((string)$quotation['id'], (string)($order['id'] ?? ''));

            Response::json([
                'quotation' => $updatedQuotation,
                'order' => $order,
            ], 201);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'QUOTATION_CONVERT_FAILED');
        }
    }
}

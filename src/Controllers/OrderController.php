<?php

namespace App\Controllers;

use App\Repositories\OrderRepository;
use App\Repositories\AuthSecurityRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Core\Response;
use App\Core\Auth;
use App\Core\TenantContext;
use App\Services\FacturadorApiService;
use Dompdf\Dompdf;
use Dompdf\Options;

class OrderController {
    private $orderRepository;
    private $userRepository;
    private $authSecurityRepository;
    private $forbiddenDiscountPayloadKeys = [
        'discount',
        'discounts',
        'coupon',
        'promo',
        'promo_code',
        'promotion',
        'promotions'
    ];
    private $forbiddenMoneyPayloadKeys = [
        'total',
        'subtotal',
        'items_subtotal',
        'vat_subtotal',
        'vat_amount',
        'vat_rate',
        'shipping',
        'shipping_base',
        'shipping_tax_rate',
        'shipping_tax_amount',
        'discount_total',
        'discount_amount',
        'discount_value',
        'net_total',
        'grand_total',
        'amount'
    ];
    private $forbiddenItemPricingKeys = [
        'price',
        'unit_price',
        'subtotal',
        'total',
        'discount_total',
        'discount_amount',
        'net_total',
        'tax_amount',
        'tax_rate',
        'unit_cost',
        'cost_total'
    ];

    public function __construct() {
        $this->orderRepository = new OrderRepository();
        $this->userRepository = new UserRepository();
        $this->authSecurityRepository = new AuthSecurityRepository();
    }

    private function authenticate() {
        return Auth::requireUser();
    }

    private function authenticateOptional() {
        return Auth::optionalUser();
    }

    private function shouldEmitFacturadorInvoiceForStatus(?string $status): bool
    {
        $normalized = strtolower(trim((string)$status));
        return in_array($normalized, ['completed', 'delivered'], true);
    }

    private function dispatchFacturadorInvoiceAfterResponse(array $order): void
    {
        if (!$order || empty($order['id']) || !$this->shouldEmitFacturadorInvoiceForStatus($order['status'] ?? null)) {
            return;
        }

        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        try {
            $this->emitInvoiceWithFacturador($order);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[FACTURADOR_DEFERRED_DISPATCH_FAILED] orden=%s error=%s',
                (string)($order['id'] ?? 'N/A'),
                $e->getMessage()
            ));
        }
    }

    private function customerCanCancelOrder(array $order, array $user): bool
    {
        if (empty($user['sub']) || empty($order['user_id']) || (string)$order['user_id'] !== (string)$user['sub']) {
            return false;
        }

        $status = strtolower(trim((string)($order['status'] ?? 'pending')));
        return in_array($status, ['pending', 'processing'], true);
    }

    public function index() {
        $user = $this->authenticate();
        try {
            $isAdmin = (($user['role'] ?? 'customer') === 'admin');
            if ($isAdmin) {
                $orders = $this->orderRepository->getAll();
            } else {
                if (empty($user['sub'])) {
                    Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
                    return;
                }
                $orders = $this->orderRepository->getByUserId($user['sub']);
            }
            Response::json($orders);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'ORDERS_LIST_FAILED');
        }
    }
    
    public function myOrders() {
        $user = $this->authenticate();
        try {
            if (($user['role'] ?? 'customer') === 'guest' || empty($user['sub'])) {
                Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
                return;
            }
            $orders = $this->orderRepository->getByUserId($user['sub']); // 'sub' is user id in JWT
            Response::json($orders);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'ORDERS_LIST_FAILED');
        }
    }

    public function show($id) {
        $user = $this->authenticate();
        try {
            $order = $this->orderRepository->getById($id);
            if (!$order) {
                Response::error('Pedido no encontrado', 404, 'ORDER_NOT_FOUND');
                return;
            }
            // Permission check: admin or owner
            $isAdmin = (($user['role'] ?? 'customer') === 'admin');
            if (!$isAdmin && $order['user_id'] !== $user['sub']) {
                Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
                return;
            }
            
            Response::json($order);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'ORDER_FETCH_FAILED');
        }
    }

    public function updateStatus($id) {
        $user = $this->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['status'])) {
            Response::error('Estado requerido', 400, 'ORDER_STATUS_REQUIRED');
            return;
        }

        $allowedStatuses = ['pending', 'processing', 'shipped', 'delivered', 'completed', 'canceled'];
        $newStatus = strtolower(trim((string)$data['status']));
        if (!in_array($newStatus, $allowedStatuses, true)) {
            Response::error('Estado inválido', 400, 'ORDER_STATUS_INVALID');
            return;
        }

        try {
            $order = $this->orderRepository->getById($id);
            if (!$order) {
                Response::error('Pedido no encontrado', 404, 'ORDER_NOT_FOUND');
                return;
            }
            $isAdmin = (($user['role'] ?? 'customer') === 'admin');
            if (!$isAdmin) {
                $isCustomerCancel = $newStatus === 'canceled' && $this->customerCanCancelOrder($order, $user);
                if (!$isCustomerCancel) {
                    Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
                    return;
                }
            }

            $updated = $this->orderRepository->updateStatus($id, $newStatus);
            Response::json($updated);
            $this->dispatchFacturadorInvoiceAfterResponse($updated ?: []);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'ORDER_STATUS_UPDATE_FAILED');
        }
    }

    public function invoice($id) {
        $user = $this->authenticate();
        try {
            $order = $this->orderRepository->getById($id);
            if (!$order) {
                Response::error('Pedido no encontrado', 404, 'ORDER_NOT_FOUND');
                return;
            }
            $isAdmin = ($user['role'] ?? '') === 'admin';
            if (!$isAdmin && $order['user_id'] !== $user['sub']) {
                Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
                return;
            }
            if (!$isAdmin && ($order['status'] ?? '') === 'canceled') {
                Response::error('Factura no disponible para pedidos cancelados', 403, 'ORDER_INVOICE_UNAVAILABLE');
                return;
            }
            $invoiceHtml = $this->resolveInvoiceHtml($order);
            if (!$invoiceHtml) {
                Response::error('Factura no disponible', 404, 'ORDER_INVOICE_UNAVAILABLE');
                return;
            }

            $format = strtolower(trim((string)($_GET['format'] ?? 'html')));
            if ($format === 'pdf') {
                $pdfBinary = $this->renderInvoicePdf($invoiceHtml);
                $safeOrderId = preg_replace('/[^A-Za-z0-9_-]/', '-', (string)$order['id']);
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="comprobante-' . $safeOrderId . '.pdf"');
                header('Content-Length: ' . strlen($pdfBinary));
                echo $pdfBinary;
                return;
            }

            header('Content-Type: text/html; charset=utf-8');
            echo $invoiceHtml;
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'ORDER_INVOICE_FAILED');
        }
    }

    private function resolveInvoiceHtml(array &$order): ?string {
        if (empty($order['invoice_html'])) {
            $baseUrl = TenantContext::appUrl() ?? ($_ENV['APP_URL'] ?? null);
            if (!$baseUrl) {
                $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $baseUrl = $proto . '://' . $host;
            }
            $invoiceHtml = $this->orderRepository->ensureInvoiceForOrder($order, $baseUrl);
            if (!$invoiceHtml) {
                return null;
            }
            $order['invoice_html'] = $invoiceHtml;
            return $invoiceHtml;
        }

        $invoiceData = null;
        if (!empty($order['invoice_data'])) {
            $invoiceData = json_decode($order['invoice_data'], true);
        }
        $customerName = $invoiceData['customer']['name'] ?? null;
        $subtotalGross = 0.0;
        if (!empty($order['items']) && is_array($order['items'])) {
            foreach ($order['items'] as $item) {
                $subtotalGross += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 1);
            }
        }
        $discountTotal = max(0, (float)($order['discount_total'] ?? 0));
        $expectedShipping = (float)($order['total'] ?? $subtotalGross) - max(0, ($subtotalGross - $discountTotal));
        if ($expectedShipping < 0) {
            $expectedShipping = 0;
        }
        $showsZeroShipping = strpos($order['invoice_html'], 'Envío</span><span>$0') !== false
            || strpos($order['invoice_html'], 'Envío</span><span>$0,00') !== false
            || strpos($order['invoice_html'], 'Envío</span><span>$0.00') !== false;
        $needsRegenerate = empty($customerName)
            || (strpos($order['invoice_html'], 'LogoVerde150.png') !== false && strpos($order['invoice_html'], 'api.') !== false)
            || (strpos($order['invoice_html'], 'brand-name') !== false)
            || (strpos($order['invoice_html'], 'invoice_v2_tax_net') === false)
            || ($expectedShipping > 0 && $showsZeroShipping);
        if ($needsRegenerate) {
            $baseUrl = TenantContext::appUrl() ?? ($_ENV['APP_URL'] ?? null);
            if (!$baseUrl) {
                $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $baseUrl = $proto . '://' . $host;
            }
            $invoiceHtml = $this->orderRepository->ensureInvoiceForOrder($order, $baseUrl, true);
            if ($invoiceHtml) {
                $order['invoice_html'] = $invoiceHtml;
            }
        }

        return !empty($order['invoice_html']) ? (string)$order['invoice_html'] : null;
    }

    private function renderInvoicePdf(string $invoiceHtml): string {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($invoiceHtml, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    private function decodeAddress($value) {
        if (!$value) return [];
        if (is_array($value)) return $value;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function emitInvoiceWithFacturador(array $order): void {
        if (empty($order['id'])) {
            return;
        }

        try {
            $payload = $this->buildFacturadorPayload($order);
            $facturador = new FacturadorApiService();
            $invoice = $facturador->emitInvoice($payload);

            $this->orderRepository->updateBillingMetadata((string)$order['id'], [
                'provider' => 'facturador',
                'status' => 'issued',
                'invoice_status' => $invoice['status'] ?? null,
                'access_key' => $invoice['access_key'] ?? null,
                'sequential' => $invoice['sequential'] ?? null,
                'issue_date' => $invoice['issue_date'] ?? null,
                'total' => $invoice['total'] ?? null,
                'authorization_number' => $invoice['authorization_number'] ?? null,
                'authorization_date' => $invoice['authorization_date'] ?? null,
                'pdf_url' => $invoice['pdf_url'] ?? null,
                'xml_url' => $invoice['xml_url'] ?? null,
                'last_attempt_at' => date('c'),
                'last_error' => null,
            ]);

            error_log(sprintf(
                '[FACTURADOR] Factura emitida para orden %s. Clave=%s',
                $order['id'],
                $invoice['access_key'] ?? 'N/A'
            ));
        } catch (\Throwable $e) {
            $this->orderRepository->updateBillingMetadata((string)$order['id'], [
                'provider' => 'facturador',
                'status' => 'failed',
                'last_attempt_at' => date('c'),
                'last_error' => $e->getMessage(),
            ]);

            error_log(sprintf(
                '[FACTURADOR] Error facturando orden %s: %s',
                $order['id'],
                $e->getMessage()
            ));
        }
    }

    private function resolveCustomerUserId(array $data, array $user): ?string {
        $role = strtolower(trim((string)($user['role'] ?? 'customer')));
        $channel = strtolower(trim((string)($data['payment_details']['channel'] ?? '')));

        if ($role === 'admin' && $channel === 'local_pos') {
            $shippingAddress = $this->decodeAddress($data['shipping_address'] ?? null);
            $billingAddress = $this->decodeAddress($data['billing_address'] ?? null);
            $address = !empty($shippingAddress) ? $shippingAddress : $billingAddress;

            $firstName = trim((string)($address['firstName'] ?? ''));
            $lastName = trim((string)($address['lastName'] ?? ''));
            $fullName = trim((string)($address['name'] ?? trim($firstName . ' ' . $lastName)));

            $customerUser = $this->userRepository->upsertLocalSaleCustomer([
                'name' => $fullName !== '' ? $fullName : 'Cliente local',
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $address['email'] ?? null,
                'phone' => $address['phone'] ?? null,
                'street' => $address['street'] ?? null,
                'city' => $address['city'] ?? null,
                'document_type' => $address['documentType'] ?? null,
                'document_number' => $address['documentNumber'] ?? null,
                'business_name' => $address['company'] ?? null,
            ]);

            return $customerUser['id'] ?? null;
        }

        return !empty($user['sub']) ? (string)$user['sub'] : null;
    }

    private function buildFacturadorPayload(array $order): array {
        $billingAddress = $this->decodeAddress($order['billing_address'] ?? null);
        $shippingAddress = $this->decodeAddress($order['shipping_address'] ?? null);
        $customerAddressData = $billingAddress ?: $shippingAddress;

        $customerName = trim(($customerAddressData['firstName'] ?? '') . ' ' . ($customerAddressData['lastName'] ?? ''));
        if ($customerName === '') {
            $customerName = trim((string)($order['user_name'] ?? '')) ?: 'CONSUMIDOR FINAL';
        }

        $customerIdentification = trim((string)($customerAddressData['documentNumber'] ?? ''));
        if ($customerIdentification === '') {
            $customerIdentification = '9999999999999';
        }

        $customerAddress = implode(', ', array_filter([
            $customerAddressData['street'] ?? null,
            $customerAddressData['city'] ?? null,
            $customerAddressData['state'] ?? null,
            $customerAddressData['country'] ?? null,
        ]));
        if ($customerAddress === '') {
            $customerAddress = 'Ecuador';
        }

        $grossItems = is_array($order['items'] ?? null) ? array_values($order['items']) : [];
        $discountAllocations = $this->allocateOrderDiscountAcrossItems($grossItems, (float)($order['discount_total'] ?? 0));
        $fallbackVatRate = isset($order['vat_rate']) && is_numeric($order['vat_rate']) ? max(0, (float)$order['vat_rate']) : 0.0;

        $items = [];
        foreach ($grossItems as $index => $item) {
            $quantity = max(1, (int)($item['quantity'] ?? 1));
            $grossUnitPrice = (float)($item['price'] ?? 0);
            $grossLineTotal = round($grossUnitPrice * $quantity, 2);
            $allocatedDiscount = (float)($discountAllocations[$index] ?? 0);
            $discountedGrossLine = max(0, $grossLineTotal - $allocatedDiscount);
            $itemTaxRate = isset($item['tax_rate']) && is_numeric($item['tax_rate'])
                ? max(0, (float)$item['tax_rate'])
                : $fallbackVatRate;
            if (isset($item['tax_exempt'])) {
                $normalizedTaxExempt = filter_var($item['tax_exempt'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($normalizedTaxExempt === true) {
                    $itemTaxRate = 0.0;
                }
            }
            $taxDivisor = 1 + ($itemTaxRate / 100);
            $originalNetUnitPrice = $taxDivisor > 0 ? ($grossUnitPrice / $taxDivisor) : $grossUnitPrice;
            $lineSubtotalNet = $taxDivisor > 0 ? ($discountedGrossLine / $taxDivisor) : $discountedGrossLine;
            $discountNetAmount = max(0, round(($originalNetUnitPrice * $quantity) - $lineSubtotalNet, 6));
            $taxAmount = max(0, round($discountedGrossLine - $lineSubtotalNet, 6));

            $items[] = [
                'code' => (string)($item['product_id'] ?? ('ITEM-' . ($index + 1))),
                'description' => (string)($item['product_name'] ?? 'Producto'),
                'quantity' => $quantity,
                'unit_price' => round($originalNetUnitPrice, 6),
                'discount' => $discountNetAmount,
                'line_subtotal_net' => round($lineSubtotalNet, 6),
                'tax_rate' => round($itemTaxRate, 2),
                'tax_code' => '2',
                'tax_percentage_code' => $this->resolveSriVatPercentageCode($itemTaxRate),
                'tax_amount' => $taxAmount,
            ];
        }

        $shipping = max(0, (float)($order['shipping'] ?? 0));
        if ($shipping > 0) {
            $shippingTaxRate = $this->resolveShippingTaxRate($order);
            $shippingBase = isset($order['shipping_base']) && is_numeric($order['shipping_base'])
                ? (float)$order['shipping_base']
                : ($shipping / (1 + ($shippingTaxRate / 100)));
            $shippingTaxAmount = max(0, round($shipping - $shippingBase, 6));

            $items[] = [
                'code' => 'ENVIO',
                'description' => 'Servicio de envio',
                'quantity' => 1,
                'unit_price' => round($shippingBase, 6),
                'discount' => 0,
                'line_subtotal_net' => round($shippingBase, 6),
                'tax_rate' => round($shippingTaxRate, 2),
                'tax_code' => '2',
                'tax_percentage_code' => $this->resolveSriVatPercentageCode($shippingTaxRate),
                'tax_amount' => $shippingTaxAmount,
            ];
        }

        $paymentMethod = $this->resolveSriPaymentMethod((string)($order['payment_method'] ?? ''));

        return [
            'customer_identification' => $customerIdentification,
            'customer_name' => $customerName,
            'customer_address' => $customerAddress,
            'customer_email' => (string)($customerAddressData['email'] ?? $order['user_email'] ?? ''),
            'payment_method' => $paymentMethod['label'],
            'payment_method_code' => $paymentMethod['code'],
            'items' => $items,
            'additional_info' => array_filter([
                'order_id' => $order['id'] ?? null,
                'tenant_id' => TenantContext::id(),
                'payment_method' => $paymentMethod['label'],
                'payment_method_code' => $paymentMethod['code'],
                'notes' => $order['order_notes'] ?? null,
            ], static fn($value) => $value !== null && $value !== ''),
        ];
    }

    private function resolveShippingTaxRate(array $order): float {
        return isset($order['shipping_tax_rate']) && is_numeric($order['shipping_tax_rate'])
            ? max(0, (float)$order['shipping_tax_rate'])
            : 0.0;
    }

    private function resolveSriVatPercentageCode(float $taxRate): string
    {
        if ($taxRate <= 0) {
            return '0';
        }

        if (abs($taxRate - 15.0) < 0.0001) {
            return '4';
        }

        return '4';
    }

    private function allocateOrderDiscountAcrossItems(array $items, float $discountTotal): array {
        $itemCount = count($items);
        if ($itemCount === 0) {
            return [];
        }

        $normalizedDiscount = round(max(0, $discountTotal), 2);
        if ($normalizedDiscount <= 0) {
            return array_fill(0, $itemCount, 0.0);
        }

        $grossTotals = [];
        $grossSum = 0.0;
        foreach ($items as $item) {
            $lineGross = round((float)($item['price'] ?? 0) * max(1, (int)($item['quantity'] ?? 1)), 2);
            $grossTotals[] = $lineGross;
            $grossSum += $lineGross;
        }

        if ($grossSum <= 0) {
            return array_fill(0, $itemCount, 0.0);
        }

        $allocations = array_fill(0, $itemCount, 0.0);
        $allocated = 0.0;
        $lastIndex = $itemCount - 1;

        foreach ($grossTotals as $index => $grossLine) {
            if ($index === $lastIndex) {
                $allocations[$index] = round(min(max(0, $normalizedDiscount - $allocated), $grossLine), 2);
                continue;
            }

            $share = round(($normalizedDiscount * $grossLine) / $grossSum, 2);
            $share = min($share, $grossLine);
            $allocations[$index] = $share;
            $allocated += $share;
        }

        return $allocations;
    }

    private function translatePaymentMethod(string $method): string {
        return $this->resolveSriPaymentMethod($method)['label'];
    }

    private function resolveSriPaymentMethod(string $method): array {
        $value = strtolower(trim($method));
        if ($value === 'credit' || $value === 'card' || $value === 'credit_card') {
            return [
                'code' => '19',
                'label' => 'Tarjeta de credito',
            ];
        }
        if ($value === 'transfer' || $value === 'bank_transfer') {
            return [
                'code' => '20',
                'label' => 'Otros con utilizacion del sistema financiero',
            ];
        }
        if ($value === 'cash' || $value === 'cod') {
            return [
                'code' => '01',
                'label' => 'Sin utilizacion del sistema financiero',
            ];
        }

        if (preg_match('/^\d{2}$/', $method) === 1) {
            return [
                'code' => $method,
                'label' => $method,
            ];
        }

        return [
            'code' => '20',
            'label' => $method !== '' ? $method : 'Otros con utilizacion del sistema financiero',
        ];
    }

    private function hasMeaningfulDiscountValue($value) {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return abs((float)$value) > 0;
        }
        if (is_array($value)) {
            return count($value) > 0;
        }
        return true;
    }

    private function rejectUnexpectedDiscountFields(array $data) {
        $unexpected = [];
        foreach ($this->forbiddenDiscountPayloadKeys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            if ($this->hasMeaningfulDiscountValue($data[$key])) {
                $unexpected[] = $key;
            }
        }
        if (count($unexpected) === 0) {
            return false;
        }

        Response::error(
            'Descuento rechazado. Solo se aceptan descuentos registrados y trazables. Campos no permitidos: ' . implode(', ', $unexpected),
            400,
            'ORDER_DISCOUNT_UNREGISTERED'
        );
        return true;
    }

    private function hasMeaningfulMoneyValue($value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        if (is_numeric($value)) {
            return abs((float)$value) > 0.00001;
        }
        if (is_array($value)) {
            return count($value) > 0;
        }
        return trim((string)$value) !== '';
    }

    private function getRequestChannel(array $data): string
    {
        $paymentDetails = $data['payment_details'] ?? null;
        if (is_string($paymentDetails)) {
            $decoded = json_decode($paymentDetails, true);
            $paymentDetails = is_array($decoded) ? $decoded : null;
        }

        return strtolower(trim((string)($paymentDetails['channel'] ?? '')));
    }

    private function isTrustedLocalPosRequest(array $data, ?array $user = null): bool
    {
        $role = strtolower(trim((string)($user['role'] ?? '')));
        if ($role !== 'admin') {
            return false;
        }

        return $this->getRequestChannel($data) === 'local_pos';
    }

    private function resolveInitialOrderStatus(array $data, ?array $user = null): string
    {
        if ($this->isTrustedLocalPosRequest($data, $user)) {
            return 'completed';
        }

        return 'pending';
    }

    private function pricingTamperLockMinutes(): int
    {
        $configured = (int)($_ENV['AUTH_PRICING_TAMPER_LOCK_MINUTES'] ?? 0);
        if ($configured > 0) {
            return max(15, $configured);
        }

        $baseLoginLock = (int)($_ENV['AUTH_LOGIN_LOCK_MINUTES'] ?? 15);
        return max(60, $baseLoginLock * 4);
    }

    private function getClientIpAddress(): ?string
    {
        if (function_exists('get_client_ip')) {
            return \get_client_ip();
        }

        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($ip) || trim($ip) === '') {
            return null;
        }

        $first = explode(',', $ip)[0] ?? null;
        return $first ? trim($first) : null;
    }

    private function blockUserForPricingTamper(?array $user, string $reasonCode, array $unexpectedFields): bool
    {
        $userId = trim((string)($user['sub'] ?? ''));
        if ($userId === '') {
            return false;
        }

        $lockedUntil = date('Y-m-d H:i:s', time() + ($this->pricingTamperLockMinutes() * 60));
        $this->userRepository->setLoginFailureState($userId, 999, $lockedUntil);
        $this->userRepository->clearActiveTokenId($userId);

        try {
            $this->authSecurityRepository->recordEvent(
                'order_pricing_tamper',
                'blocked',
                $userId,
                isset($user['email']) ? (string)$user['email'] : null,
                $this->getClientIpAddress(),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                [
                    'reason_code' => $reasonCode,
                    'blocked_until' => $lockedUntil,
                    'fields' => array_values($unexpectedFields),
                    'path' => $_SERVER['REQUEST_URI'] ?? null,
                ]
            );
        } catch (\Throwable $eventError) {
            error_log('[ORDER_PRICING_TAMPER_EVENT_FAILED] ' . $eventError->getMessage());
        }

        return true;
    }

    private function respondToPricingTamper(?array $user, array $unexpectedFields, string $message, string $code): bool
    {
        $blocked = $this->blockUserForPricingTamper($user, $code, $unexpectedFields);
        $finalMessage = $message;
        if ($blocked) {
            $finalMessage .= ' Detectamos manipulación del pedido y bloqueamos temporalmente tu cuenta por seguridad.';
        }

        Response::error($finalMessage, 403, $code);
        return true;
    }

    private function rejectUnexpectedMoneyFields(array $data, ?array $user = null): bool
    {
        if ($this->isTrustedLocalPosRequest($data, $user)) {
            return false;
        }

        $unexpected = [];
        foreach ($this->forbiddenMoneyPayloadKeys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            if ($this->hasMeaningfulMoneyValue($data[$key])) {
                $unexpected[] = $key;
            }
        }

        if (count($unexpected) === 0) {
            return false;
        }

        return $this->respondToPricingTamper(
            $user,
            $unexpected,
            'Montos rechazados. El servidor calcula precios, IVA, envío y descuentos. Campos no permitidos: ' . implode(', ', $unexpected),
            'ORDER_PRICING_FIELDS_FORBIDDEN'
        );
    }

    private function rejectUnexpectedItemPricingFields(array $data, ?array $user = null): bool
    {
        if ($this->isTrustedLocalPosRequest($data, $user)) {
            return false;
        }

        $items = $data['items'] ?? null;
        if (!is_array($items)) {
            return false;
        }

        $unexpected = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ($this->forbiddenItemPricingKeys as $key) {
                if (!array_key_exists($key, $item)) {
                    continue;
                }
                if ($this->hasMeaningfulMoneyValue($item[$key])) {
                    $unexpected[] = "items[$index].$key";
                }
            }
        }

        if (count($unexpected) === 0) {
            return false;
        }

        return $this->respondToPricingTamper(
            $user,
            $unexpected,
            'Líneas de pedido rechazadas. El cliente no puede definir montos por producto. Campos no permitidos: ' . implode(', ', $unexpected),
            'ORDER_ITEM_PRICING_FIELDS_FORBIDDEN'
        );
    }

    private function normalizeDiscountCodeValue($value) {
        if ($value === null) return null;
        $normalized = strtoupper(trim((string)$value));
        if ($normalized === '') return null;
        return preg_replace('/\s+/', '', $normalized);
    }

    private function extractDiscountCode(array $data, ?bool &$hasError = null) {
        $hasError = false;
        $couponCode = $this->normalizeDiscountCodeValue($data['coupon_code'] ?? null);
        $discountCode = $this->normalizeDiscountCodeValue($data['discount_code'] ?? null);

        if ($couponCode && $discountCode && $couponCode !== $discountCode) {
            Response::error(
                'Se enviaron dos códigos de descuento distintos. Usa solo uno.',
                400,
                'ORDER_DISCOUNT_CODE_CONFLICT'
            );
            $hasError = true;
            return null;
        }

        return $couponCode ?: $discountCode;
    }

    private function isStoreSalesEnabled() {
        $settings = new SettingsRepository();
        $enabledRaw = $settings->get('store_sales_enabled');
        $messageRaw = $settings->get('store_sales_message');
        $salesEnabled = $enabledRaw === null ? true : in_array(strtolower(trim((string)$enabledRaw)), ['1', 'true', 'yes', 'y', 'on'], true);
        $message = trim((string)($messageRaw ?? 'Tienda temporalmente en mantenimiento. Intenta más tarde.'));
        if ($message === '') {
            $message = 'Tienda temporalmente en mantenimiento. Intenta más tarde.';
        }
        return ['enabled' => $salesEnabled, 'message' => $message];
    }

    private function enforceSalesEnabled() {
        $status = $this->isStoreSalesEnabled();
        if ($status['enabled']) {
            return true;
        }
        Response::error($status['message'], 503, 'STORE_SALES_DISABLED');
        return false;
    }

    public function quote() {
        try {
            $user = $this->authenticateOptional();
            $isAdmin = (($user['role'] ?? 'customer') === 'admin');
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            if ($this->rejectUnexpectedDiscountFields($data)) {
                return;
            }
            if ($this->rejectUnexpectedMoneyFields($data, $user) || $this->rejectUnexpectedItemPricingFields($data, $user)) {
                return;
            }
            if (!$isAdmin && !$this->enforceSalesEnabled()) {
                return;
            }
            $hasDiscountCodeError = false;
            $discountCode = $this->extractDiscountCode($data, $hasDiscountCodeError);
            if ($hasDiscountCodeError) {
                return;
            }
            if (!isset($data['items'])) {
                throw new \Exception("Items required");
            }
            $quote = $this->orderRepository->calculateQuote(
                $data['items'],
                $data['delivery_method'] ?? 'delivery',
                $discountCode,
                'quote',
                null,
                null
            );
            Response::json($quote);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'ORDER_QUOTE_FAILED');
        }
    }

    public function store() {
        $user = $this->authenticate();
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            if ($this->rejectUnexpectedDiscountFields($data)) {
                return;
            }
            if ($this->rejectUnexpectedMoneyFields($data, $user) || $this->rejectUnexpectedItemPricingFields($data, $user)) {
                return;
            }
            if (!$this->enforceSalesEnabled()) {
                return;
            }
            $hasDiscountCodeError = false;
            $discountCode = $this->extractDiscountCode($data, $hasDiscountCodeError);
            if ($hasDiscountCodeError) {
                return;
            }
            $data['coupon_code'] = $discountCode;
            if (($user['role'] ?? 'customer') === 'guest' || empty($user['sub'])) {
                Response::error('Debes iniciar sesión para comprar', 403, 'GUEST_PURCHASE_DISABLED');
                return;
            }
            $data['user_id'] = $this->resolveCustomerUserId($data, $user);
            $data['status'] = $this->resolveInitialOrderStatus($data, $user);

            // Always generate a server-side order id to avoid collisions/tampering.
            $data['id'] = 'ORD-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));

            $baseUrl = TenantContext::appUrl() ?? ($_ENV['APP_URL'] ?? null);
            if (!$baseUrl) {
                $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $baseUrl = $proto . '://' . $host;
            }
            $order = $this->orderRepository->create($data, $baseUrl);

            Response::json($order, 201);
            $this->dispatchFacturadorInvoiceAfterResponse($order ?: []);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'ORDER_CREATE_FAILED');
        }
    }
}

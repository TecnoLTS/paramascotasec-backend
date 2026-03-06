<?php

namespace App\Controllers;

use App\Repositories\OrderRepository;
use App\Repositories\SettingsRepository;
use App\Core\Response;
use App\Core\Auth;
use App\Core\TenantContext;
use App\Controllers\SriController;

class OrderController {
    private $orderRepository;
    private $forbiddenDiscountPayloadKeys = [
        'discount',
        'discounts',
        'coupon',
        'promo',
        'promo_code',
        'promotion',
        'promotions'
    ];

    public function __construct() {
        $this->orderRepository = new OrderRepository();
    }

    private function authenticate() {
        return Auth::requireUser();
    }

    private function authenticateOptional() {
        return Auth::optionalUser();
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

        $allowedStatuses = ['pending', 'processing', 'shipped', 'delivered', 'canceled'];
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
                Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
                return;
            }

            $updated = $this->orderRepository->updateStatus($id, $newStatus);
            Response::json($updated);
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
            if (empty($order['invoice_html'])) {
                $baseUrl = TenantContext::appUrl() ?? ($_ENV['APP_URL'] ?? null);
                if (!$baseUrl) {
                    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $baseUrl = $proto . '://' . $host;
                }
                $invoiceHtml = $this->orderRepository->ensureInvoiceForOrder($order, $baseUrl);
                if (!$invoiceHtml) {
                    Response::error('Factura no disponible', 404, 'ORDER_INVOICE_UNAVAILABLE');
                    return;
                }
                $order['invoice_html'] = $invoiceHtml;
            } else {
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
            }
            header('Content-Type: text/html; charset=utf-8');
            echo $order['invoice_html'];
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'ORDER_INVOICE_FAILED');
        }
    }

    private function decodeAddress($value) {
        if (!$value) return [];
        if (is_array($value)) return $value;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
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
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            if ($this->rejectUnexpectedDiscountFields($data)) {
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
            $data['user_id'] = $user['sub'];

            // Always generate a server-side order id to avoid collisions/tampering.
            $data['id'] = 'ORD-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));

            $baseUrl = TenantContext::appUrl() ?? ($_ENV['APP_URL'] ?? null);
            if (!$baseUrl) {
                $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $baseUrl = $proto . '://' . $host;
            }
            $order = $this->orderRepository->create($data, $baseUrl);
            
            // Generar XML del SRI automáticamente
            if ($order && $order['id']) {
                try {
                    error_log("[SRI] Iniciando generación de XML para orden {$order['id']}");
                    
                    $sriController = new SriController();
                    $reflection = new \ReflectionClass($sriController);
                    
                    // Preparar datos del XML
                    $prepareMethod = $reflection->getMethod('prepareOrderDataForSri');
                    $prepareMethod->setAccessible(true);
                    $xmlData = $prepareMethod->invoke($sriController, $order);
                    error_log("[SRI] Datos preparados, secuencial: {$xmlData['secuencial']}");
                    
                    // Obtener el generador de XML
                    $xmlGeneratorProp = $reflection->getProperty('xmlGenerator');
                    $xmlGeneratorProp->setAccessible(true);
                    $xmlGenerator = $xmlGeneratorProp->getValue($sriController);
                    
                    // Generar XML
                    $xml = $xmlGenerator->generateInvoiceXml($xmlData);
                    error_log("[SRI] XML generado, tamaño: " . strlen($xml) . " bytes");
                    
                    // Guardar XML
                    $xmlDir = __DIR__ . '/../../storage/sri/xml';
                    if (!is_dir($xmlDir)) {
                        mkdir($xmlDir, 0775, true);
                        error_log("[SRI] Directorio creado: {$xmlDir}");
                    }
                    
                    $secuencial = str_pad($xmlData['secuencial'], 9, '0', STR_PAD_LEFT);
                    $timestamp = date('YmdHis');
                    $filename = "factura_{$secuencial}_{$timestamp}.xml";
                    $filepath = $xmlDir . '/' . $filename;
                    
                    $bytesWritten = file_put_contents($filepath, $xml);
                    
                    if ($bytesWritten !== false) {
                        error_log("[SRI] ✅ XML guardado exitosamente: {$filename} ({$bytesWritten} bytes) en {$filepath}");
                        // Verificar que el archivo existe
                        if (file_exists($filepath)) {
                            error_log("[SRI] ✅ Archivo verificado existe: {$filepath}");
                        } else {
                            error_log("[SRI] ⚠️ ADVERTENCIA: file_put_contents retornó {$bytesWritten} pero el archivo NO existe!");
                        }
                    } else {
                        error_log("[SRI] ❌ ERROR: file_put_contents retornó FALSE");
                    }
                    
                } catch (\Exception $e) {
                    // No fallar la orden si el XML falla
                    error_log("[SRI] ❌ Excepción generando XML para orden {$order['id']}: " . $e->getMessage());
                    error_log("[SRI] Stack trace: " . $e->getTraceAsString());
                }
            }
            
            Response::json($order, 201);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'ORDER_CREATE_FAILED');
        }
    }
}

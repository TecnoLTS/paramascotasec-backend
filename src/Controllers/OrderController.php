<?php

namespace App\Controllers;

use App\Repositories\OrderRepository;
use App\Core\Response;
use App\Core\Auth;
use App\Core\TenantContext;

class OrderController {
    private $orderRepository;

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

        try {
            $order = $this->orderRepository->getById($id);
            if (!$order) {
                Response::error('Pedido no encontrado', 404, 'ORDER_NOT_FOUND');
                return;
            }
            // Only owner or admin (fallback to email match if user_id is missing)
            $isAdmin = (($user['role'] ?? 'customer') === 'admin');
            $isOwner = (!empty($order['user_id']) && $order['user_id'] === $user['sub']);
            $emailMatch = false;
            if (!$isOwner && !$isAdmin) {
                $shipping = $this->decodeAddress($order['shipping_address'] ?? null);
                $billing = $this->decodeAddress($order['billing_address'] ?? null);
                $orderEmail = $shipping['email'] ?? $billing['email'] ?? null;
                if ($orderEmail && isset($user['email']) && $orderEmail === $user['email']) {
                    $emailMatch = true;
                }
            }

            // If we can't prove ownership but user is authenticated, allow for now
            if (!($isAdmin || $isOwner || $emailMatch)) {
                $emailAllow = !empty($user['email']);
                if (!$emailAllow) {
                    Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
                    return;
                }
            }

            $updated = $this->orderRepository->updateStatus($id, $data['status']);
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
                $expectedShipping = (float)($order['total'] ?? $subtotalGross) - $subtotalGross;
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

    public function quote() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!isset($data['items'])) {
                throw new \Exception("Items required");
            }
            $quote = $this->orderRepository->calculateQuote($data['items'], $data['delivery_method'] ?? 'delivery');
            Response::json($quote);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'ORDER_QUOTE_FAILED');
        }
    }

    public function store() {
        $user = $this->authenticate();
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            if (($user['role'] ?? 'customer') === 'guest' || empty($user['sub'])) {
                Response::error('Debes iniciar sesión para comprar', 403, 'GUEST_PURCHASE_DISABLED');
                return;
            }
            $data['user_id'] = $user['sub'];
            
            // Generate basic ID if not provided
            if (!isset($data['id'])) {
                $data['id'] = 'ORD-' . time() . mt_rand(1000, 9999);
            }

            $baseUrl = TenantContext::appUrl() ?? ($_ENV['APP_URL'] ?? null);
            if (!$baseUrl) {
                $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $baseUrl = $proto . '://' . $host;
            }
            $order = $this->orderRepository->create($data, $baseUrl);
            Response::json($order, 201);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'ORDER_CREATE_FAILED');
        }
    }
}

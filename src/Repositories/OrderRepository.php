<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use PDO;

class OrderRepository {
    private $db;
    private $discountRepository;
    private $taxRateCache = null;
    private $shippingRateCache = null;
    private $shippingTaxRateCache = null;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->discountRepository = new DiscountRepository($this->db);
    }

    public function getAll() {
        // Lightweight list for admin table (full detail is fetched via getById).
        $stmt = $this->db->prepare('
            SELECT
                o.id,
                o.user_id,
                o.total,
                o.status,
                o.created_at,
                u.name as user_name,
                u.email as user_email
            FROM "Order" o 
            LEFT JOIN "User" u ON o.user_id = u.id AND u.tenant_id = o.tenant_id
            WHERE o.tenant_id = :tenant_id
            ORDER BY o.created_at DESC
        ');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll();
    }

    public function getByUserId($userId) {
        $stmt = $this->db->prepare('
            SELECT o.*, u.name as user_name, u.email as user_email
            FROM "Order" o
            LEFT JOIN "User" u ON o.user_id = u.id AND u.tenant_id = o.tenant_id
            WHERE o.user_id = :user_id AND o.tenant_id = :tenant_id
            ORDER BY o.created_at DESC
        ');
        $stmt->execute([
            'user_id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        $orders = $stmt->fetchAll();

        if (!$orders) {
            return [];
        }

        $orderIds = array_map(fn($order) => $order['id'], $orders);
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmtItems = $this->db->prepare('
            SELECT oi.*
            FROM "OrderItem" oi
            JOIN "Order" o ON oi.order_id = o.id
            WHERE oi.order_id IN (' . $placeholders . ')
              AND o.tenant_id = ?
        ');
        $params = $orderIds;
        $params[] = $this->getTenantId();
        $stmtItems->execute($params);
        $items = $stmtItems->fetchAll();

        $itemsByOrder = [];
        foreach ($items as $item) {
            $itemsByOrder[$item['order_id']][] = $item;
        }

        foreach ($orders as &$order) {
            $order['items'] = $itemsByOrder[$order['id']] ?? [];
            $order = $this->addTaxBreakdown($order);
        }
        unset($order);

        return $orders;
    }

    public function getById($id) {
        $stmt = $this->db->prepare('
            SELECT o.*, u.name as user_name, u.email as user_email
            FROM "Order" o
            LEFT JOIN "User" u ON o.user_id = u.id AND u.tenant_id = o.tenant_id
            WHERE o.id = :id AND o.tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
        $order = $stmt->fetch();

        if ($order) {
            $stmtItems = $this->db->prepare('
                SELECT oi.*
                FROM "OrderItem" oi
                JOIN "Order" o ON oi.order_id = o.id
                WHERE oi.order_id = :order_id
                  AND o.tenant_id = :tenant_id
            ');
            $stmtItems->execute([
                'order_id' => $id,
                'tenant_id' => $this->getTenantId()
            ]);
            $order['items'] = $stmtItems->fetchAll();
            $order = $this->addTaxBreakdown($order);
        }

        return $order;
    }

    public function updateStatus($id, $status) {
        $tenantId = $this->getTenantId();
        $this->db->beginTransaction();
        try {
            $stmtCurrent = $this->db->prepare('SELECT status FROM "Order" WHERE id = :id AND tenant_id = :tenant_id FOR UPDATE');
            $stmtCurrent->execute([
                'id' => $id,
                'tenant_id' => $tenantId
            ]);
            $current = $stmtCurrent->fetch();
            if (!$current) {
                $this->db->rollBack();
                return null;
            }

            $currentStatus = strtolower(trim((string)($current['status'] ?? 'pending')));
            $nextStatus = strtolower(trim((string)$status));
            $currentActive = $this->orderAffectsInventory($currentStatus);
            $nextActive = $this->orderAffectsInventory($nextStatus);

            if ($currentActive !== $nextActive) {
                $stmtItems = $this->db->prepare('SELECT product_id, quantity FROM "OrderItem" WHERE order_id = :order_id');
                $stmtItems->execute(['order_id' => $id]);
                $items = $stmtItems->fetchAll();

                if ($currentActive && !$nextActive) {
                    $stmtRestore = $this->db->prepare('UPDATE "Product" SET quantity = quantity + :qty, sold = GREATEST(0, sold - :qty) WHERE id = :id AND tenant_id = :tenant_id');
                    foreach ($items as $item) {
                        $qty = max(0, (int)($item['quantity'] ?? 0));
                        if ($qty <= 0) {
                            continue;
                        }
                        $stmtRestore->execute([
                            'qty' => $qty,
                            'id' => $item['product_id'],
                            'tenant_id' => $tenantId
                        ]);
                    }
                } else {
                    $stmtConsume = $this->db->prepare('UPDATE "Product" SET quantity = quantity - :qty, sold = sold + :qty WHERE id = :id AND tenant_id = :tenant_id AND quantity >= :qty');
                    foreach ($items as $item) {
                        $qty = max(0, (int)($item['quantity'] ?? 0));
                        if ($qty <= 0) {
                            continue;
                        }
                        $stmtConsume->execute([
                            'qty' => $qty,
                            'id' => $item['product_id'],
                            'tenant_id' => $tenantId
                        ]);
                        if ($stmtConsume->rowCount() !== 1) {
                            throw new \Exception('Stock insuficiente para reactivar el pedido');
                        }
                    }
                }
            }

            $stmt = $this->db->prepare('UPDATE "Order" SET status = :status WHERE id = :id AND tenant_id = :tenant_id');
            $stmt->execute([
                'id' => $id,
                'tenant_id' => $tenantId,
                'status' => $nextStatus
            ]);
            $this->db->commit();
            return $this->getById($id);
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function ensureInvoiceForOrder($order, $baseUrl = null, $force = false) {
        if (!$order) return null;
        if (!empty($order['invoice_html']) && !$force) {
            return $order['invoice_html'];
        }

        $orderId = $order['id'];
        $invoiceNumber = ($order['invoice_number'] ?? null) ?: $this->buildInvoiceNumber($orderId);

        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += (float)$item['price'] * (int)$item['quantity'];
        }
        $orderTotal = (float)($order['total'] ?? $subtotal);
        $shippingFromOrder = isset($order['shipping']) ? (float)$order['shipping'] : ($orderTotal - $subtotal);
        if ($shippingFromOrder < 0) {
            $shippingFromOrder = 0;
        }

        $quote = [
            'items' => array_map(function ($item) {
                return [
                    'product_name' => $item['product_name'] ?? null,
                    'price' => (float)($item['price'] ?? 0),
                    'quantity' => (int)($item['quantity'] ?? 1),
                    'product_image' => $item['product_image'] ?? null
                ];
            }, $items),
            'subtotal' => $subtotal,
            'shipping' => $shippingFromOrder,
            'shipping_base' => isset($order['shipping_base']) ? (float)$order['shipping_base'] : null,
            'shipping_tax_rate' => isset($order['shipping_tax_rate']) ? (float)$order['shipping_tax_rate'] : null,
            'shipping_tax_amount' => isset($order['shipping_tax_amount']) ? (float)$order['shipping_tax_amount'] : null,
            'total' => $orderTotal,
            'vat_rate' => isset($order['vat_rate']) ? (float)$order['vat_rate'] : $this->getTaxRate(),
            'vat_subtotal' => isset($order['vat_subtotal']) ? (float)$order['vat_subtotal'] : null,
            'vat_amount' => isset($order['vat_amount']) ? (float)$order['vat_amount'] : null,
            'discount_code' => $order['discount_code'] ?? null,
            'discount_total' => isset($order['discount_total']) ? (float)$order['discount_total'] : 0
        ];

        $billing = $this->decodeJsonField($order['billing_address'] ?? null);
        if (!$billing) {
            $billing = $this->getUserDefaultBilling($order['user_id'] ?? null);
        }

        $shipping = $this->decodeJsonField($order['shipping_address'] ?? null);
        $customerName = trim(($shipping['firstName'] ?? '') . ' ' . ($shipping['lastName'] ?? ''));
        if (!$customerName) {
            $customerName = trim(($billing['firstName'] ?? '') . ' ' . ($billing['lastName'] ?? ''));
        }
        if (!$customerName) {
            $customerName = $order['user_name'] ?? null;
        }
        if (!$customerName) {
            $stmtUser = $this->db->prepare('SELECT name FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
            $stmtUser->execute([
                'id' => $order['user_id'] ?? null,
                'tenant_id' => $this->getTenantId()
            ]);
            $userRow = $stmtUser->fetch();
            if ($userRow && !empty($userRow['name'])) {
                $customerName = $userRow['name'];
            }
        }

        $customerEmail = $shipping['email'] ?? $billing['email'] ?? null;
        $customerPhone = $shipping['phone'] ?? $billing['phone'] ?? null;

        $paymentLabel = $this->translatePaymentMethod($order['payment_method'] ?? null);

        $invoiceData = [
            'customer' => [
                'name' => $customerName ?: null,
                'email' => $customerEmail,
                'phone' => $customerPhone
            ],
            'billing_address' => $billing,
            'shipping_address' => [
                'line1' => 'Local Para Mascotas EC',
                'line2' => 'Retiro en tienda'
            ],
            'company' => $billing['company'] ?? null,
            'payment_method' => $paymentLabel,
            'items' => $quote['items']
        ];

        $invoiceHtml = $this->renderInvoiceHtml($invoiceNumber, [
            'billing_address' => $billing,
            'shipping_address' => $shipping,
            'payment_method' => $order['payment_method'] ?? null
        ], $quote, $invoiceData, $baseUrl);

        $stmt = $this->db->prepare('UPDATE "Order" SET invoice_number = :invoice_number, invoice_html = :invoice_html, invoice_created_at = NOW(), invoice_data = :invoice_data WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $orderId,
            'tenant_id' => $this->getTenantId(),
            'invoice_number' => $invoiceNumber,
            'invoice_html' => $invoiceHtml,
            'invoice_data' => json_encode($invoiceData)
        ]);

        return $invoiceHtml;
    }

    public function calculateQuote($items, $deliveryMethod = 'delivery', $discountCode = null, $context = 'quote', $orderId = null, $userId = null) {
        if (!is_array($items) || count($items) === 0) {
            throw new \Exception('Items required');
        }
        $itemsGrossSubtotal = 0;
        $itemsWithDetails = [];
        $taxRate = $this->getTaxRate();
        $taxMultiplier = 1 + ($taxRate / 100);

        foreach ($items as $item) {
            $productId = trim((string)($item['product_id'] ?? ''));
            $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 0;
            if ($productId === '' || $quantity <= 0) {
                throw new \Exception('Item inválido');
            }

            $stmt = $this->db->prepare('
                SELECT p.id, p.legacy_id, p.price, p.name, p.quantity, p.attributes,
                       (SELECT url FROM "Image" WHERE product_id = p.id ORDER BY id LIMIT 1) as image
                FROM "Product" p 
                WHERE (p.id = :id OR p.legacy_id = :id) AND p.tenant_id = :tenant_id
            ');
            $stmt->execute([
                'id' => $productId,
                'tenant_id' => $this->getTenantId()
            ]);
            $product = $stmt->fetch();

            if (!$product) {
                throw new \Exception('Producto no encontrado: ' . $productId);
            }

            $availableQty = (int)($product['quantity'] ?? 0);
            if ($availableQty < $quantity) {
                throw new \Exception('Stock insuficiente para: ' . $product['name']);
            }

            $attributes = [];
            if (!empty($product['attributes'])) {
                $decoded = json_decode((string)$product['attributes'], true);
                if (is_array($decoded)) {
                    $attributes = $decoded;
                }
            }
            $expirationDateRaw = trim((string)($attributes['expirationDate'] ?? $attributes['expiryDate'] ?? ''));
            if ($expirationDateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expirationDateRaw) === 1) {
                $expirationDate = \DateTimeImmutable::createFromFormat('Y-m-d', $expirationDateRaw);
                if ($expirationDate instanceof \DateTimeImmutable) {
                    $today = new \DateTimeImmutable('today');
                    if ($expirationDate < $today) {
                        throw new \Exception('Producto vencido: ' . $product['name']);
                    }
                }
            }

            $priceWithTax = round(floatval($product['price']) * $taxMultiplier, 2);
            $lineTotal = $priceWithTax * $quantity;
            $itemsGrossSubtotal += $lineTotal;

            $itemsWithDetails[] = [
                'product_id' => $product['id'],
                'product_name' => $product['name'],
                'product_image' => $item['product_image'] ?? $product['image'],
                'quantity' => $quantity,
                'price' => $priceWithTax,
                'total' => $lineTotal
            ];
        }

        $discountResult = ($context === 'order')
            ? $this->discountRepository->reserveForOrder($discountCode, $itemsGrossSubtotal, (string)$orderId, $userId)
            : $this->discountRepository->evaluateForQuote($discountCode, $itemsGrossSubtotal, $userId);
        $discountTotal = max(0, (float)($discountResult['discount_total'] ?? 0));
        $itemsNetSubtotal = max(0, $itemsGrossSubtotal - $discountTotal);

        $shippingBase = $this->getShippingRate($deliveryMethod);
        $shippingTaxRate = $this->getShippingTaxRate();
        $shippingTaxAmount = $shippingTaxRate > 0 ? ($shippingBase * ($shippingTaxRate / 100)) : 0;
        $shippingTotal = $shippingBase + $shippingTaxAmount;
        $total = $itemsNetSubtotal + $shippingTotal;
        $vatSubtotal = $taxMultiplier > 0 ? ($itemsNetSubtotal / $taxMultiplier) : $itemsNetSubtotal;
        $vatAmount = $itemsNetSubtotal - $vatSubtotal;

        return [
            'subtotal' => round($itemsNetSubtotal, 2),
            'items_subtotal_before_discount' => round($itemsGrossSubtotal, 2),
            'vat_rate' => round($taxRate, 2),
            'vat_subtotal' => round($vatSubtotal, 2),
            'vat_amount' => round($vatAmount, 2),
            'shipping' => round($shippingTotal, 2),
            'shipping_base' => round($shippingBase, 2),
            'shipping_tax_rate' => round($shippingTaxRate, 2),
            'shipping_tax_amount' => round($shippingTaxAmount, 2),
            'discount_code' => $discountResult['discount_code'] ?? null,
            'discount_total' => round($discountTotal, 2),
            'discounts_applied' => $discountResult['discounts_applied'] ?? [],
            'discount_rejections' => $discountResult['discount_rejections'] ?? [],
            'total' => round($total, 2),
            'items' => $itemsWithDetails
        ];
    }

    private function getShippingRate($deliveryMethod) {
        if ($this->shippingRateCache === null) {
            $settings = new \App\Repositories\SettingsRepository();
            $delivery = $settings->get('shipping_delivery');
            $pickup = $settings->get('shipping_pickup');
            $deliveryValue = is_numeric($delivery) ? floatval($delivery) : 5.0;
            $pickupValue = is_numeric($pickup) ? floatval($pickup) : 0.0;
            if ($delivery === null) {
                $settings->set('shipping_delivery', (string)$deliveryValue);
            }
            if ($pickup === null) {
                $settings->set('shipping_pickup', (string)$pickupValue);
            }
            $this->shippingRateCache = [
                'delivery' => $deliveryValue,
                'pickup' => $pickupValue
            ];
        }
        $method = $deliveryMethod === 'pickup' ? 'pickup' : 'delivery';
        return (float)($this->shippingRateCache[$method] ?? 0);
    }

    private function getShippingTaxRate() {
        if ($this->shippingTaxRateCache !== null) {
            return $this->shippingTaxRateCache;
        }
        $settings = new \App\Repositories\SettingsRepository();
        $value = $settings->get('shipping_tax_rate');
        $rate = is_numeric($value) ? floatval($value) : $this->getTaxRate();
        if ($value === null) {
            $settings->set('shipping_tax_rate', (string)$rate);
        }
        $this->shippingTaxRateCache = $rate;
        return $rate;
    }

    private function getTaxRate() {
        if ($this->taxRateCache !== null) {
            return $this->taxRateCache;
        }
        $settings = new \App\Repositories\SettingsRepository();
        $value = $settings->get('vat_rate');
        $rate = is_numeric($value) ? floatval($value) : 0;
        $this->taxRateCache = $rate;
        return $rate;
    }

    public function create($data, $baseUrl = null) {
        $this->db->beginTransaction();
        try {
            $paymentDetails = $data['payment_details'] ?? null;
            if (is_string($paymentDetails)) {
                $decoded = json_decode($paymentDetails, true);
                $paymentDetails = is_array($decoded) ? $decoded : null;
            }
            $channel = strtolower(trim((string)($paymentDetails['channel'] ?? '')));
            if ($channel === 'local_pos') {
                $posRepository = new PosRepository();
                $activeShift = $posRepository->getActiveShift();
                if (!$activeShift) {
                    throw new \Exception('No hay una caja abierta para registrar ventas en local.');
                }
                $requestedShiftId = trim((string)($paymentDetails['shift_id'] ?? ''));
                if ($requestedShiftId !== '' && $requestedShiftId !== (string)$activeShift['id']) {
                    throw new \Exception('La venta local no corresponde al turno de caja activo.');
                }
                $paymentDetails['shift_id'] = (string)$activeShift['id'];
                $data['payment_details'] = $paymentDetails;
            }

            // Inteligencia de Negocio: El Backend recalcula y valida TODO.
            $quote = $this->calculateQuote(
                $data['items'],
                $data['delivery_method'] ?? 'delivery',
                $data['coupon_code'] ?? ($data['discount_code'] ?? null),
                'order',
                $data['id'],
                $data['user_id'] ?? null
            );
            $orderStatus = strtolower(trim((string)($data['status'] ?? 'pending')));
            
            $stmt = $this->db->prepare('INSERT INTO "Order" ("id", "tenant_id", "user_id", "total", "status", "created_at", "shipping_address", "billing_address", "payment_method", "payment_details", "items_subtotal", "vat_subtotal", "vat_rate", "vat_amount", "shipping", "shipping_base", "shipping_tax_rate", "shipping_tax_amount", "discount_code", "discount_total", "discount_snapshot", "order_notes") VALUES (:id, :tenant_id, :user_id, :total, :status, NOW(), :shipping_address, :billing_address, :payment_method, :payment_details, :items_subtotal, :vat_subtotal, :vat_rate, :vat_amount, :shipping, :shipping_base, :shipping_tax_rate, :shipping_tax_amount, :discount_code, :discount_total, :discount_snapshot, :order_notes)');
            
            $stmt->execute([
                'id' => $data['id'],
                'tenant_id' => $this->getTenantId(),
                'user_id' => $data['user_id'],
                'total' => $quote['total'],
                'status' => $orderStatus,
                'shipping_address' => json_encode($data['shipping_address'] ?? null),
                'billing_address' => json_encode($data['billing_address'] ?? null),
                'payment_method' => $data['payment_method'] ?? null,
                'payment_details' => isset($data['payment_details']) ? json_encode($data['payment_details']) : null,
                'items_subtotal' => $quote['subtotal'],
                'vat_subtotal' => $quote['vat_subtotal'],
                'vat_rate' => $quote['vat_rate'],
                'vat_amount' => $quote['vat_amount'],
                'shipping' => $quote['shipping'],
                'shipping_base' => $quote['shipping_base'] ?? $quote['shipping'],
                'shipping_tax_rate' => $quote['shipping_tax_rate'] ?? 0,
                'shipping_tax_amount' => $quote['shipping_tax_amount'] ?? 0,
                'discount_code' => $quote['discount_code'] ?? null,
                'discount_total' => $quote['discount_total'] ?? 0,
                'discount_snapshot' => isset($quote['discounts_applied']) ? json_encode($quote['discounts_applied']) : null,
                'order_notes' => $data['order_notes'] ?? null
            ]);

            foreach ($quote['items'] as $item) {
                $stmtItem = $this->db->prepare('INSERT INTO "OrderItem" ("id", "order_id", "product_id", "product_name", "product_image", "quantity", "price") VALUES (:id, :order_id, :product_id, :product_name, :product_image, :quantity, :price)');
                
                $stmtItem->execute([
                    'id' => uniqid('item_'),
                    'order_id' => $data['id'],
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'product_image' => $item['product_image'] ?? null,
                    'quantity' => $item['quantity'],
                    'price' => $item['price']
                ]);

                if ($this->orderAffectsInventory($orderStatus)) {
                    // Pedido activo consume inventario y suma unidades vendidas.
                    $stmtUpdateStock = $this->db->prepare('UPDATE "Product" SET quantity = quantity - :qty, sold = sold + :qty WHERE id = :id AND tenant_id = :tenant_id AND quantity >= :qty');
                    $stmtUpdateStock->execute([
                        'qty' => $item['quantity'],
                        'id' => $item['product_id'],
                        'tenant_id' => $this->getTenantId()
                    ]);
                    if ($stmtUpdateStock->rowCount() !== 1) {
                        throw new \Exception('Stock insuficiente para completar el pedido');
                    }
                }
            }

            $invoiceData = $this->buildInvoiceData($data, $quote);
            $invoiceNumber = $this->buildInvoiceNumber($data['id']);
            $invoiceHtml = $this->renderInvoiceHtml($invoiceNumber, $data, $quote, $invoiceData, $baseUrl);

            $stmtInvoice = $this->db->prepare('UPDATE "Order" SET invoice_number = :invoice_number, invoice_html = :invoice_html, invoice_created_at = NOW(), invoice_data = :invoice_data WHERE id = :id AND tenant_id = :tenant_id');
            $stmtInvoice->execute([
                'id' => $data['id'],
                'tenant_id' => $this->getTenantId(),
                'invoice_number' => $invoiceNumber,
                'invoice_html' => $invoiceHtml,
                'invoice_data' => json_encode($invoiceData)
            ]);

            $this->db->commit();
            return $this->getById($data['id']);

        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function activeOrdersCondition($alias = 'o') {
        return "LOWER(COALESCE({$alias}.status, 'pending')) NOT IN ('canceled', 'cancelled')";
    }

    private function realizedSalesCondition($alias = 'o') {
        return "LOWER(COALESCE({$alias}.status, 'pending')) IN ('delivered', 'completed')";
    }

    private function orderAffectsInventory($status) {
        $normalized = strtolower(trim((string)$status));
        return !in_array($normalized, ['canceled', 'cancelled'], true);
    }

    private function netSalesSql($alias = 'o') {
        $totalMinusShipping = "(COALESCE({$alias}.total, 0) - COALESCE({$alias}.shipping, 0))";
        $vatRateExpr = "COALESCE({$alias}.vat_rate, 0)";
        $multiplierExpr = "(1 + ({$vatRateExpr} / 100.0))";

        return "COALESCE({$alias}.vat_subtotal, CASE
            WHEN {$vatRateExpr} > 0 THEN ({$totalMinusShipping} / NULLIF({$multiplierExpr}, 0))
            ELSE ({$totalMinusShipping} - COALESCE({$alias}.vat_amount, 0))
        END)";
    }

    private function vatAmountSql($alias = 'o') {
        $totalMinusShipping = "(COALESCE({$alias}.total, 0) - COALESCE({$alias}.shipping, 0))";
        $netExpr = $this->netSalesSql($alias);
        return "COALESCE({$alias}.vat_amount, ({$totalMinusShipping} - ({$netExpr})))";
    }

    // Stats methods for Dashboard
    public function getTotalSales() {
        $netExpr = $this->netSalesSql('o');
        $activeStatus = $this->activeOrdersCondition('o');
        $stmt = $this->db->prepare("
            SELECT SUM($netExpr) as total
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id AND $activeStatus
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $result = $stmt->fetch();
        return round((float)($result['total'] ?? 0), 2);
    }

    public function getSalesProgress() {
        $netExpr = $this->netSalesSql('o');
        $activeStatus = $this->activeOrdersCondition('o');
        $stmtThis = $this->db->prepare("
            SELECT SUM($netExpr)
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id
              AND $activeStatus
              AND o.created_at >= DATE_TRUNC('month', NOW())
        ");
        $stmtThis->execute(['tenant_id' => $this->getTenantId()]);
        $thisMonth = (float)($stmtThis->fetchColumn() ?: 0);

        $stmtLast = $this->db->prepare("
            SELECT SUM($netExpr)
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id
              AND $activeStatus
              AND o.created_at >= DATE_TRUNC('month', NOW() - INTERVAL '1 month')
              AND o.created_at < DATE_TRUNC('month', NOW())
        ");
        $stmtLast->execute(['tenant_id' => $this->getTenantId()]);
        $lastMonth = (float)($stmtLast->fetchColumn() ?: 0);

        $percentage = $lastMonth > 0
            ? (($thisMonth - $lastMonth) / $lastMonth) * 100
            : ($thisMonth > 0 ? 100 : 0);

        return [
            'current' => round($thisMonth, 2),
            'previous' => round($lastMonth, 2),
            'percentage' => round($percentage, 1)
        ];
    }

    public function getNewOrdersCount() {
        $stmt = $this->db->prepare('SELECT COUNT(*) as count FROM "Order" WHERE tenant_id = :tenant_id AND created_at >= CURRENT_DATE');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }

    public function getOrdersProgress() {
        $stmtToday = $this->db->prepare('SELECT COUNT(*) FROM "Order" WHERE tenant_id = :tenant_id AND created_at >= CURRENT_DATE');
        $stmtToday->execute(['tenant_id' => $this->getTenantId()]);
        $today = (int)($stmtToday->fetchColumn() ?: 0);

        $stmtYesterday = $this->db->prepare('SELECT COUNT(*) FROM "Order" WHERE tenant_id = :tenant_id AND created_at >= CURRENT_DATE - INTERVAL \'1 day\' AND created_at < CURRENT_DATE');
        $stmtYesterday->execute(['tenant_id' => $this->getTenantId()]);
        $yesterday = (int)($stmtYesterday->fetchColumn() ?: 0);

        $percentage = $yesterday > 0
            ? (($today - $yesterday) / $yesterday) * 100
            : ($today > 0 ? 100 : 0);

        return [
            'current' => $today,
            'previous' => $yesterday,
            'percentage' => round($percentage, 1)
        ];
    }

    public function getMonthlyPerformance() {
        $netExpr = $this->netSalesSql('o');
        $activeStatus = $this->activeOrdersCondition('o');
        $stmt = $this->db->prepare("
            SELECT TO_CHAR(d, 'Dy') as day, COALESCE(SUM($netExpr), 0) as total
            FROM generate_series(CURRENT_DATE - INTERVAL '6 days', CURRENT_DATE, '1 day') d
            LEFT JOIN \"Order\" o ON DATE(o.created_at) = DATE(d)
                AND $activeStatus
                AND o.tenant_id = :tenant_id
            GROUP BY d
            ORDER BY d ASC
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll();
    }

    public function getSalesTrend30Days() {
        $netExpr = $this->netSalesSql('o');
        $activeStatus = $this->activeOrdersCondition('o');
        $stmt = $this->db->prepare("
            SELECT TO_CHAR(d, 'DD Mon') as day, COALESCE(SUM($netExpr), 0) as total
            FROM generate_series(CURRENT_DATE - INTERVAL '29 days', CURRENT_DATE, '1 day') d
            LEFT JOIN \"Order\" o ON DATE(o.created_at) = DATE(d)
                AND $activeStatus
                AND o.tenant_id = :tenant_id
            GROUP BY d
            ORDER BY d ASC
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll();
    }

    public function getSalesSummary() {
        $netExpr = $this->netSalesSql('o');
        $vatExpr = $this->vatAmountSql('o');
        $activeStatus = $this->activeOrdersCondition('o');
        $stmt = $this->db->prepare("
            SELECT
                SUM(COALESCE(o.total, 0)) as gross,
                SUM($netExpr) as net,
                SUM($vatExpr) as vat,
                SUM(COALESCE(o.shipping, 0)) as shipping
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id AND $activeStatus
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $row = $stmt->fetch();
        return [
            'gross' => round((float)($row['gross'] ?? 0), 2),
            'net' => round((float)($row['net'] ?? 0), 2),
            'vat' => round((float)($row['vat'] ?? 0), 2),
            'shipping' => round((float)($row['shipping'] ?? 0), 2)
        ];
    }

    public function getKpiTraceability($orderLimit = 12, $productLimit = 8) {
        $safeOrderLimit = max(1, min(50, (int)$orderLimit));
        $safeProductLimit = max(1, min(30, (int)$productLimit));
        $activeStatus = $this->activeOrdersCondition('o');
        $netExpr = $this->netSalesSql('o');
        $vatExpr = $this->vatAmountSql('o');

        $ordersStmt = $this->db->prepare("
            SELECT
                o.id,
                o.created_at,
                LOWER(COALESCE(o.status, 'pending')) as status,
                u.name as user_name,
                COALESCE(o.total, 0) as gross,
                $netExpr as net,
                $vatExpr as vat,
                COALESCE(o.shipping, 0) as shipping
            FROM \"Order\" o
            LEFT JOIN \"User\" u ON o.user_id = u.id AND u.tenant_id = o.tenant_id
            WHERE o.tenant_id = :tenant_id AND $activeStatus
            ORDER BY o.created_at DESC
            LIMIT $safeOrderLimit
        ");
        $ordersStmt->execute(['tenant_id' => $this->getTenantId()]);
        $orders = $ordersStmt->fetchAll();

        foreach ($orders as &$order) {
            $order['gross'] = round((float)($order['gross'] ?? 0), 2);
            $order['net'] = round((float)($order['net'] ?? 0), 2);
            $order['vat'] = round((float)($order['vat'] ?? 0), 2);
            $order['shipping'] = round((float)($order['shipping'] ?? 0), 2);
        }
        unset($order);

        $vatRate = $this->getTaxRate();
        $productsStmt = $this->db->prepare("
            SELECT
                oi.product_id,
                COALESCE(NULLIF(TRIM(oi.product_name), ''), 'Producto sin nombre') as product_name,
                COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría') as category,
                SUM(oi.quantity) as units_sold,
                SUM(oi.quantity * (oi.price / NULLIF((1 + (COALESCE(o.vat_rate, :vat_rate) / 100.0)), 0))) as net_revenue,
                STRING_AGG(DISTINCT o.id, ', ') as order_refs
            FROM \"OrderItem\" oi
            JOIN \"Order\" o ON oi.order_id = o.id
            LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
            WHERE o.tenant_id = :tenant_id AND $activeStatus
            GROUP BY
                oi.product_id,
                COALESCE(NULLIF(TRIM(oi.product_name), ''), 'Producto sin nombre'),
                COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría')
            ORDER BY net_revenue DESC, units_sold DESC
            LIMIT $safeProductLimit
        ");
        $productsStmt->execute([
            'vat_rate' => $vatRate,
            'tenant_id' => $this->getTenantId()
        ]);
        $products = $productsStmt->fetchAll();

        foreach ($products as &$product) {
            $product['units_sold'] = (int)($product['units_sold'] ?? 0);
            $product['net_revenue'] = round((float)($product['net_revenue'] ?? 0), 2);
            $refs = trim((string)($product['order_refs'] ?? ''));
            $product['order_refs'] = $refs === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $refs))));
        }
        unset($product);

        $categoriesStmt = $this->db->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría') as category,
                SUM(oi.quantity * (oi.price / NULLIF((1 + (COALESCE(o.vat_rate, :vat_rate) / 100.0)), 0))) as net_revenue,
                STRING_AGG(DISTINCT o.id, ', ') as order_refs
            FROM \"OrderItem\" oi
            JOIN \"Order\" o ON oi.order_id = o.id
            LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
            WHERE o.tenant_id = :tenant_id AND $activeStatus
            GROUP BY COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría')
            ORDER BY net_revenue DESC
            LIMIT 6
        ");
        $categoriesStmt->execute([
            'vat_rate' => $vatRate,
            'tenant_id' => $this->getTenantId()
        ]);
        $categories = $categoriesStmt->fetchAll();

        foreach ($categories as &$category) {
            $category['net_revenue'] = round((float)($category['net_revenue'] ?? 0), 2);
            $refs = trim((string)($category['order_refs'] ?? ''));
            $category['order_refs'] = $refs === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $refs))));
        }
        unset($category);

        return [
            'orders' => $orders,
            'products' => $products,
            'categories' => $categories
        ];
    }

    public function getTopProducts() {
        $vatRate = $this->getTaxRate();
        $activeStatus = $this->activeOrdersCondition('o');
        $stmt = $this->db->prepare("
            SELECT oi.product_name as name,
                   SUM(oi.quantity) as sold,
                   SUM(oi.quantity * (oi.price / NULLIF((1 + (COALESCE(o.vat_rate, :vat_rate) / 100.0)), 0))) as revenue
            FROM \"OrderItem\" oi
            JOIN \"Order\" o ON oi.order_id = o.id
            WHERE o.tenant_id = :tenant_id AND $activeStatus
            GROUP BY oi.product_name
            ORDER BY sold DESC
            LIMIT 5
        ");
        $stmt->execute([
            'vat_rate' => $vatRate,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetchAll();
    }

    public function getProductSalesRanking(?string $selectedMonth = null) {
        $tenantId = $this->getTenantId();
        $vatRate = $this->getTaxRate();
        $realizedSales = $this->realizedSalesCondition('o');
        $netExpr = $this->netSalesSql('o');
        $vatExpr = $this->vatAmountSql('o');
        $periodStmt = $this->db->prepare("
            SELECT
                MIN(o.created_at)::date AS historical_start,
                MAX(o.created_at)::date AS historical_end
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id
              AND $realizedSales
        ");
        $periodStmt->execute(['tenant_id' => $tenantId]);
        $periodRow = $periodStmt->fetch() ?: [];
        $monthKey = null;
        if (is_string($selectedMonth) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth) === 1) {
            $monthKey = $selectedMonth;
        }
        if ($monthKey === null) {
            $monthKey = date('Y-m');
        }
        $monthStart = $monthKey . '-01';
        $nextMonthStart = date('Y-m-01', strtotime($monthStart . ' +1 month'));
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $monthlySalesStmt = $this->db->prepare("
            SELECT
                COUNT(*)::int AS orders_count,
                COALESCE(SUM(COALESCE(o.total, 0)), 0) AS gross,
                COALESCE(SUM($netExpr), 0) AS net,
                COALESCE(SUM($vatExpr), 0) AS vat,
                COALESCE(SUM(COALESCE(o.shipping, 0)), 0) AS shipping
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id
              AND $realizedSales
              AND o.created_at >= :start_date
              AND o.created_at < :end_date
        ");
        $monthlySalesStmt->execute([
            'tenant_id' => $tenantId,
            'start_date' => $monthStart,
            'end_date' => $nextMonthStart
        ]);
        $monthlySales = $monthlySalesStmt->fetch() ?: [];

        $historicalSalesStmt = $this->db->prepare("
            SELECT
                COUNT(*)::int AS orders_count,
                COALESCE(SUM(COALESCE(o.total, 0)), 0) AS gross,
                COALESCE(SUM($netExpr), 0) AS net,
                COALESCE(SUM($vatExpr), 0) AS vat,
                COALESCE(SUM(COALESCE(o.shipping, 0)), 0) AS shipping
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id
              AND $realizedSales
        ");
        $historicalSalesStmt->execute([
            'tenant_id' => $tenantId
        ]);
        $historicalSales = $historicalSalesStmt->fetch() ?: [];

        $monthlyCostStmt = $this->db->prepare("
            SELECT COALESCE(SUM(oi.quantity * COALESCE(p.cost, 0)), 0) AS cost
            FROM \"OrderItem\" oi
            JOIN \"Order\" o ON oi.order_id = o.id
            LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
            WHERE o.tenant_id = :tenant_id
              AND $realizedSales
              AND o.created_at >= :start_date
              AND o.created_at < :end_date
        ");
        $monthlyCostStmt->execute([
            'tenant_id' => $tenantId,
            'start_date' => $monthStart,
            'end_date' => $nextMonthStart
        ]);
        $monthlyCost = (float)($monthlyCostStmt->fetchColumn() ?: 0);

        $historicalCostStmt = $this->db->prepare("
            SELECT COALESCE(SUM(oi.quantity * COALESCE(p.cost, 0)), 0) AS cost
            FROM \"OrderItem\" oi
            JOIN \"Order\" o ON oi.order_id = o.id
            LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
            WHERE o.tenant_id = :tenant_id
              AND $realizedSales
        ");
        $historicalCostStmt->execute([
            'tenant_id' => $tenantId
        ]);
        $historicalCost = (float)($historicalCostStmt->fetchColumn() ?: 0);

        $stmt = $this->db->prepare("
            WITH active_lines AS (
                SELECT
                    oi.product_id,
                    o.id AS order_id,
                    o.created_at,
                    COALESCE(oi.quantity, 0)::numeric AS quantity,
                    (COALESCE(oi.quantity, 0) * COALESCE(oi.price, 0)) AS line_gross_items,
                    (COALESCE(oi.quantity, 0) * (COALESCE(oi.price, 0) / NULLIF((1 + (COALESCE(o.vat_rate, :vat_rate) / 100.0)), 0))) AS line_net_estimate,
                    COALESCE(o.shipping, 0) AS order_shipping,
                    COALESCE($netExpr, 0) AS order_net,
                    COALESCE($vatExpr, 0) AS order_vat,
                    SUM(COALESCE(oi.quantity, 0) * COALESCE(oi.price, 0)) OVER (PARTITION BY o.id) AS order_items_gross,
                    COALESCE(pr.cost, 0) AS unit_cost
                FROM \"OrderItem\" oi
                JOIN \"Order\" o ON oi.order_id = o.id
                LEFT JOIN \"Product\" pr ON oi.product_id = pr.id AND pr.tenant_id = :tenant_id
                WHERE o.tenant_id = :tenant_id
                  AND $realizedSales
            ),
            distributed_lines AS (
                SELECT
                    product_id,
                    order_id,
                    created_at,
                    quantity,
                    line_gross_items,
                    line_net_estimate,
                    CASE
                        WHEN order_net > 0 THEN order_shipping * (line_net_estimate / order_net)
                        WHEN order_items_gross > 0 THEN order_shipping * (line_gross_items / order_items_gross)
                        ELSE 0
                    END AS line_shipping,
                    CASE
                        WHEN order_net > 0 THEN order_vat * (line_net_estimate / order_net)
                        WHEN order_items_gross > 0 THEN GREATEST(line_gross_items - line_net_estimate, 0)
                        ELSE 0
                    END AS line_vat,
                    unit_cost
                FROM active_lines
            ),
            product_metrics AS (
                SELECT
                    dl.product_id,
                    COUNT(DISTINCT dl.order_id) FILTER (
                        WHERE dl.created_at >= :start_date AND dl.created_at < :end_date
                    )::int AS month_orders_count,
                    COALESCE(SUM(dl.quantity) FILTER (
                        WHERE dl.created_at >= :start_date AND dl.created_at < :end_date
                    ), 0)::int AS month_units_sold,
                    COALESCE(SUM(dl.line_gross_items + dl.line_shipping) FILTER (
                        WHERE dl.created_at >= :start_date AND dl.created_at < :end_date
                    ), 0) AS month_gross_revenue,
                    COALESCE(SUM(dl.line_net_estimate) FILTER (
                        WHERE dl.created_at >= :start_date AND dl.created_at < :end_date
                    ), 0) AS month_net_revenue,
                    COALESCE(SUM(dl.line_vat) FILTER (
                        WHERE dl.created_at >= :start_date AND dl.created_at < :end_date
                    ), 0) AS month_vat_amount,
                    COALESCE(SUM(dl.line_shipping) FILTER (
                        WHERE dl.created_at >= :start_date AND dl.created_at < :end_date
                    ), 0) AS month_shipping_amount,
                    COALESCE(SUM(dl.quantity * dl.unit_cost) FILTER (
                        WHERE dl.created_at >= :start_date AND dl.created_at < :end_date
                    ), 0) AS month_cost,
                    COUNT(DISTINCT dl.order_id)::int AS historical_orders_count,
                    COALESCE(SUM(dl.quantity), 0)::int AS historical_units_sold,
                    COALESCE(SUM(dl.line_gross_items + dl.line_shipping), 0) AS historical_gross_revenue,
                    COALESCE(SUM(dl.line_net_estimate), 0) AS historical_net_revenue,
                    COALESCE(SUM(dl.line_vat), 0) AS historical_vat_amount,
                    COALESCE(SUM(dl.line_shipping), 0) AS historical_shipping_amount,
                    COALESCE(SUM(dl.quantity * dl.unit_cost), 0) AS historical_cost
                FROM distributed_lines dl
                GROUP BY dl.product_id
            )
            SELECT
                p.id AS product_id,
                COALESCE(NULLIF(TRIM(p.name), ''), 'Producto sin nombre') AS product_name,
                COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría') AS category,
                COALESCE(pm.month_orders_count, 0) AS month_orders_count,
                COALESCE(pm.month_units_sold, 0) AS month_units_sold,
                COALESCE(pm.month_gross_revenue, 0) AS month_gross_revenue,
                COALESCE(pm.month_net_revenue, 0) AS month_net_revenue,
                COALESCE(pm.month_vat_amount, 0) AS month_vat_amount,
                COALESCE(pm.month_shipping_amount, 0) AS month_shipping_amount,
                COALESCE(pm.month_cost, 0) AS month_cost,
                COALESCE(pm.historical_orders_count, 0) AS historical_orders_count,
                COALESCE(pm.historical_units_sold, 0) AS historical_units_sold,
                COALESCE(pm.historical_gross_revenue, 0) AS historical_gross_revenue,
                COALESCE(pm.historical_net_revenue, 0) AS historical_net_revenue,
                COALESCE(pm.historical_vat_amount, 0) AS historical_vat_amount,
                COALESCE(pm.historical_shipping_amount, 0) AS historical_shipping_amount,
                COALESCE(pm.historical_cost, 0) AS historical_cost
            FROM \"Product\" p
            LEFT JOIN product_metrics pm ON pm.product_id = p.id
            WHERE p.tenant_id = :tenant_id
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'vat_rate' => $vatRate,
            'start_date' => $monthStart,
            'end_date' => $nextMonthStart
        ]);
        $rows = $stmt->fetchAll() ?: [];

        $normalized = array_map(static function ($row) {
            $monthNet = (float)($row['month_net_revenue'] ?? 0);
            $monthCost = (float)($row['month_cost'] ?? 0);
            $monthProfit = $monthNet - $monthCost;
            $monthMargin = $monthNet > 0 ? ($monthProfit / $monthNet) * 100 : 0;
            $historicalNet = (float)($row['historical_net_revenue'] ?? 0);
            $historicalCost = (float)($row['historical_cost'] ?? 0);
            $historicalProfit = $historicalNet - $historicalCost;
            $historicalMargin = $historicalNet > 0 ? ($historicalProfit / $historicalNet) * 100 : 0;

            return [
                'product_id' => (string)($row['product_id'] ?? ''),
                'product_name' => (string)($row['product_name'] ?? 'Producto sin nombre'),
                'category' => (string)($row['category'] ?? 'Sin categoría'),
                'month_orders_count' => (int)($row['month_orders_count'] ?? 0),
                'month_units_sold' => (int)($row['month_units_sold'] ?? 0),
                'month_gross_revenue' => round((float)($row['month_gross_revenue'] ?? 0), 2),
                'month_net_revenue' => round((float)($row['month_net_revenue'] ?? 0), 2),
                'month_vat_amount' => round((float)($row['month_vat_amount'] ?? 0), 2),
                'month_shipping_amount' => round((float)($row['month_shipping_amount'] ?? 0), 2),
                'month_cost' => round($monthCost, 2),
                'month_profit' => round($monthProfit, 2),
                'month_margin' => round($monthMargin, 1),
                'historical_orders_count' => (int)($row['historical_orders_count'] ?? 0),
                'historical_units_sold' => (int)($row['historical_units_sold'] ?? 0),
                'historical_gross_revenue' => round((float)($row['historical_gross_revenue'] ?? 0), 2),
                'historical_net_revenue' => round((float)($row['historical_net_revenue'] ?? 0), 2),
                'historical_vat_amount' => round((float)($row['historical_vat_amount'] ?? 0), 2),
                'historical_shipping_amount' => round((float)($row['historical_shipping_amount'] ?? 0), 2),
                'historical_cost' => round($historicalCost, 2),
                'historical_profit' => round($historicalProfit, 2),
                'historical_margin' => round($historicalMargin, 1),
            ];
        }, $rows);

        $monthlyRanking = $normalized;
        usort($monthlyRanking, static function ($a, $b) {
            if ($a['month_units_sold'] !== $b['month_units_sold']) {
                return $b['month_units_sold'] <=> $a['month_units_sold'];
            }
            if ($a['historical_units_sold'] !== $b['historical_units_sold']) {
                return $b['historical_units_sold'] <=> $a['historical_units_sold'];
            }
            return strcmp((string)$a['product_name'], (string)$b['product_name']);
        });

        $historicalRanking = $normalized;
        usort($historicalRanking, static function ($a, $b) {
            if ($a['historical_units_sold'] !== $b['historical_units_sold']) {
                return $b['historical_units_sold'] <=> $a['historical_units_sold'];
            }
            if ($a['month_units_sold'] !== $b['month_units_sold']) {
                return $b['month_units_sold'] <=> $a['month_units_sold'];
            }
            return strcmp((string)$a['product_name'], (string)$b['product_name']);
        });

        $monthUnitsTotal = 0;
        $monthNetTotal = 0.0;
        $historicalUnitsTotal = 0;
        $historicalNetTotal = 0.0;
        foreach ($normalized as $item) {
            $monthUnitsTotal += (int)$item['month_units_sold'];
            $monthNetTotal += (float)$item['month_net_revenue'];
            $historicalUnitsTotal += (int)$item['historical_units_sold'];
            $historicalNetTotal += (float)$item['historical_net_revenue'];
        }

        $monthlyNet = (float)($monthlySales['net'] ?? 0);
        $historicalNet = (float)($historicalSales['net'] ?? 0);
        $monthlyProfit = $monthlyNet - $monthlyCost;
        $historicalProfit = $historicalNet - $historicalCost;
        $monthlyMargin = $monthlyNet > 0 ? ($monthlyProfit / $monthlyNet) * 100 : 0;
        $historicalMargin = $historicalNet > 0 ? ($historicalProfit / $historicalNet) * 100 : 0;

        return [
            'period' => [
                'start' => $monthStart,
                'end' => $monthEnd,
            ],
            'selectedMonth' => $monthKey,
            'historicalPeriod' => [
                'start' => isset($periodRow['historical_start']) && $periodRow['historical_start'] !== null ? (string)$periodRow['historical_start'] : null,
                'end' => isset($periodRow['historical_end']) && $periodRow['historical_end'] !== null ? (string)$periodRow['historical_end'] : date('Y-m-d'),
            ],
            'monthlyTotals' => [
                'units_sold' => $monthUnitsTotal,
                'net_revenue' => round($monthNetTotal, 2),
            ],
            'monthlyFinancial' => [
                'orders_count' => (int)($monthlySales['orders_count'] ?? 0),
                'gross' => round((float)($monthlySales['gross'] ?? 0), 2),
                'net' => round($monthlyNet, 2),
                'vat' => round((float)($monthlySales['vat'] ?? 0), 2),
                'shipping' => round((float)($monthlySales['shipping'] ?? 0), 2),
                'cost' => round($monthlyCost, 2),
                'profit' => round($monthlyProfit, 2),
                'margin' => round($monthlyMargin, 1),
            ],
            'historicalTotals' => [
                'units_sold' => $historicalUnitsTotal,
                'net_revenue' => round($historicalNetTotal, 2),
            ],
            'historicalFinancial' => [
                'orders_count' => (int)($historicalSales['orders_count'] ?? 0),
                'gross' => round((float)($historicalSales['gross'] ?? 0), 2),
                'net' => round($historicalNet, 2),
                'vat' => round((float)($historicalSales['vat'] ?? 0), 2),
                'shipping' => round((float)($historicalSales['shipping'] ?? 0), 2),
                'cost' => round($historicalCost, 2),
                'profit' => round($historicalProfit, 2),
                'margin' => round($historicalMargin, 1),
            ],
            'monthlyRanking' => $monthlyRanking,
            'historicalRanking' => $historicalRanking,
        ];
    }

    public function getSalesByCategory() {
        $vatRate = $this->getTaxRate();
        $activeStatus = $this->activeOrdersCondition('o');
        $stmt = $this->db->prepare("
            SELECT COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría') as category,
                   SUM(oi.quantity * (oi.price / NULLIF((1 + (COALESCE(o.vat_rate, :vat_rate) / 100.0)), 0))) as total
            FROM \"OrderItem\" oi
            LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
            JOIN \"Order\" o ON oi.order_id = o.id
            WHERE o.tenant_id = :tenant_id AND $activeStatus
            GROUP BY COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría')
            ORDER BY total DESC
        ");
        $stmt->execute([
            'vat_rate' => $vatRate,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetchAll();
    }

    public function getAverageOrderValue() {
        $netExpr = $this->netSalesSql('o');
        $activeStatus = $this->activeOrdersCondition('o');
        $stmt = $this->db->prepare("
            SELECT AVG($netExpr) as avg
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id AND $activeStatus
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return round((float)($stmt->fetchColumn() ?: 0), 2);
    }

    public function getSalesDeepDive() {
        $netExpr = $this->netSalesSql('o');
        $activeStatus = $this->activeOrdersCondition('o');
        $stmtCurrent = $this->db->prepare("
            SELECT EXTRACT(DAY FROM d) as day, COALESCE(SUM($netExpr), 0) as total
            FROM generate_series(DATE_TRUNC('month', NOW()), CURRENT_DATE, '1 day') d
            LEFT JOIN \"Order\" o ON DATE(o.created_at) = DATE(d)
                AND $activeStatus
                AND o.tenant_id = :tenant_id
            GROUP BY day ORDER BY day ASC
        ");
        $stmtCurrent->execute(['tenant_id' => $this->getTenantId()]);
        $currentDays = $stmtCurrent->fetchAll();

        $stmtPrevious = $this->db->prepare("
            SELECT EXTRACT(DAY FROM d) as day, COALESCE(SUM($netExpr), 0) as total
            FROM generate_series(
                DATE_TRUNC('month', NOW() - INTERVAL '1 month'),
                DATE_TRUNC('month', NOW() - INTERVAL '1 month') + (CURRENT_DATE - DATE_TRUNC('month', NOW())),
                '1 day'
            ) d
            LEFT JOIN \"Order\" o ON DATE(o.created_at) = DATE(d)
                AND $activeStatus
                AND o.tenant_id = :tenant_id
            GROUP BY day ORDER BY day ASC
        ");
        $stmtPrevious->execute(['tenant_id' => $this->getTenantId()]);
        $previousDays = $stmtPrevious->fetchAll();

        $vatRate = $this->getTaxRate();
        $catGrowthStmt = $this->db->prepare("
            WITH this_month AS (
                SELECT
                    COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría') as category,
                    SUM(oi.quantity * (oi.price / NULLIF((1 + (COALESCE(o.vat_rate, :vat_rate) / 100.0)), 0))) as current_sales
                FROM \"OrderItem\" oi
                LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
                JOIN \"Order\" o ON oi.order_id = o.id
                WHERE $activeStatus
                  AND o.tenant_id = :tenant_id
                  AND o.created_at >= DATE_TRUNC('month', NOW())
                GROUP BY COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría')
            ),
            last_month AS (
                SELECT
                    COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría') as category,
                    SUM(oi.quantity * (oi.price / NULLIF((1 + (COALESCE(o.vat_rate, :vat_rate) / 100.0)), 0))) as previous_sales
                FROM \"OrderItem\" oi
                LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
                JOIN \"Order\" o ON oi.order_id = o.id
                WHERE $activeStatus
                  AND o.tenant_id = :tenant_id
                  AND o.created_at >= DATE_TRUNC('month', NOW() - INTERVAL '1 month')
                  AND o.created_at < DATE_TRUNC('month', NOW())
                GROUP BY COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría')
            )
            SELECT
                COALESCE(tm.category, lm.category) as category,
                COALESCE(tm.current_sales, 0) as current,
                COALESCE(lm.previous_sales, 0) as previous,
                CASE
                    WHEN COALESCE(lm.previous_sales, 0) > 0
                        THEN ROUND(((COALESCE(tm.current_sales, 0) - lm.previous_sales) / lm.previous_sales) * 100, 1)
                    WHEN COALESCE(tm.current_sales, 0) > 0
                        THEN 100
                    ELSE 0
                END as growth
            FROM this_month tm
            FULL OUTER JOIN last_month lm ON tm.category = lm.category
            ORDER BY growth DESC
        ");
        $catGrowthStmt->execute([
            'vat_rate' => $vatRate,
            'tenant_id' => $this->getTenantId()
        ]);
        $catGrowth = $catGrowthStmt->fetchAll();

        return [
            'daily' => [
                'current' => $currentDays,
                'previous' => $previousDays
            ],
            'categories' => $catGrowth
        ];
    }

    public function getProfitStats() {
        $netExpr = $this->netSalesSql('o');
        $activeStatus = $this->activeOrdersCondition('o');
        $salesStmt = $this->db->prepare("
            SELECT
                SUM($netExpr) as revenue,
                SUM(COALESCE(o.shipping_base, o.shipping, 0)) as shipping_cost
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id AND $activeStatus
        ");
        $salesStmt->execute(['tenant_id' => $this->getTenantId()]);
        $salesRow = $salesStmt->fetch();
        $revenue = (float)($salesRow['revenue'] ?? 0);
        $shippingCost = (float)($salesRow['shipping_cost'] ?? 0);

        $costStmt = $this->db->prepare("
            SELECT SUM(oi.quantity * COALESCE(p.cost, 0)) as cost
            FROM \"OrderItem\" oi
            JOIN \"Order\" o ON oi.order_id = o.id
            LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
            WHERE o.tenant_id = :tenant_id AND $activeStatus
        ");
        $costStmt->execute(['tenant_id' => $this->getTenantId()]);
        $costRow = $costStmt->fetch();
        $cost = (float)($costRow['cost'] ?? 0);
        $profit = $revenue - $cost;
        $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

        return [
            'revenue' => round($revenue, 2),
            'cost' => round($cost, 2),
            'shipping_cost' => round($shippingCost, 2),
            'profit' => round($profit, 2),
            'margin' => round($margin, 1)
        ];
    }

    public function getInventoryValue() {
        $stmt = $this->db->prepare('SELECT SUM(quantity * price) as market_value, SUM(quantity * cost) as cost_value, SUM(quantity) as total_items FROM "Product" WHERE tenant_id = :tenant_id');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetch();
    }

    public function getOrdersByStatus() {
        $stmt = $this->db->prepare("
            SELECT LOWER(COALESCE(status, 'pending')) as status, COUNT(*) as count
            FROM \"Order\"
            WHERE tenant_id = :tenant_id
            GROUP BY LOWER(COALESCE(status, 'pending'))
            ORDER BY count DESC
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll();
    }

    private function getTenantId() {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }

    private function buildInvoiceNumber($orderId) {
        $date = date('Ymd');
        $suffix = substr(preg_replace('/[^A-Za-z0-9]/', '', $orderId), -6);
        return 'FAC-' . $date . '-' . strtoupper($suffix);
    }

    private function buildInvoiceData($data, $quote) {
        $billing = $data['billing_address'] ?? null;
        if (!$billing) {
            $billing = $this->getUserDefaultBilling($data['user_id']);
        }

        return [
            'customer' => [
                'name' => trim(($data['shipping_address']['firstName'] ?? '') . ' ' . ($data['shipping_address']['lastName'] ?? '')),
                'email' => $data['shipping_address']['email'] ?? null,
                'phone' => $data['shipping_address']['phone'] ?? null
            ],
            'billing_address' => $billing,
            'shipping_address' => [
                'line1' => 'Local Para Mascotas EC',
                'line2' => 'Retiro en tienda'
            ],
            'company' => $billing['company'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'items' => $quote['items'],
            'order_notes' => $data['order_notes'] ?? null
        ];
    }

    public function updateBillingMetadata(string $orderId, array $billingMetadata): void {
        $stmt = $this->db->prepare('SELECT invoice_data FROM "Order" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $orderId,
            'tenant_id' => $this->getTenantId()
        ]);
        $row = $stmt->fetch();

        $invoiceData = [];
        if ($row && !empty($row['invoice_data'])) {
            $decoded = is_array($row['invoice_data']) ? $row['invoice_data'] : json_decode((string)$row['invoice_data'], true);
            if (is_array($decoded)) {
                $invoiceData = $decoded;
            }
        }

        $existingBilling = is_array($invoiceData['billing'] ?? null) ? $invoiceData['billing'] : [];
        $invoiceData['billing'] = array_merge($existingBilling, $billingMetadata);

        $stmtUpdate = $this->db->prepare('UPDATE "Order" SET invoice_data = :invoice_data WHERE id = :id AND tenant_id = :tenant_id');
        $stmtUpdate->execute([
            'id' => $orderId,
            'tenant_id' => $this->getTenantId(),
            'invoice_data' => json_encode($invoiceData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    }

    private function getUserDefaultBilling($userId) {
        if (!$userId) return null;
        $stmt = $this->db->prepare('SELECT addresses FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        $row = $stmt->fetch();
        if (!$row || empty($row['addresses'])) return null;
        $addresses = json_decode($row['addresses'], true);
        if (!is_array($addresses) || count($addresses) === 0) return null;
        $first = $addresses[0];
        return $first['billing'] ?? null;
    }

    private function decodeJsonField($value) {
        if (!$value) return null;
        if (is_array($value)) return $value;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function renderInvoiceHtml($invoiceNumber, $data, $quote, $invoiceData, $baseUrl = null) {
        $frontendBase = TenantContext::appUrl() ?? ($_ENV['FRONTEND_URL'] ?? ($_ENV['APP_URL'] ?? ''));
        $baseUrl = $baseUrl ?: $frontendBase;
        if (empty($baseUrl)) {
            $baseUrl = TenantContext::appUrl() ?? 'https://paramascotasec.com';
        }
        if (strpos($baseUrl, 'api.') !== false) {
            $baseUrl = str_replace('://api.', '://', $baseUrl);
        }
        $logoUrl = rtrim($baseUrl, '/') . '/images/brand/LogoVerde150.png';
        $logoPath = __DIR__ . '/../../public/images/brand/LogoVerde150.png';
        if (file_exists($logoPath)) {
            $logoData = base64_encode(file_get_contents($logoPath));
            $logoUrl = 'data:image/png;base64,' . $logoData;
        }
        $customer = $invoiceData['customer'] ?? [];
        $billing = $invoiceData['billing_address'] ?? null;
        $company = $invoiceData['company'] ?? null;
        $payment = $invoiceData['payment_method'] ?? '—';
        $items = $quote['items'] ?? [];
        $subtotal = $quote['subtotal'] ?? 0;
        $shipping = $quote['shipping'] ?? 0;
        $total = $quote['total'] ?? 0;
        $discountTotal = isset($quote['discount_total']) ? (float)$quote['discount_total'] : 0;
        $discountCode = trim((string)($quote['discount_code'] ?? ''));
        $taxRate = isset($quote['vat_rate']) ? (float)$quote['vat_rate'] : $this->getTaxRate();
        $taxMultiplier = 1 + ($taxRate / 100);
        $taxNetSubtotal = isset($quote['vat_subtotal']) && $quote['vat_subtotal'] !== null
            ? (float)$quote['vat_subtotal']
            : ($taxMultiplier > 0 ? ($subtotal / $taxMultiplier) : $subtotal);
        $taxAmount = isset($quote['vat_amount']) && $quote['vat_amount'] !== null
            ? (float)$quote['vat_amount']
            : ($subtotal - $taxNetSubtotal);
        $billingLines = $this->formatAddressLines($billing);
        $shippingLines = $invoiceData['shipping_address'] ?? [];

        $rows = '';
        foreach ($items as $item) {
            $itemPrice = (float)($item['price'] ?? 0);
            $itemQty = (int)($item['quantity'] ?? 1);
            $priceNet = $taxMultiplier > 0 ? ($itemPrice / $taxMultiplier) : $itemPrice;
            $rowTotal = number_format($priceNet * $itemQty, 2, ',', '.');
            $rows .= '<tr>'
                . '<td>' . htmlspecialchars($item['product_name'] ?? '-') . '</td>'
                . '<td>' . $itemQty . '</td>'
                . '<td>$' . number_format($priceNet, 2, ',', '.') . '</td>'
                . '<td>$' . $rowTotal . '</td>'
                . '</tr>';
        }

        $billingHtml = count($billingLines) > 0 ? implode('', array_map(fn($line) => '<div>' . htmlspecialchars($line) . '</div>', $billingLines)) : '<div>-</div>';
        $shippingHtml = '';
        if (!empty($shippingLines['line1'])) $shippingHtml .= '<div>' . htmlspecialchars($shippingLines['line1']) . '</div>';
        if (!empty($shippingLines['line2'])) $shippingHtml .= '<div>' . htmlspecialchars($shippingLines['line2']) . '</div>';
        $discountLabel = $discountCode !== '' ? ('Descuento (' . htmlspecialchars($discountCode) . ')') : 'Descuento';
        $discountRow = $discountTotal > 0
            ? ('<tr><td colspan="3">' . $discountLabel . '</td><td>-$' . number_format($discountTotal, 2, ',', '.') . '</td></tr>')
            : '';

        return '<!doctype html>
<html lang="es">
<head>
  <!-- invoice_v2_tax_net -->
  <meta charset="utf-8" />
  <title>Factura ' . $invoiceNumber . '</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 32px; color: #1f2937; }
    .header { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
    .brand { display:flex; align-items:center; }
    .brand img { height:36px; }
    .meta { text-align:right; font-size:12px; color:#4b5563; }
    .grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; font-size:12px; }
    .box { border:1px solid #e5e7eb; padding:12px; border-radius:8px; }
    table { width:100%; border-collapse: collapse; margin-top:12px; font-size:12px; }
    th, td { border-bottom:1px solid #e5e7eb; padding:8px; text-align:left; }
    th { background:#f9fafb; text-transform: uppercase; font-size:11px; }
    .items-table { table-layout: fixed; }
    .items-table th:nth-child(2), .items-table td:nth-child(2) { text-align:center; width:80px; }
    .items-table th:nth-child(3), .items-table td:nth-child(3) { text-align:right; width:120px; }
    .items-table th:nth-child(4), .items-table td:nth-child(4) { text-align:right; width:120px; }
    .items-table tfoot td { padding:6px 8px; border-bottom:none; }
    .items-table tfoot td:last-child { text-align:right; }
    .items-table tfoot tr.total td { font-weight:700; font-size:14px; }
    .total { font-weight:700; font-size:14px; }
  </style>
</head>
<body>
  <div class="header">
    <div class="brand">
      <img src="' . htmlspecialchars($logoUrl) . '" alt="Para Mascotas EC" />
    </div>
    <div class="meta">
      <div>Factura: ' . htmlspecialchars($invoiceNumber) . '</div>
      <div>Fecha: ' . date('d/m/Y') . '</div>
    </div>
  </div>
  <div class="grid">
    <div class="box">
      <strong>Cliente</strong>
      <div>' . htmlspecialchars($customer['name'] ?? '-') . '</div>
      <div>' . htmlspecialchars($customer['email'] ?? '-') . '</div>
      <div>' . htmlspecialchars($customer['phone'] ?? '-') . '</div>
    </div>
    <div class="box">
      <strong>Método de pago</strong>
      <div>' . htmlspecialchars($payment) . '</div>
    </div>
    <div class="box">
      <strong>Dirección de envío</strong>
      ' . $shippingHtml . '
    </div>
    <div class="box">
      <strong>Dirección de facturación</strong>
      ' . $billingHtml . '
    </div>
    <div class="box">
      <strong>Empresa</strong>
      <div>' . htmlspecialchars($company ?: 'No aplica') . '</div>
    </div>
  </div>
  <div class="section">
    <strong>Artículos</strong>
    <table class="items-table">
      <thead>
        <tr>
          <th>Producto</th>
          <th>Cantidad</th>
          <th>Precio</th>
          <th>Sub total</th>
        </tr>
      </thead>
      <tbody>
        ' . $rows . '
      </tbody>
      <tfoot>
        <tr><td colspan="3">Subtotal sin IVA</td><td>$' . number_format($taxNetSubtotal, 2, ',', '.') . '</td></tr>
        <tr><td colspan="3">IVA (' . number_format($taxRate, 2, ',', '.') . '%)</td><td>$' . number_format($taxAmount, 2, ',', '.') . '</td></tr>
        ' . $discountRow . '
        <tr><td colspan="3">Envío</td><td>$' . number_format($shipping, 2, ',', '.') . '</td></tr>
        <tr class="total"><td colspan="3">Total</td><td>$' . number_format($total, 2, ',', '.') . '</td></tr>
      </tfoot>
    </table>
  </div>
</body>
</html>';
    }

    private function addTaxBreakdown($order) {
        if (!$order || !is_array($order)) return $order;
        $taxRate = $this->getTaxRate();
        $taxMultiplier = 1 + ($taxRate / 100);
        $items = $order['items'] ?? [];
        $itemsSubtotal = isset($order['items_subtotal']) ? (float)$order['items_subtotal'] : 0;
        if ($itemsSubtotal <= 0 && is_array($items)) {
            foreach ($items as $item) {
                $itemsSubtotal += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 1);
            }
        }
        $storedVatSubtotal = isset($order['vat_subtotal']) ? (float)$order['vat_subtotal'] : 0;
        $storedVatAmount = isset($order['vat_amount']) ? (float)$order['vat_amount'] : 0;
        $storedVatRate = isset($order['vat_rate']) ? (float)$order['vat_rate'] : 0;
        $netSubtotal = $storedVatSubtotal > 0 ? $storedVatSubtotal : ($taxMultiplier > 0 ? ($itemsSubtotal / $taxMultiplier) : $itemsSubtotal);
        $taxAmount = $storedVatAmount > 0 ? $storedVatAmount : ($itemsSubtotal - $netSubtotal);
        $order['vat_rate'] = $storedVatRate > 0 ? $storedVatRate : $taxRate;
        $order['vat_subtotal'] = round($netSubtotal, 2);
        $order['vat_amount'] = round($taxAmount, 2);
        $order['items_subtotal'] = round($itemsSubtotal, 2);
        $discountTotal = isset($order['discount_total']) ? (float)$order['discount_total'] : 0;
        if ($discountTotal < 0) $discountTotal = 0;
        $order['discount_total'] = round($discountTotal, 2);
        if (!array_key_exists('discount_code', $order)) {
            $order['discount_code'] = null;
        }
        $shipping = isset($order['shipping']) ? (float)$order['shipping'] : null;
        if ($shipping === null || $shipping < 0) {
            $orderTotal = (float)($order['total'] ?? $itemsSubtotal);
            $shipping = $orderTotal - $itemsSubtotal;
            if ($shipping < 0) $shipping = 0;
        }
        $order['shipping'] = round($shipping, 2);

        $shippingTaxRate = isset($order['shipping_tax_rate']) ? (float)$order['shipping_tax_rate'] : $this->getShippingTaxRate();
        if ($shippingTaxRate < 0) $shippingTaxRate = 0;
        $shippingBase = isset($order['shipping_base']) ? (float)$order['shipping_base'] : null;
        if ($shippingBase === null || $shippingBase < 0) {
            $shippingBase = $shippingTaxRate > 0 ? ($order['shipping'] / (1 + ($shippingTaxRate / 100))) : $order['shipping'];
        }
        $shippingTaxAmount = isset($order['shipping_tax_amount']) ? (float)$order['shipping_tax_amount'] : null;
        if ($shippingTaxAmount === null || $shippingTaxAmount < 0) {
            $shippingTaxAmount = $shippingTaxRate > 0 ? ($shippingBase * ($shippingTaxRate / 100)) : 0;
        }
        $order['shipping_base'] = round($shippingBase, 2);
        $order['shipping_tax_rate'] = round($shippingTaxRate, 2);
        $order['shipping_tax_amount'] = round($shippingTaxAmount, 2);

        $shouldPersist = (empty($order['items_subtotal']) || empty($order['vat_subtotal']) || empty($order['vat_amount']) || empty($order['vat_rate']) || !isset($order['shipping']) || !isset($order['shipping_base']) || !isset($order['shipping_tax_amount']) || !isset($order['shipping_tax_rate']) || !isset($order['discount_total']) || !array_key_exists('discount_code', $order));
        if (!empty($order['id']) && $shouldPersist) {
            try {
                $stmt = $this->db->prepare('UPDATE "Order" SET items_subtotal = :items_subtotal, vat_subtotal = :vat_subtotal, vat_rate = :vat_rate, vat_amount = :vat_amount, shipping = :shipping, shipping_base = :shipping_base, shipping_tax_rate = :shipping_tax_rate, shipping_tax_amount = :shipping_tax_amount, discount_total = :discount_total, discount_code = COALESCE(discount_code, :discount_code) WHERE id = :id AND tenant_id = :tenant_id');
                $stmt->execute([
                    'id' => $order['id'],
                    'tenant_id' => $this->getTenantId(),
                    'items_subtotal' => $order['items_subtotal'],
                    'vat_subtotal' => $order['vat_subtotal'],
                    'vat_rate' => $order['vat_rate'],
                    'vat_amount' => $order['vat_amount'],
                    'shipping' => $order['shipping'],
                    'shipping_base' => $order['shipping_base'],
                    'shipping_tax_rate' => $order['shipping_tax_rate'],
                    'shipping_tax_amount' => $order['shipping_tax_amount'],
                    'discount_total' => $order['discount_total'],
                    'discount_code' => $order['discount_code']
                ]);
            } catch (\Exception $e) {
                // noop: avoid breaking read flow
            }
        }
        return $order;
    }

    private function formatAddressLines($addr) {
        if (!$addr || !is_array($addr)) return [];
        $nameLine = trim(($addr['firstName'] ?? '') . ' ' . ($addr['lastName'] ?? ''));
        $cityLine = trim(implode(', ', array_filter([$addr['city'] ?? null, $addr['state'] ?? null, $addr['zip'] ?? null])));
        $docType = trim((string)($addr['documentType'] ?? ''));
        $docNumber = trim((string)($addr['documentNumber'] ?? ''));
        $docLine = null;
        if ($docType !== '' && $docNumber !== '') {
            $docLine = 'Identificación: ' . $docType . ' ' . $docNumber;
        } elseif ($docNumber !== '') {
            $docLine = 'Identificación: ' . $docNumber;
        }
        $lines = array_filter([
            $nameLine ?: null,
            $addr['company'] ?? null,
            $docLine,
            $addr['street'] ?? null,
            $cityLine ?: null,
            $addr['country'] ?? null,
            $addr['phone'] ?? null,
            $addr['email'] ?? null
        ]);
        return array_values($lines);
    }

    private function translatePaymentMethod($method) {
        $value = strtolower((string)($method ?? ''));
        if ($value === 'credit' || $value === 'card' || $value === 'credit_card') {
            return 'Tarjeta de crédito/débito';
        }
        if ($value === 'transfer' || $value === 'bank_transfer') {
            return 'Transferencia bancaria';
        }
        if ($value === 'cash' || $value === 'cod') {
            return 'Pago contra entrega';
        }
        return $method ?: '—';
    }

    public function getRecentOrders($limit = 5) {
        $netExpr = $this->netSalesSql('o');
        $vatExpr = $this->vatAmountSql('o');
        $activeStatus = $this->activeOrdersCondition('o');
        $safeLimit = max(1, min(50, (int)$limit));
        $stmt = $this->db->prepare("
            SELECT o.id,
                   u.name as user_name,
                   u.email as user_email,
                   o.total,
                   $netExpr as vat_subtotal,
                   $vatExpr as vat_amount,
                   COALESCE(o.shipping, 0) as shipping,
                   COALESCE(o.vat_rate, 0) as vat_rate,
                   o.status,
                   o.created_at
            FROM \"Order\" o
            LEFT JOIN \"User\" u ON o.user_id = u.id AND u.tenant_id = o.tenant_id
            WHERE o.tenant_id = :tenant_id
              AND $activeStatus
            ORDER BY o.created_at DESC
            LIMIT $safeLimit
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll();
    }

    public function getPickupQueue($limit = 10) {
        $safeLimit = max(1, min(100, (int)$limit));
        $stmt = $this->db->prepare("
            SELECT o.id,
                   o.status,
                   o.created_at,
                   o.shipping_address,
                   u.name as user_name,
                   u.email as user_email
            FROM \"Order\" o
            LEFT JOIN \"User\" u ON o.user_id = u.id AND u.tenant_id = o.tenant_id
            WHERE o.tenant_id = :tenant_id
              AND LOWER(COALESCE(o.status, '')) IN ('pickup', 'ready_for_pickup', 'ready')
            ORDER BY o.created_at DESC
            LIMIT $safeLimit
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll();
    }

    public function getInventoryDeepDive() {
        // High Value Stock (Top 5 by cost investment)
        $highValueStmt = $this->db->prepare("
            SELECT name, quantity, cost, (quantity * cost) as total_cost
            FROM \"Product\"
            WHERE tenant_id = :tenant_id AND quantity > 0
            ORDER BY total_cost DESC
            LIMIT 5
        ");
        $highValueStmt->execute(['tenant_id' => $this->getTenantId()]);
        $highValue = $highValueStmt->fetchAll();

        // Stock Risk (Low quantity)
        $stockRiskStmt = $this->db->prepare("
            SELECT name, quantity
            FROM \"Product\"
            WHERE tenant_id = :tenant_id AND quantity <= 5
            ORDER BY quantity ASC
            LIMIT 5
        ");
        $stockRiskStmt->execute(['tenant_id' => $this->getTenantId()]);
        $stockRisk = $stockRiskStmt->fetchAll();

        // Expiration analysis (only products with stock and valid YYYY-MM-DD expiration date in attributes)
        $expiringSoonStmt = $this->db->prepare("
            SELECT
                t.id,
                t.legacy_id,
                t.name,
                t.quantity,
                t.expiration_date,
                t.expiration_alert_days,
                (t.expiration_date - CURRENT_DATE) AS days_to_expire
            FROM (
                SELECT
                    id,
                    legacy_id,
                    name,
                    quantity,
                    CASE
                        WHEN COALESCE(attributes->>'expirationDate', attributes->>'expiryDate') ~ '^\d{4}-\d{2}-\d{2}$'
                            THEN (COALESCE(attributes->>'expirationDate', attributes->>'expiryDate'))::date
                        ELSE NULL
                    END AS expiration_date,
                    CASE
                        WHEN COALESCE(attributes->>'expirationAlertDays', attributes->>'expiryAlertDays') ~ '^\d+$'
                            THEN GREATEST(0, (COALESCE(attributes->>'expirationAlertDays', attributes->>'expiryAlertDays'))::int)
                        ELSE 30
                    END AS expiration_alert_days
                FROM \"Product\"
                WHERE tenant_id = :tenant_id
                  AND quantity > 0
            ) t
            WHERE t.expiration_date IS NOT NULL
              AND t.expiration_date >= CURRENT_DATE
              AND t.expiration_date <= (CURRENT_DATE + t.expiration_alert_days)
            ORDER BY t.expiration_date ASC, t.quantity DESC
            LIMIT 8
        ");
        $expiringSoonStmt->execute(['tenant_id' => $this->getTenantId()]);
        $expiringSoon = $expiringSoonStmt->fetchAll();

        $expiredStmt = $this->db->prepare("
            SELECT
                t.id,
                t.legacy_id,
                t.name,
                t.quantity,
                t.expiration_date,
                (CURRENT_DATE - t.expiration_date) AS days_expired
            FROM (
                SELECT
                    id,
                    legacy_id,
                    name,
                    quantity,
                    CASE
                        WHEN COALESCE(attributes->>'expirationDate', attributes->>'expiryDate') ~ '^\d{4}-\d{2}-\d{2}$'
                            THEN (COALESCE(attributes->>'expirationDate', attributes->>'expiryDate'))::date
                        ELSE NULL
                    END AS expiration_date
                FROM \"Product\"
                WHERE tenant_id = :tenant_id
                  AND quantity > 0
            ) t
            WHERE t.expiration_date IS NOT NULL
              AND t.expiration_date < CURRENT_DATE
            ORDER BY t.expiration_date ASC, t.quantity DESC
            LIMIT 8
        ");
        $expiredStmt->execute(['tenant_id' => $this->getTenantId()]);
        $expired = $expiredStmt->fetchAll();

        // Stock Health Summary
        $summaryStmt = $this->db->prepare("
            SELECT
                COUNT(*) FILTER (WHERE t.quantity = 0) AS out_of_stock,
                COUNT(*) FILTER (WHERE t.quantity > 0 AND t.quantity <= 5) AS low_stock,
                COUNT(*) FILTER (WHERE t.quantity > 50) AS overstock,
                COUNT(*) FILTER (
                    WHERE t.quantity > 0
                      AND t.expiration_date IS NOT NULL
                      AND t.expiration_date < CURRENT_DATE
                ) AS expired_products,
                COUNT(*) FILTER (
                    WHERE t.quantity > 0
                      AND t.expiration_date IS NOT NULL
                      AND t.expiration_date >= CURRENT_DATE
                      AND t.expiration_date <= (CURRENT_DATE + t.expiration_alert_days)
                ) AS expiring_products
            FROM (
                SELECT
                    quantity,
                    CASE
                        WHEN COALESCE(attributes->>'expirationDate', attributes->>'expiryDate') ~ '^\d{4}-\d{2}-\d{2}$'
                            THEN (COALESCE(attributes->>'expirationDate', attributes->>'expiryDate'))::date
                        ELSE NULL
                    END AS expiration_date,
                    CASE
                        WHEN COALESCE(attributes->>'expirationAlertDays', attributes->>'expiryAlertDays') ~ '^\d+$'
                            THEN GREATEST(0, (COALESCE(attributes->>'expirationAlertDays', attributes->>'expiryAlertDays'))::int)
                        ELSE 30
                    END AS expiration_alert_days
                FROM \"Product\"
                WHERE tenant_id = :tenant_id
            ) t
        ");
        $summaryStmt->execute(['tenant_id' => $this->getTenantId()]);
        $summary = $summaryStmt->fetch();

        return [
            'highValueItems' => $highValue,
            'riskItems' => $stockRisk,
            'expiringItems' => $expiringSoon,
            'expiredItems' => $expired,
            'health' => $summary
        ];
    }

    public function getAOVDeepDive() {
        $netExpr = $this->netSalesSql('o');
        $activeStatus = $this->activeOrdersCondition('o');
        $distributionStmt = $this->db->prepare("
            WITH orders AS (
                SELECT $netExpr as net_total
                FROM \"Order\" o
                WHERE o.tenant_id = :tenant_id AND $activeStatus
            )
            SELECT
                CASE 
                    WHEN net_total < 50 THEN 'Bajo (<$50)'
                    WHEN net_total BETWEEN 50 AND 150 THEN 'Medio ($50-$150)'
                    ELSE 'Alto (>$150)'
                END as bucket,
                COUNT(*) as count,
                SUM(net_total) as revenue
            FROM orders
            GROUP BY bucket
            ORDER BY count DESC
        ");
        $distributionStmt->execute(['tenant_id' => $this->getTenantId()]);
        $distribution = $distributionStmt->fetchAll();

        return [
            'distribution' => $distribution
        ];
    }
}

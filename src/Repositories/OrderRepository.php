<?php

namespace App\Repositories;

use App\Core\Database;
use PDO;

class OrderRepository {
    private $db;
    private $taxRateCache = null;
    private $shippingRateCache = null;
    private $shippingTaxRateCache = null;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureInvoiceColumns();
    }

    public function getAll() {
        // Fetch orders with user name
        $stmt = $this->db->query('
            SELECT o.*, u.name as user_name 
            FROM "Order" o 
            LEFT JOIN "User" u ON o.user_id = u.id 
            ORDER BY o.created_at DESC
        ');
        return $stmt->fetchAll();
    }

    public function getByUserId($userId) {
        $stmt = $this->db->prepare('SELECT * FROM "Order" WHERE "user_id" = :user_id ORDER BY created_at DESC');
        $stmt->execute(['user_id' => $userId]);
        $orders = $stmt->fetchAll();

        if (!$orders) {
            return [];
        }

        $orderIds = array_map(fn($order) => $order['id'], $orders);
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmtItems = $this->db->prepare('SELECT * FROM "OrderItem" WHERE "order_id" IN (' . $placeholders . ')');
        $stmtItems->execute($orderIds);
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
        $stmt = $this->db->prepare('SELECT * FROM "Order" WHERE "id" = :id');
        $stmt->execute(['id' => $id]);
        $order = $stmt->fetch();

        if ($order) {
            $stmtItems = $this->db->prepare('SELECT * FROM "OrderItem" WHERE "order_id" = :order_id');
            $stmtItems->execute(['order_id' => $id]);
            $order['items'] = $stmtItems->fetchAll();
            $order = $this->addTaxBreakdown($order);
        }

        return $order;
    }

    public function updateStatus($id, $status) {
        $stmt = $this->db->prepare('UPDATE "Order" SET status = :status WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'status' => $status
        ]);
        return $this->getById($id);
    }

    public function ensureInvoiceForOrder($order, $baseUrl = null, $force = false) {
        if (!$order) return null;
        if (!empty($order['invoice_html']) && !$force) {
            return $order['invoice_html'];
        }

        $this->ensureInvoiceColumns();
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
            'vat_amount' => isset($order['vat_amount']) ? (float)$order['vat_amount'] : null
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
            $stmtUser = $this->db->prepare('SELECT name FROM "User" WHERE id = :id');
            $stmtUser->execute(['id' => $order['user_id'] ?? null]);
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

        $stmt = $this->db->prepare('UPDATE "Order" SET invoice_number = :invoice_number, invoice_html = :invoice_html, invoice_created_at = NOW(), invoice_data = :invoice_data WHERE id = :id');
        $stmt->execute([
            'id' => $orderId,
            'invoice_number' => $invoiceNumber,
            'invoice_html' => $invoiceHtml,
            'invoice_data' => json_encode($invoiceData)
        ]);

        return $invoiceHtml;
    }

    public function calculateQuote($items, $deliveryMethod = 'delivery') {
        $subtotal = 0;
        $itemsWithDetails = [];
        $taxRate = $this->getTaxRate();
        $taxMultiplier = 1 + ($taxRate / 100);

        foreach ($items as $item) {
            $stmt = $this->db->prepare('
                SELECT p.id, p.legacy_id, p.price, p.name, 
                       (SELECT url FROM "Image" WHERE product_id = p.id ORDER BY id LIMIT 1) as image
                FROM "Product" p 
                WHERE p.id = :id OR p.legacy_id = :id
            ');
            $stmt->execute(['id' => $item['product_id']]);
            $product = $stmt->fetch();

            if (!$product) {
                throw new \Exception("Producto no encontrado: " . $item['product_id']);
            }

            $priceWithTax = round(floatval($product['price']) * $taxMultiplier, 2);
            $lineTotal = $priceWithTax * $item['quantity'];
            $subtotal += $lineTotal;

            $itemsWithDetails[] = [
                'product_id' => $product['id'],
                'product_name' => $product['name'],
                'product_image' => $item['product_image'] ?? $product['image'],
                'quantity' => $item['quantity'],
                'price' => $priceWithTax,
                'total' => $lineTotal
            ];
        }

        $shippingBase = $this->getShippingRate($deliveryMethod);
        $shippingTaxRate = $this->getShippingTaxRate();
        $shippingTaxAmount = $shippingTaxRate > 0 ? ($shippingBase * ($shippingTaxRate / 100)) : 0;
        $shippingTotal = $shippingBase + $shippingTaxAmount;
        $total = $subtotal + $shippingTotal;
        $vatSubtotal = $taxMultiplier > 0 ? ($subtotal / $taxMultiplier) : $subtotal;
        $vatAmount = $subtotal - $vatSubtotal;

        return [
            'subtotal' => round($subtotal, 2),
            'vat_rate' => round($taxRate, 2),
            'vat_subtotal' => round($vatSubtotal, 2),
            'vat_amount' => round($vatAmount, 2),
            'shipping' => round($shippingTotal, 2),
            'shipping_base' => round($shippingBase, 2),
            'shipping_tax_rate' => round($shippingTaxRate, 2),
            'shipping_tax_amount' => round($shippingTaxAmount, 2),
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
            $this->ensureInvoiceColumns();
            // Inteligencia de Negocio: El Backend recalcula y valida TODO.
            $quote = $this->calculateQuote($data['items'], $data['delivery_method'] ?? 'delivery');
            
            $stmt = $this->db->prepare('INSERT INTO "Order" ("id", "user_id", "total", "status", "created_at", "shipping_address", "billing_address", "payment_method", "payment_details", "items_subtotal", "vat_subtotal", "vat_rate", "vat_amount", "shipping", "shipping_base", "shipping_tax_rate", "shipping_tax_amount") VALUES (:id, :user_id, :total, :status, NOW(), :shipping_address, :billing_address, :payment_method, :payment_details, :items_subtotal, :vat_subtotal, :vat_rate, :vat_amount, :shipping, :shipping_base, :shipping_tax_rate, :shipping_tax_amount)');
            
            $stmt->execute([
                'id' => $data['id'],
                'user_id' => $data['user_id'],
                'total' => $quote['total'],
                'status' => $data['status'] ?? 'pending',
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
                'shipping_tax_amount' => $quote['shipping_tax_amount'] ?? 0
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

                // Reducir stock
                $stmtUpdateStock = $this->db->prepare('UPDATE "Product" SET quantity = quantity - :qty WHERE id = :id');
                $stmtUpdateStock->execute([
                    'qty' => $item['quantity'],
                    'id' => $item['product_id']
                ]);
            }

            $invoiceData = $this->buildInvoiceData($data, $quote);
            $invoiceNumber = $this->buildInvoiceNumber($data['id']);
            $invoiceHtml = $this->renderInvoiceHtml($invoiceNumber, $data, $quote, $invoiceData, $baseUrl);

            $stmtInvoice = $this->db->prepare('UPDATE "Order" SET invoice_number = :invoice_number, invoice_html = :invoice_html, invoice_created_at = NOW(), invoice_data = :invoice_data WHERE id = :id');
            $stmtInvoice->execute([
                'id' => $data['id'],
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

    // Stats methods for Dashboard
    public function getTotalSales() {
        $stmt = $this->db->query('
            SELECT SUM(COALESCE(vat_subtotal, total - COALESCE(vat_amount, 0) - COALESCE(shipping, 0))) as total
            FROM "Order"
            WHERE status != \'canceled\'
        ');
        $result = $stmt->fetch();
        return $result['total'] ?? 0;
    }

    public function getSalesProgress() {
        // This month vs Last month
        $thisMonth = $this->db->query('SELECT SUM(COALESCE(vat_subtotal, total - COALESCE(vat_amount, 0) - COALESCE(shipping, 0))) FROM "Order" WHERE status != \'canceled\' AND created_at >= DATE_TRUNC(\'month\', NOW())')->fetchColumn() ?: 0;
        $lastMonth = $this->db->query('SELECT SUM(COALESCE(vat_subtotal, total - COALESCE(vat_amount, 0) - COALESCE(shipping, 0))) FROM "Order" WHERE status != \'canceled\' AND created_at >= DATE_TRUNC(\'month\', NOW() - INTERVAL \'1 month\') AND created_at < DATE_TRUNC(\'month\', NOW())')->fetchColumn() ?: 0;
        
        $percentage = $lastMonth > 0 ? (($thisMonth - $lastMonth) / $lastMonth) * 100 : 100;
        return [
            'current' => $thisMonth,
            'previous' => $lastMonth,
            'percentage' => round($percentage, 1)
        ];
    }

    public function getNewOrdersCount() {
        // Today
        $stmt = $this->db->query('SELECT COUNT(*) as count FROM "Order" WHERE created_at >= CURRENT_DATE');
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }

    public function getOrdersProgress() {
        $today = $this->db->query('SELECT COUNT(*) FROM "Order" WHERE created_at >= CURRENT_DATE')->fetchColumn() ?: 0;
        $yesterday = $this->db->query('SELECT COUNT(*) FROM "Order" WHERE created_at >= CURRENT_DATE - INTERVAL \'1 day\' AND created_at < CURRENT_DATE')->fetchColumn() ?: 0;
        
        $percentage = $yesterday > 0 ? (($today - $yesterday) / $yesterday) * 100 : 100;
        return [
            'current' => $today,
            'previous' => $yesterday,
            'percentage' => round($percentage, 1)
        ];
    }

    public function getMonthlyPerformance() {
        // Last 7 days sales including today, even if 0
        $stmt = $this->db->query("
            SELECT TO_CHAR(d, 'Dy') as day, COALESCE(SUM(COALESCE(o.vat_subtotal, o.total - COALESCE(o.vat_amount, 0) - COALESCE(o.shipping, 0))), 0) as total
            FROM generate_series(CURRENT_DATE - INTERVAL '6 days', CURRENT_DATE, '1 day') d
            LEFT JOIN \"Order\" o ON DATE(o.created_at) = d AND o.status != 'canceled'
            GROUP BY d
            ORDER BY d ASC
        ");
        return $stmt->fetchAll();
    }

    public function getSalesTrend30Days() {
        // Last 30 days sales including today, even if 0
        $stmt = $this->db->query("
            SELECT TO_CHAR(d, 'DD Mon') as day, COALESCE(SUM(COALESCE(o.vat_subtotal, o.total - COALESCE(o.vat_amount, 0) - COALESCE(o.shipping, 0))), 0) as total
            FROM generate_series(CURRENT_DATE - INTERVAL '29 days', CURRENT_DATE, '1 day') d
            LEFT JOIN \"Order\" o ON DATE(o.created_at) = d AND o.status != 'canceled'
            GROUP BY d
            ORDER BY d ASC
        ");
        return $stmt->fetchAll();
    }

    public function getSalesSummary() {
        $stmt = $this->db->query('
            SELECT
                SUM(total) as gross,
                SUM(COALESCE(vat_subtotal, total - COALESCE(vat_amount, 0) - COALESCE(shipping, 0))) as net,
                SUM(COALESCE(vat_amount, total - COALESCE(shipping, 0) - COALESCE(vat_subtotal, 0))) as vat,
                SUM(COALESCE(shipping, 0)) as shipping
            FROM "Order"
            WHERE status != \'canceled\'
        ');
        $row = $stmt->fetch();
        return [
            'gross' => round($row['gross'] ?? 0, 2),
            'net' => round($row['net'] ?? 0, 2),
            'vat' => round($row['vat'] ?? 0, 2),
            'shipping' => round($row['shipping'] ?? 0, 2)
        ];
    }

    public function getTopProducts() {
        $vatRate = $this->getTaxRate();
        $stmt = $this->db->prepare('
            SELECT oi.product_name as name,
                   SUM(oi.quantity) as sold,
                   SUM(oi.quantity * (oi.price / (1 + (COALESCE(o.vat_rate, :vat_rate) / 100.0)))) as revenue
            FROM "OrderItem" oi
            JOIN "Order" o ON oi.order_id = o.id
            WHERE o.status != \'canceled\'
            GROUP BY oi.product_name
            ORDER BY sold DESC
            LIMIT 5
        ');
        $stmt->execute(['vat_rate' => $vatRate]);
        return $stmt->fetchAll();
    }

    public function getSalesByCategory() {
        $vatRate = $this->getTaxRate();
        $stmt = $this->db->prepare('
            SELECT p.category,
                   SUM(oi.quantity * (oi.price / (1 + (COALESCE(o.vat_rate, :vat_rate) / 100.0)))) as total
            FROM "OrderItem" oi
            JOIN "Product" p ON oi.product_id = p.id
            JOIN "Order" o ON oi.order_id = o.id
            WHERE o.status != \'canceled\'
            GROUP BY p.category
            ORDER BY total DESC
        ');
        $stmt->execute(['vat_rate' => $vatRate]);
        return $stmt->fetchAll();
    }

    public function getAverageOrderValue() {
        $stmt = $this->db->query('SELECT AVG(COALESCE(vat_subtotal, total - COALESCE(vat_amount, 0) - COALESCE(shipping, 0))) as avg FROM "Order" WHERE status != \'canceled\'');
        return round($stmt->fetchColumn() ?: 0, 2);
    }

    public function getSalesDeepDive() {
        // Daily comparison: This Month (all days till today) vs Last Month
        $currentDays = $this->db->query("
            SELECT EXTRACT(DAY FROM d) as day, COALESCE(SUM(COALESCE(o.vat_subtotal, o.total - COALESCE(o.vat_amount, 0) - COALESCE(o.shipping, 0))), 0) as total
            FROM generate_series(DATE_TRUNC('month', NOW()), CURRENT_DATE, '1 day') d
            LEFT JOIN \"Order\" o ON DATE(o.created_at) = DATE(d) AND o.status != 'canceled'
            GROUP BY day ORDER BY day ASC
        ")->fetchAll();

        $previousDays = $this->db->query("
            SELECT EXTRACT(DAY FROM d) as day, COALESCE(SUM(COALESCE(o.vat_subtotal, o.total - COALESCE(o.vat_amount, 0) - COALESCE(o.shipping, 0))), 0) as total
            FROM generate_series(
                DATE_TRUNC('month', NOW() - INTERVAL '1 month'), 
                DATE_TRUNC('month', NOW() - INTERVAL '1 month') + (CURRENT_DATE - DATE_TRUNC('month', NOW())), 
                '1 day'
            ) d
            LEFT JOIN \"Order\" o ON DATE(o.created_at) = DATE(d) AND o.status != 'canceled'
            GROUP BY day ORDER BY day ASC
        ")->fetchAll();

        // Categorical growth drivers
        $vatRate = $this->getTaxRate();
        $catGrowthStmt = $this->db->prepare("
            WITH this_month AS (
                SELECT p.category, SUM(oi.quantity * (oi.price / (1 + (COALESCE(o.vat_rate, :vat_rate) / 100.0)))) as current_sales
                FROM \"OrderItem\" oi
                JOIN \"Product\" p ON oi.product_id = p.id
                JOIN \"Order\" o ON oi.order_id = o.id
                WHERE o.status != 'canceled' AND o.created_at >= DATE_TRUNC('month', NOW())
                GROUP BY p.category
            ),
            last_month AS (
                SELECT p.category, SUM(oi.quantity * (oi.price / (1 + (COALESCE(o.vat_rate, :vat_rate) / 100.0)))) as previous_sales
                FROM \"OrderItem\" oi
                JOIN \"Product\" p ON oi.product_id = p.id
                JOIN \"Order\" o ON oi.order_id = o.id
                WHERE o.status != 'canceled' 
                AND o.created_at >= DATE_TRUNC('month', NOW() - INTERVAL '1 month')
                AND o.created_at < DATE_TRUNC('month', NOW())
                GROUP BY p.category
            )
            SELECT 
                COALESCE(tm.category, lm.category) as category,
                COALESCE(tm.current_sales, 0) as current,
                COALESCE(lm.previous_sales, 0) as previous,
                CASE 
                    WHEN COALESCE(lm.previous_sales, 0) > 0 
                    THEN ROUND(((COALESCE(tm.current_sales, 0) - lm.previous_sales) / lm.previous_sales) * 100, 1)
                    ELSE 100 
                END as growth
            FROM this_month tm
            FULL OUTER JOIN last_month lm ON tm.category = lm.category
            ORDER BY growth DESC
        ");
        $catGrowthStmt->execute(['vat_rate' => $vatRate]);
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
        // Profit = Net Sales (sin IVA y sin envío) - COGS - Gastos de envío
        $salesStmt = $this->db->query('
            SELECT 
                SUM(COALESCE(vat_subtotal, total - COALESCE(vat_amount, 0) - COALESCE(shipping, 0))) as revenue,
                SUM(COALESCE(shipping_base, shipping, 0)) as shipping_cost
            FROM "Order"
            WHERE status != \'canceled\'
        ');
        $salesRow = $salesStmt->fetch();
        $revenue = $salesRow['revenue'] ?: 0;
        $shippingCost = $salesRow['shipping_cost'] ?: 0;

        $costStmt = $this->db->query('
            SELECT SUM(oi.quantity * p.cost) as cost
            FROM "OrderItem" oi
            JOIN "Product" p ON oi.product_id = p.id
            JOIN "Order" o ON oi.order_id = o.id
            WHERE o.status != \'canceled\'
        ');
        $costRow = $costStmt->fetch();
        $cost = $costRow['cost'] ?: 0;
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
        $stmt = $this->db->query('SELECT SUM(quantity * price) as market_value, SUM(quantity * cost) as cost_value, SUM(quantity) as total_items FROM "Product"');
        return $stmt->fetch();
    }

    public function getOrdersByStatus() {
        $stmt = $this->db->query('SELECT status, COUNT(*) as count FROM "Order" GROUP BY status');
        return $stmt->fetchAll();
    }

    private function ensureInvoiceColumns() {
        $this->db->exec('ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS invoice_number text');
        $this->db->exec('ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS invoice_html text');
        $this->db->exec('ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS invoice_created_at timestamp(3) without time zone');
        $this->db->exec('ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS invoice_data jsonb');
        $this->db->exec('ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS payment_details jsonb');
        $this->db->exec('ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS items_subtotal numeric(12,2)');
        $this->db->exec('ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS vat_subtotal numeric(12,2)');
        $this->db->exec('ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS vat_rate numeric(6,2)');
        $this->db->exec('ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS vat_amount numeric(12,2)');
        $this->db->exec('ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS shipping numeric(12,2)');
        $this->db->exec('ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS shipping_base numeric(12,2)');
        $this->db->exec('ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS shipping_tax_rate numeric(6,2)');
        $this->db->exec('ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS shipping_tax_amount numeric(12,2)');
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
            'items' => $quote['items']
        ];
    }

    private function getUserDefaultBilling($userId) {
        if (!$userId) return null;
        $stmt = $this->db->prepare('SELECT addresses FROM "User" WHERE id = :id');
        $stmt->execute(['id' => $userId]);
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
        $frontendBase = $_ENV['FRONTEND_URL'] ?? ($_ENV['APP_URL'] ?? '');
        $baseUrl = $baseUrl ?: $frontendBase;
        if (empty($baseUrl)) {
            $baseUrl = 'https://paramascotasec.com';
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

        $shouldPersist = (empty($order['items_subtotal']) || empty($order['vat_subtotal']) || empty($order['vat_amount']) || empty($order['vat_rate']) || !isset($order['shipping']) || !isset($order['shipping_base']) || !isset($order['shipping_tax_amount']) || !isset($order['shipping_tax_rate']));
        if (!empty($order['id']) && $shouldPersist) {
            try {
                $this->ensureInvoiceColumns();
                $stmt = $this->db->prepare('UPDATE "Order" SET items_subtotal = :items_subtotal, vat_subtotal = :vat_subtotal, vat_rate = :vat_rate, vat_amount = :vat_amount, shipping = :shipping, shipping_base = :shipping_base, shipping_tax_rate = :shipping_tax_rate, shipping_tax_amount = :shipping_tax_amount WHERE id = :id');
                $stmt->execute([
                    'id' => $order['id'],
                    'items_subtotal' => $order['items_subtotal'],
                    'vat_subtotal' => $order['vat_subtotal'],
                    'vat_rate' => $order['vat_rate'],
                    'vat_amount' => $order['vat_amount'],
                    'shipping' => $order['shipping'],
                    'shipping_base' => $order['shipping_base'],
                    'shipping_tax_rate' => $order['shipping_tax_rate'],
                    'shipping_tax_amount' => $order['shipping_tax_amount']
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
        $lines = array_filter([
            $nameLine ?: null,
            $addr['company'] ?? null,
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
        $stmt = $this->db->query("
            SELECT o.id, u.name as user_name, (COALESCE(o.vat_subtotal, o.total - COALESCE(o.vat_amount, 0) - COALESCE(o.shipping, 0))) as total, o.status, o.created_at
            FROM \"Order\" o
            LEFT JOIN \"User\" u ON o.user_id = u.id
            ORDER BY o.created_at DESC
            LIMIT $limit
        ");
        return $stmt->fetchAll();
    }

    public function getInventoryDeepDive() {
        // High Value Stock (Top 5 by cost investment)
        $highValue = $this->db->query("
            SELECT name, quantity, cost, (quantity * cost) as total_cost
            FROM \"Product\"
            WHERE quantity > 0
            ORDER BY total_cost DESC
            LIMIT 5
        ")->fetchAll();

        // Stock Risk (Low quantity)
        $stockRisk = $this->db->query("
            SELECT name, quantity
            FROM \"Product\"
            WHERE quantity <= 5
            ORDER BY quantity ASC
            LIMIT 5
        ")->fetchAll();

        // Stock Health Summary
        $summary = $this->db->query("
            SELECT 
                COUNT(*) FILTER (WHERE quantity = 0) as out_of_stock,
                COUNT(*) FILTER (WHERE quantity > 0 AND quantity <= 5) as low_stock,
                COUNT(*) FILTER (WHERE quantity > 50) as overstock
            FROM \"Product\"
        ")->fetch();

        return [
            'highValueItems' => $highValue,
            'riskItems' => $stockRisk,
            'health' => $summary
        ];
    }

    public function getAOVDeepDive() {
        // Order value distribution buckets
        $distribution = $this->db->query("
            SELECT 
                CASE 
                    WHEN total < 50 THEN 'Bajo (<$50)'
                    WHEN total BETWEEN 50 AND 150 THEN 'Medio ($50-$150)'
                    ELSE 'Alto (>$150)'
                END as bucket,
                COUNT(*) as count,
                SUM(total) as revenue
            FROM \"Order\"
            WHERE status != 'canceled'
            GROUP BY bucket
            ORDER BY count DESC
        ")->fetchAll();

        return [
            'distribution' => $distribution
        ];
    }
}

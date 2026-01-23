<?php

namespace App\Repositories;

use App\Core\Database;
use PDO;

class OrderRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
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
        return $stmt->fetchAll();
    }

    public function getById($id) {
        $stmt = $this->db->prepare('SELECT * FROM "Order" WHERE "id" = :id');
        $stmt->execute(['id' => $id]);
        $order = $stmt->fetch();

        if ($order) {
            $stmtItems = $this->db->prepare('SELECT * FROM "OrderItem" WHERE "order_id" = :order_id');
            $stmtItems->execute(['order_id' => $id]);
            $order['items'] = $stmtItems->fetchAll();
        }

        return $order;
    }

    public function calculateQuote($items, $deliveryMethod = 'delivery') {
        $subtotal = 0;
        $itemsWithDetails = [];

        foreach ($items as $item) {
            $stmt = $this->db->prepare('
                SELECT p.price, p.name, 
                       (SELECT url FROM "Image" WHERE product_id = p.id ORDER BY id LIMIT 1) as image
                FROM "Product" p 
                WHERE p.id = :id
            ');
            $stmt->execute(['id' => $item['product_id']]);
            $product = $stmt->fetch();

            if (!$product) {
                throw new \Exception("Producto no encontrado: " . $item['product_id']);
            }

            $lineTotal = $product['price'] * $item['quantity'];
            $subtotal += $lineTotal;

            $itemsWithDetails[] = [
                'product_id' => $item['product_id'],
                'product_name' => $product['name'],
                'product_image' => $item['product_image'] ?? $product['image'],
                'quantity' => $item['quantity'],
                'price' => (float)$product['price'],
                'total' => $lineTotal
            ];
        }

        $shipping = ($deliveryMethod === 'pickup') ? 0 : 5.00; // Business Logic: Flat rate shipping
        $total = $subtotal + $shipping;

        return [
            'subtotal' => round($subtotal, 2),
            'shipping' => round($shipping, 2),
            'total' => round($total, 2),
            'items' => $itemsWithDetails
        ];
    }

    public function create($data) {
        $this->db->beginTransaction();
        try {
            // Inteligencia de Negocio: El Backend recalcula y valida TODO.
            $quote = $this->calculateQuote($data['items'], $data['delivery_method'] ?? 'delivery');
            
            $stmt = $this->db->prepare('INSERT INTO "Order" ("id", "user_id", "total", "status", "created_at", "shipping_address", "billing_address", "payment_method") VALUES (:id, :user_id, :total, :status, NOW(), :shipping_address, :billing_address, :payment_method)');
            
            $stmt->execute([
                'id' => $data['id'],
                'user_id' => $data['user_id'],
                'total' => $quote['total'],
                'status' => $data['status'] ?? 'pending',
                'shipping_address' => json_encode($data['shipping_address'] ?? null),
                'billing_address' => json_encode($data['billing_address'] ?? null),
                'payment_method' => $data['payment_method'] ?? null
            ]);

            foreach ($quote['items'] as $item) {
                $stmtItem = $this->db->prepare('INSERT INTO "OrderItem" ("order_id", "product_id", "product_name", "product_image", "quantity", "price") VALUES (:order_id, :product_id, :product_name, :product_image, :quantity, :price)');
                
                $stmtItem->execute([
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

            $this->db->commit();
            return $this->getById($data['id']);

        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // Stats methods for Dashboard
    public function getTotalSales() {
        $stmt = $this->db->query('SELECT SUM(total) as total FROM "Order" WHERE status != \'canceled\'');
        $result = $stmt->fetch();
        return $result['total'] ?? 0;
    }

    public function getSalesProgress() {
        // This month vs Last month
        $thisMonth = $this->db->query('SELECT SUM(total) FROM "Order" WHERE status != \'canceled\' AND created_at >= DATE_TRUNC(\'month\', NOW())')->fetchColumn() ?: 0;
        $lastMonth = $this->db->query('SELECT SUM(total) FROM "Order" WHERE status != \'canceled\' AND created_at >= DATE_TRUNC(\'month\', NOW() - INTERVAL \'1 month\') AND created_at < DATE_TRUNC(\'month\', NOW())')->fetchColumn() ?: 0;
        
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
            SELECT TO_CHAR(d, 'Dy') as day, COALESCE(SUM(o.total), 0) as total
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
            SELECT TO_CHAR(d, 'DD Mon') as day, COALESCE(SUM(o.total), 0) as total
            FROM generate_series(CURRENT_DATE - INTERVAL '29 days', CURRENT_DATE, '1 day') d
            LEFT JOIN \"Order\" o ON DATE(o.created_at) = d AND o.status != 'canceled'
            GROUP BY d
            ORDER BY d ASC
        ");
        return $stmt->fetchAll();
    }

    public function getTopProducts() {
        $stmt = $this->db->query('
            SELECT product_name as name, SUM(quantity) as sold, SUM(quantity * price) as revenue
            FROM "OrderItem"
            GROUP BY product_name
            ORDER BY sold DESC
            LIMIT 5
        ');
        return $stmt->fetchAll();
    }

    public function getSalesByCategory() {
        $stmt = $this->db->query('
            SELECT p.category, SUM(oi.quantity * oi.price) as total
            FROM "OrderItem" oi
            JOIN "Product" p ON oi.product_id = p.id
            GROUP BY p.category
            ORDER BY total DESC
        ');
        return $stmt->fetchAll();
    }

    public function getAverageOrderValue() {
        $stmt = $this->db->query('SELECT AVG(total) as avg FROM "Order" WHERE status != \'canceled\'');
        return round($stmt->fetchColumn() ?: 0, 2);
    }

    public function getSalesDeepDive() {
        // Daily comparison: This Month (all days till today) vs Last Month
        $currentDays = $this->db->query("
            SELECT EXTRACT(DAY FROM d) as day, COALESCE(SUM(o.total), 0) as total
            FROM generate_series(DATE_TRUNC('month', NOW()), CURRENT_DATE, '1 day') d
            LEFT JOIN \"Order\" o ON DATE(o.created_at) = DATE(d) AND o.status != 'canceled'
            GROUP BY day ORDER BY day ASC
        ")->fetchAll();

        $previousDays = $this->db->query("
            SELECT EXTRACT(DAY FROM d) as day, COALESCE(SUM(o.total), 0) as total
            FROM generate_series(
                DATE_TRUNC('month', NOW() - INTERVAL '1 month'), 
                DATE_TRUNC('month', NOW() - INTERVAL '1 month') + (CURRENT_DATE - DATE_TRUNC('month', NOW())), 
                '1 day'
            ) d
            LEFT JOIN \"Order\" o ON DATE(o.created_at) = DATE(d) AND o.status != 'canceled'
            GROUP BY day ORDER BY day ASC
        ")->fetchAll();

        // Categorical growth drivers
        $catGrowth = $this->db->query("
            WITH this_month AS (
                SELECT p.category, SUM(oi.quantity * oi.price) as current_sales
                FROM \"OrderItem\" oi
                JOIN \"Product\" p ON oi.product_id = p.id
                JOIN \"Order\" o ON oi.order_id = o.id
                WHERE o.status != 'canceled' AND o.created_at >= DATE_TRUNC('month', NOW())
                GROUP BY p.category
            ),
            last_month AS (
                SELECT p.category, SUM(oi.quantity * oi.price) as previous_sales
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
        ")->fetchAll();

        return [
            'daily' => [
                'current' => $currentDays,
                'previous' => $previousDays
            ],
            'categories' => $catGrowth
        ];
    }

    public function getProfitStats() {
        // Profit = Sales - Cost of items sold
        $stmt = $this->db->query('
            SELECT 
                SUM(oi.quantity * oi.price) as revenue,
                SUM(oi.quantity * p.cost) as cost
            FROM "OrderItem" oi
            JOIN "Product" p ON oi.product_id = p.id
            JOIN "Order" o ON oi.order_id = o.id
            WHERE o.status != \'canceled\'
        ');
        $row = $stmt->fetch();
        $revenue = $row['revenue'] ?: 0;
        $cost = $row['cost'] ?: 0;
        $profit = $revenue - $cost;
        $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
        
        return [
            'revenue' => round($revenue, 2),
            'cost' => round($cost, 2),
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

    public function getRecentOrders($limit = 5) {
        $stmt = $this->db->query("
            SELECT o.id, u.name as user_name, o.total, o.status, o.created_at
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

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

    public function create($data) {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO "Order" ("id", "user_id", "total", "status", "created_at", "shipping_address", "billing_address") VALUES (:id, :user_id, :total, :status, NOW(), :shipping_address, :billing_address)');
            
            $stmt->execute([
                'id' => $data['id'],
                'user_id' => $data['user_id'],
                'total' => $data['total'],
                'status' => $data['status'] ?? 'pending',
                'shipping_address' => json_encode($data['shipping_address'] ?? null),
                'billing_address' => json_encode($data['billing_address'] ?? null)
            ]);

            if (isset($data['items']) && is_array($data['items'])) {
                $stmtItem = $this->db->prepare('INSERT INTO "OrderItem" ("order_id", "product_id", "product_name", "product_image", "quantity", "price") VALUES (:order_id, :product_id, :product_name, :product_image, :quantity, :price)');
                
                foreach ($data['items'] as $item) {
                    $stmtItem->execute([
                        'order_id' => $data['id'],
                        'product_id' => $item['product_id'],
                        'product_name' => $item['product_name'],
                        'product_image' => $item['product_image'] ?? null,
                        'quantity' => $item['quantity'],
                        'price' => $item['price']
                    ]);
                }
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

    public function getNewOrdersCount() {
        // Last 24 hours
        $stmt = $this->db->query('SELECT COUNT(*) as count FROM "Order" WHERE created_at >= NOW() - INTERVAL \'24 hours\'');
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }

    public function getMonthlyPerformance() {
        // Simple day by day for last 7 days? Or months? 
        // Image shows Mon-Sun. Let's do last 7 days sales.
        $stmt = $this->db->query('
            SELECT TO_CHAR(created_at, \'Dy\') as day, SUM(total) as total 
            FROM "Order" 
            WHERE created_at >= NOW() - INTERVAL \'7 days\' 
            GROUP BY TO_CHAR(created_at, \'Dy\'), DATE(created_at) 
            ORDER BY DATE(created_at) ASC
        ');
        return $stmt->fetchAll();
    }
}

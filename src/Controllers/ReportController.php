<?php

namespace App\Controllers;

use App\Repositories\OrderRepository;
use App\Core\Response;

class ReportController {
    private $orderRepository;

    public function __construct() {
        $this->orderRepository = new OrderRepository();
    }

    public function recentOrders() {
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 5;
        if ($limit <= 0 || $limit > 50) {
            Response::error('Limit inválido', 400, 'REPORTS_LIMIT_INVALID');
            return;
        }

        try {
            $orders = $this->orderRepository->getRecentOrders($limit);
            Response::json([
                'orders' => $orders,
                'limit' => $limit
            ]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'REPORTS_RECENT_FAILED');
        }
    }
}

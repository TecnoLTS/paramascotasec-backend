<?php

namespace App\Services;

use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Repositories\ProductRepository;

class BusinessIntelligenceService {
    private $orderRepo;
    private $userRepo;

    public function __construct() {
        $this->orderRepo = new OrderRepository();
        $this->userRepo = new UserRepository();
    }

    public function getFullDashboardStats() {
        return [
            'totalSales' => [
                'amount' => (float)$this->orderRepo->getTotalSales(),
                'progress' => $this->orderRepo->getSalesProgress()
            ],
            'newOrders' => [
                'count' => (int)$this->orderRepo->getNewOrdersCount(),
                'progress' => $this->orderRepo->getOrdersProgress()
            ],
            'newClients' => [
                'count' => (int)$this->userRepo->getNewUsersCount(),
                'progress' => $this->userRepo->getClientsProgress()
            ],
            'monthlyPerformance' => $this->orderRepo->getMonthlyPerformance(),
            'salesTrend30Days' => $this->orderRepo->getSalesTrend30Days(),
            'topProducts' => $this->orderRepo->getTopProducts(),
            'salesByCategory' => $this->orderRepo->getSalesByCategory(),
            'productAnalysis' => $this->productAnalytics(),
            'businessMetrics' => [
                'averageOrderValue' => $this->orderRepo->getAverageOrderValue(),
                'profitStats' => $this->profitAnalysis(),
                'inventoryValue' => $this->orderRepo->getInventoryValue(),
                'ordersByStatus' => $this->orderRepo->getOrdersByStatus(),
                'recentOrders' => $this->orderRepo->getRecentOrders(8),
                'salesDeepDive' => $this->orderRepo->getSalesDeepDive(),
                'inventoryDeepDive' => $this->inventoryHealthCheck(),
                'aovDeepDive' => $this->orderRepo->getAOVDeepDive()
            ],
            'strategicAlerts' => $this->generateAlerts()
        ];
    }

    private function profitAnalysis() {
        $raw = $this->orderRepo->getProfitStats();
        // Add business intelligence: Break-even analysis or projections
        $raw['roi'] = $raw['cost'] > 0 ? round(($raw['profit'] / $raw['cost']) * 100, 1) : 0;
        return $raw;
    }

    private function inventoryHealthCheck() {
        $dive = $this->orderRepo->getInventoryDeepDive();
        // Add intelligence: Days of stock remaining (simulated for now)
        foreach ($dive['riskItems'] as &$item) {
            $item['estimated_days_left'] = max(1, $item['quantity'] * 2); // Simple logic
        }
        return $dive;
    }

    private function productAnalytics() {
        $pRepo = new \App\Repositories\ProductRepository();
        $products = $pRepo->getAll();
        
        $totalMargin = 0;
        $lowMarginCount = 0;
        foreach ($products as $p) {
            $margin = $p['business']['margin'] ?? 0;
            $totalMargin += $margin;
            if ($margin < 25) {
                $lowMarginCount++;
            }
        }
        
        $avgMargin = count($products) > 0 ? round($totalMargin / count($products), 1) : 0;
        
        return [
            'averageMargin' => $avgMargin,
            'lowMarginOpportunities' => $lowMarginCount,
            'totalMonitored' => count($products)
        ];
    }

    private function generateAlerts() {
        $alerts = [];
        $inventory = $this->orderRepo->getInventoryDeepDive();
        
        if ($inventory['health']['out_of_stock'] > 0) {
            $alerts[] = [
                'type' => 'critical',
                'message' => "Tienes {$inventory['health']['out_of_stock']} productos sin stock. Riesgo de pérdida de ventas.",
                'action' => 'Ver inventario'
            ];
        }

        $salesProgress = $this->orderRepo->getSalesProgress();
        if ($salesProgress['percentage'] < -10) {
            $alerts[] = [
                'type' => 'warning',
                'message' => "Las ventas han bajado un " . abs($salesProgress['percentage']) . "% respecto al mes pasado.",
                'action' => 'Analizar campañas'
            ];
        }

        return $alerts;
    }
}

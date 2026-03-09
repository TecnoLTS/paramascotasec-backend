<?php

namespace App\Services;

use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SettingsRepository;

class BusinessIntelligenceService {
    private $orderRepo;
    private $userRepo;

    public function __construct() {
        $this->orderRepo = new OrderRepository();
        $this->userRepo = new UserRepository();
    }

    public function getFullDashboardStats(?string $selectedMonth = null) {
        $settings = new SettingsRepository();
        $vatRate = $settings->get('vat_rate');
        $vatRate = is_numeric($vatRate) ? floatval($vatRate) : 0;
        $salesProgress = $this->orderRepo->getSalesProgress();
        $ordersProgress = $this->orderRepo->getOrdersProgress();
        $clientsProgress = $this->userRepo->getClientsProgress();
        $inventoryDeepDive = $this->inventoryHealthCheck();
        $productAnalysis = $this->productAnalytics();
        $profitStats = $this->profitAnalysis();
        $ordersByStatus = $this->orderRepo->getOrdersByStatus();
        $recentOrders = $this->orderRepo->getRecentOrders(8);
        $salesDeepDive = $this->orderRepo->getSalesDeepDive();
        $aovDeepDive = $this->orderRepo->getAOVDeepDive();
        $salesSummary = $this->orderRepo->getSalesSummary();
        $traceability = $this->orderRepo->getKpiTraceability();
        $productSalesRanking = $this->orderRepo->getProductSalesRanking($selectedMonth);

        return [
            'tax' => [
                'rate' => $vatRate,
                'multiplier' => round(1 + ($vatRate / 100), 4)
            ],
            'totalSales' => [
                'amount' => (float)$this->orderRepo->getTotalSales(),
                'progress' => $salesProgress
            ],
            'newOrders' => [
                'count' => (int)$this->orderRepo->getNewOrdersCount(),
                'progress' => $ordersProgress
            ],
            'newClients' => [
                'count' => (int)$this->userRepo->getNewUsersCount(),
                'progress' => $clientsProgress
            ],
            'monthlyPerformance' => $this->orderRepo->getMonthlyPerformance(),
            'salesTrend30Days' => $this->orderRepo->getSalesTrend30Days(),
            'topProducts' => $this->orderRepo->getTopProducts(),
            'salesByCategory' => $this->orderRepo->getSalesByCategory(),
            'productAnalysis' => $productAnalysis,
            'businessMetrics' => [
                'averageOrderValue' => $this->orderRepo->getAverageOrderValue(),
                'profitStats' => $profitStats,
                'inventoryValue' => $this->orderRepo->getInventoryValue(),
                'ordersByStatus' => $ordersByStatus,
                'recentOrders' => $recentOrders,
                'salesDeepDive' => $salesDeepDive,
                'inventoryDeepDive' => $inventoryDeepDive,
                'aovDeepDive' => $aovDeepDive,
                'salesSummary' => $salesSummary,
                'traceability' => $traceability,
                'productSalesRanking' => $productSalesRanking
            ],
            'strategicAlerts' => $this->generateAlerts($inventoryDeepDive, $salesProgress, $productAnalysis, $ordersByStatus)
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
            $margin = 0;
            if (isset($p['business']['margin']) && is_numeric($p['business']['margin'])) {
                $margin = (float)$p['business']['margin'];
            } else {
                $price = (float)($p['price'] ?? 0);
                $cost = (float)($p['cost'] ?? 0);
                $margin = $price > 0 ? (($price - $cost) / $price) * 100 : 0;
            }
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

    private function generateAlerts($inventory = null, $salesProgress = null, $productAnalysis = null, $ordersByStatus = null) {
        $alerts = [];
        $inventory = is_array($inventory) ? $inventory : $this->orderRepo->getInventoryDeepDive();
        $health = is_array($inventory['health'] ?? null) ? $inventory['health'] : [];
        $expiredCount = (int)($health['expired_products'] ?? 0);
        $expiringCount = (int)($health['expiring_products'] ?? 0);
        
        if ((int)($health['out_of_stock'] ?? 0) > 0) {
            $alerts[] = [
                'type' => 'critical',
                'message' => "Tienes " . (int)$health['out_of_stock'] . " productos sin stock. Riesgo de pérdida de ventas.",
                'action' => 'Ver inventario'
            ];
        }

        if ($expiredCount > 0) {
            $alerts[] = [
                'type' => 'critical',
                'message' => "Hay {$expiredCount} productos vencidos con stock. Retíralos de venta y gestiona devolución o merma.",
                'action' => 'Revisar vencimientos'
            ];
        }

        if ($expiringCount > 0) {
            $nearestExpiry = null;
            if (!empty($inventory['expiringItems']) && is_array($inventory['expiringItems'])) {
                $nearestExpiry = $inventory['expiringItems'][0];
            }
            $daysToExpire = isset($nearestExpiry['days_to_expire']) ? (int)$nearestExpiry['days_to_expire'] : null;
            $soonText = ($daysToExpire !== null && $daysToExpire >= 0)
                ? " El más próximo vence en {$daysToExpire} día(s)."
                : '';

            $alerts[] = [
                'type' => 'warning',
                'message' => "Tienes {$expiringCount} productos próximos a vencer." . $soonText,
                'action' => 'Planificar rotación'
            ];
        }

        $salesProgress = is_array($salesProgress) ? $salesProgress : $this->orderRepo->getSalesProgress();
        if ($salesProgress['percentage'] < -10) {
            $alerts[] = [
                'type' => 'warning',
                'message' => "Las ventas han bajado un " . abs($salesProgress['percentage']) . "% respecto al mes pasado.",
                'action' => 'Analizar campañas'
            ];
        }

        $productAnalysis = is_array($productAnalysis) ? $productAnalysis : $this->productAnalytics();
        $lowMargin = (int)($productAnalysis['lowMarginOpportunities'] ?? 0);
        if ($lowMargin > 0) {
            $alerts[] = [
                'type' => 'info',
                'message' => "Tienes {$lowMargin} productos con margen por debajo del 25%.",
                'action' => 'Ajustar márgenes'
            ];
        }

        $ordersByStatus = is_array($ordersByStatus) ? $ordersByStatus : $this->orderRepo->getOrdersByStatus();
        $pendingOps = 0;
        foreach ($ordersByStatus as $row) {
            $status = strtolower((string)($row['status'] ?? ''));
            if (in_array($status, ['pending', 'processing', 'in_process', 'in-process'], true)) {
                $pendingOps += (int)($row['count'] ?? 0);
            }
        }
        if ($pendingOps >= 10) {
            $alerts[] = [
                'type' => 'warning',
                'message' => "Hay {$pendingOps} pedidos pendientes/en proceso. Revisa operación y despacho.",
                'action' => 'Ver pedidos'
            ];
        }

        return $alerts;
    }
}

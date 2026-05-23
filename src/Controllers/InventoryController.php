<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Services\InventoryIntelligenceService;

class InventoryController {
    public function intelligence(): void {
        Auth::requireAdmin();

        try {
            $windowDays = isset($_GET['window_days']) && is_numeric($_GET['window_days'])
                ? (int)$_GET['window_days']
                : 30;
            $targetDays = isset($_GET['target_days']) && is_numeric($_GET['target_days'])
                ? (int)$_GET['target_days']
                : 30;

            $service = new InventoryIntelligenceService();
            Response::json($service->getIntelligence($windowDays, $targetDays));
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'INVENTORY_INTELLIGENCE_FAILED');
        }
    }
}

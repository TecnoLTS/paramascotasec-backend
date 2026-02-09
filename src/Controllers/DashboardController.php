<?php

namespace App\Controllers;

use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Core\Response;
use App\Core\Auth;

class DashboardController {
    private $orderRepo;
    private $userRepo;

    public function __construct() {
        $this->orderRepo = new OrderRepository();
        $this->userRepo = new UserRepository();
    }

    private function authenticate() {
        Auth::requireUser();
    }

    public function stats() {
        $this->authenticate();
        
        try {
            $biService = new \App\Services\BusinessIntelligenceService();
            $response = $biService->getFullDashboardStats();
            Response::json($response);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'DASHBOARD_STATS_FAILED');
        }
    }
}

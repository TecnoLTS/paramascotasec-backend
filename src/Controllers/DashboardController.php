<?php

namespace App\Controllers;

use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class DashboardController {
    private $orderRepo;
    private $userRepo;

    public function __construct() {
        $this->orderRepo = new OrderRepository();
        $this->userRepo = new UserRepository();
    }

    private function authenticate() {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            exit;
        }
        // ... (reuse auth logic or move to trait/helper)
    }

    public function stats() {
        $this->authenticate();
        
        try {
            $biService = new \App\Services\BusinessIntelligenceService();
            $response = $biService->getFullDashboardStats();
            echo json_encode($response);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}

<?php

namespace App\Controllers;

use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class SettingsController {
    private function authenticate() {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            exit;
        }

        $jwt = $matches[1];
        $secretKey = $_ENV['JWT_SECRET'] ?? 'default_secret';
        try {
            $decoded = JWT::decode($jwt, new Key($secretKey, 'HS256'));
            return (array) $decoded;
        } catch (\Exception $e) {
            http_response_code(401);
            echo json_encode(['error' => 'Token inválido']);
            exit;
        }
    }

    private function requireAdmin($user) {
        if (($user['role'] ?? 'customer') === 'admin') {
            return;
        }

        $repo = new UserRepository();
        $dbUser = $repo->getById($user['sub'] ?? '');
        if (($dbUser['role'] ?? 'customer') === 'admin') {
            return;
        }

        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }

    public function getVat() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $settings = new SettingsRepository();
        $rate = $settings->get('vat_rate');
        echo json_encode(['rate' => $rate !== null ? floatval($rate) : 0]);
    }

    public function updateVat() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['rate']) || !is_numeric($data['rate'])) {
            http_response_code(400);
            echo json_encode(['error' => 'IVA inválido']);
            return;
        }
        $rate = max(0, floatval($data['rate']));
        $settings = new SettingsRepository();
        $settings->set('vat_rate', (string)$rate);
        echo json_encode(['rate' => $rate]);
    }

    public function getShipping() {
        $settings = new SettingsRepository();
        $delivery = $settings->get('shipping_delivery');
        $pickup = $settings->get('shipping_pickup');
        $taxRate = $settings->get('shipping_tax_rate');
        $deliveryValue = is_numeric($delivery) ? floatval($delivery) : 5.0;
        $pickupValue = is_numeric($pickup) ? floatval($pickup) : 0.0;
        $taxValue = is_numeric($taxRate) ? floatval($taxRate) : 0.0;
        if ($delivery === null) {
            $settings->set('shipping_delivery', (string)$deliveryValue);
        }
        if ($pickup === null) {
            $settings->set('shipping_pickup', (string)$pickupValue);
        }
        if ($taxRate === null) {
            $settings->set('shipping_tax_rate', (string)$taxValue);
        }
        echo json_encode([
            'delivery' => $deliveryValue,
            'pickup' => $pickupValue,
            'tax_rate' => $taxValue
        ]);
    }

    public function updateShipping() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['delivery']) || !is_numeric($data['delivery']) || !isset($data['pickup']) || !is_numeric($data['pickup']) || !isset($data['tax_rate']) || !is_numeric($data['tax_rate'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Costos de envío inválidos']);
            return;
        }
        $delivery = max(0, floatval($data['delivery']));
        $pickup = max(0, floatval($data['pickup']));
        $taxRate = max(0, floatval($data['tax_rate']));
        $settings = new SettingsRepository();
        $settings->set('shipping_delivery', (string)$delivery);
        $settings->set('shipping_pickup', (string)$pickup);
        $settings->set('shipping_tax_rate', (string)$taxRate);
        echo json_encode([
            'delivery' => $delivery,
            'pickup' => $pickup,
            'tax_rate' => $taxRate
        ]);
    }
}

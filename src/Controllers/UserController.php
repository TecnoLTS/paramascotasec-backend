<?php

namespace App\Controllers;

use App\Repositories\UserRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class UserController {
    private $userRepository;

    public function __construct() {
        $this->userRepository = new UserRepository();
    }

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

    public function index() {
        try {
            $users = $this->userRepository->getAll();
            echo json_encode($users);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function getAddresses() {
        $user = $this->authenticate();
        try {
            $addresses = $this->userRepository->getAddresses($user['sub']);
            echo json_encode(['addresses' => $addresses ? json_decode($addresses, true) : []]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function updateAddresses() {
        $user = $this->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['addresses'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Direcciones requeridas']);
            return;
        }

        try {
            $addresses = $this->userRepository->updateAddresses($user['sub'], $data['addresses']);
            echo json_encode(['addresses' => $addresses ? json_decode($addresses, true) : []]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function getProfile() {
        $user = $this->authenticate();
        try {
            $profileData = $this->userRepository->getProfile($user['sub']);
            $profile = [];
            $name = null;
            if ($profileData) {
                $name = $profileData['name'] ?? null;
                if (!empty($profileData['profile'])) {
                    $profile = json_decode($profileData['profile'], true) ?: [];
                }
            }
            echo json_encode(['name' => $name, 'profile' => $profile]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function updateProfile() {
        $user = $this->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['profile']) || !is_array($data['profile'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Perfil requerido']);
            return;
        }

        $name = $data['name'] ?? null;
        if (!$name) {
            $first = trim($data['profile']['firstName'] ?? '');
            $last = trim($data['profile']['lastName'] ?? '');
            $name = trim($first . ' ' . $last);
        }

        try {
            $updated = $this->userRepository->updateProfile($user['sub'], $name, $data['profile']);
            $profile = [];
            $savedName = null;
            if ($updated) {
                $savedName = $updated['name'] ?? null;
                if (!empty($updated['profile'])) {
                    $profile = json_decode($updated['profile'], true) ?: [];
                }
            }
            echo json_encode(['name' => $savedName, 'profile' => $profile]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}

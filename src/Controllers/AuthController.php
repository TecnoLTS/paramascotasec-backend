<?php

namespace App\Controllers;

use App\Repositories\UserRepository;
use Firebase\JWT\JWT;

class AuthController {
    private $userRepository;

    public function __construct() {
        $this->userRepository = new UserRepository();
    }

    public function login() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['email']) || !isset($data['password'])) {
            http_response_code(400);
            echo json_encode(['error' => 'El correo y la contraseña son obligatorios']);
            return;
        }

        $user = $this->userRepository->getByEmail($data['email']);

        if (!$user || !password_verify($data['password'], $user['password'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Credenciales inválidas']);
            return;
        }

        if (!$user['email_verified']) {
            http_response_code(403);
            echo json_encode(['error' => 'Por favor, confirma tu dirección de correo electrónico antes de iniciar sesión']);
            return;
        }

        $secretKey = $_ENV['JWT_SECRET'] ?? 'default_secret';
        $payload = [
            'iat' => time(),
            'exp' => time() + (60 * 60 * 3), // 3 hours
            'sub' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name']
        ];

        $jwt = JWT::encode($payload, $secretKey, 'HS256');

        echo json_encode([
            'token' => $jwt,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'role' => $user['role'] ?? 'customer'
            ]
        ]);
    }

    public function register() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['email']) || !isset($data['password']) || !isset($data['name'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Nombre, correo y contraseña son obligatorios']);
            return;
        }

        // Check if user already exists
        if ($this->userRepository->getByEmail($data['email'])) {
            http_response_code(409);
            echo json_encode(['error' => 'El usuario ya existe']);
            return;
        }

        try {
            $result = $this->userRepository->create($data);
            echo json_encode([
                'message' => 'Usuario registrado exitosamente. Por favor, revisa tu correo para confirmar tu cuenta.',
                'id' => $result['id'],
                'debug_token' => $result['token'] // Solo para desarrollo
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'No se pudo registrar el usuario: ' . $e->getMessage()]);
        }
    }

    public function verify() {
        $token = $_GET['token'] ?? null;

        if (!$token) {
            http_response_code(400);
            echo json_encode(['error' => 'Token de verificación no proporcionado']);
            return;
        }

        $user = $this->userRepository->verifyToken($token);

        if ($user) {
            echo json_encode(['message' => 'Correo electrónico confirmado exitosamente. Ya puedes iniciar sesión.']);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Token de verificación inválido o expirado']);
        }
    }
}

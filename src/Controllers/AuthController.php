<?php

namespace App\Controllers;

use App\Repositories\UserRepository;
use App\Services\MailService;
use Firebase\JWT\JWT;
use App\Core\Response;
use App\Core\TenantContext;

class AuthController {
    private $userRepository;

    public function __construct() {
        $this->userRepository = new UserRepository();
    }

    public function login() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['email']) || !isset($data['password'])) {
            Response::error('El correo y la contraseña son obligatorios', 400, 'AUTH_LOGIN_MISSING_FIELDS');
            return;
        }

        $user = $this->userRepository->getByEmail($data['email']);

        if (!$user || !password_verify($data['password'], $user['password'])) {
            Response::error('Credenciales inválidas', 401, 'AUTH_LOGIN_INVALID');
            return;
        }

        if (!$user['email_verified']) {
            Response::error('Por favor, confirma tu dirección de correo electrónico antes de iniciar sesión', 403, 'AUTH_EMAIL_NOT_VERIFIED');
            return;
        }

        $secretKey = $_ENV['JWT_SECRET'] ?? 'default_secret';
        $tokenId = bin2hex(random_bytes(16));
        $payload = [
            'iat' => time(),
            'exp' => time() + (60 * 60 * 3), // 3 hours
            'sub' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role'] ?? 'customer',
            'tenant_id' => TenantContext::id(),
            'jti' => $tokenId
        ];

        $this->userRepository->setActiveTokenId($user['id'], $tokenId);
        $jwt = JWT::encode($payload, $secretKey, 'HS256');

        Response::json([
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
            Response::error('Nombre, correo y contraseña son obligatorios', 400, 'AUTH_REGISTER_MISSING_FIELDS');
            return;
        }

        if (mb_strlen($data['password']) < 12) {
            Response::error('La contraseña debe tener al menos 12 caracteres', 400, 'AUTH_REGISTER_WEAK_PASSWORD');
            return;
        }

        $docType = $data['documentType'] ?? ($data['document_type'] ?? null);
        $docNumber = $data['documentNumber'] ?? ($data['document_number'] ?? null);
        if (!$docType || !$docNumber) {
            Response::error('Tipo y número de identificación son obligatorios', 400, 'AUTH_REGISTER_MISSING_ID');
            return;
        }

        // Check if user already exists
        if ($this->userRepository->getByEmail($data['email'])) {
            Response::error('El usuario ya existe', 409, 'AUTH_REGISTER_EXISTS');
            return;
        }

        try {
            $data['document_type'] = $docType;
            $data['document_number'] = $docNumber;
            $data['business_name'] = $data['businessName'] ?? ($data['business_name'] ?? null);
            $skipVerificationEmail = (bool)($data['skipVerificationEmail'] ?? ($data['skip_verification_email'] ?? false));
            $result = $this->userRepository->create($data, [
                'skip_verification_token' => $skipVerificationEmail
            ]);
            if (!$skipVerificationEmail) {
                if (!$this->sendVerificationEmail($data['email'], $data['name'], $result['token'])) {
                    $this->userRepository->markEmailVerifiedById($result['id']);
                    Response::json([
                        'id' => $result['id'],
                        'debug_token' => $result['token'],
                        'email_verified' => true
                    ], 201, null, 'Cuenta creada. No se pudo enviar el correo de verificación, pero tu cuenta quedó activa. Inicia sesión para continuar.');
                    return;
                }
            }
            Response::json([
                'id' => $result['id'],
                'debug_token' => $result['token'] // Solo para desarrollo
            ], 201, null, 'Usuario registrado exitosamente. Por favor, revisa tu correo para confirmar tu cuenta.');
        } catch (\Exception $e) {
            Response::error('No se pudo registrar el usuario: ' . $e->getMessage(), 500, 'AUTH_REGISTER_FAILED');
        }
    }

    public function guestToken() {
        Response::error('Compra como invitado deshabilitada', 403, 'GUEST_DISABLED');
        return;
        $secretKey = $_ENV['JWT_SECRET'] ?? 'default_secret';
        $payload = [
            'iat' => time(),
            'exp' => time() + (60 * 15), // 15 minutes
            'sub' => 'guest-' . bin2hex(random_bytes(8)),
            'role' => 'guest',
            'scope' => 'guest_checkout',
            'tenant_id' => TenantContext::id()
        ];

        $jwt = JWT::encode($payload, $secretKey, 'HS256');

        Response::json([
            'token' => $jwt,
            'expires_in' => 900
        ]);
    }

    public function verify() {
        $token = $_GET['token'] ?? null;

        if (!$token) {
            Response::error('Token de verificación no proporcionado', 400, 'AUTH_VERIFY_MISSING_TOKEN');
            return;
        }

        $user = $this->userRepository->verifyToken($token);

        if ($user) {
            Response::json(['verified' => true], 200, null, 'Correo electrónico confirmado exitosamente. Ya puedes iniciar sesión.');
        } else {
            Response::error('Token de verificación inválido o expirado', 400, 'AUTH_VERIFY_INVALID');
        }
    }

    public function requestOtp() {
        $data = json_decode(file_get_contents('php://input'), true);
        $email = $data['email'] ?? null;
        if (!$email) {
            Response::error('El correo es obligatorio', 400, 'AUTH_OTP_EMAIL_REQUIRED');
            return;
        }

        $user = $this->userRepository->getByEmail($email);
        if (!$user) {
            Response::error('Usuario no encontrado', 404, 'AUTH_OTP_USER_NOT_FOUND');
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + (10 * 60));
        $this->userRepository->setOtpForEmail($email, $code, $expiresAt);

        if (!$this->sendOtpEmail($email, $user['name'] ?? 'Usuario', $code)) {
            Response::error('No se pudo enviar el código de verificación', 500, 'AUTH_OTP_SEND_FAILED');
            return;
        }

        Response::json(['sent' => true], 200, null, 'Código de verificación enviado.');
    }

    public function verifyOtp() {
        $data = json_decode(file_get_contents('php://input'), true);
        $email = $data['email'] ?? null;
        $code = $data['code'] ?? null;
        if (!$email || !$code) {
            Response::error('Correo y código son obligatorios', 400, 'AUTH_OTP_REQUIRED');
            return;
        }

        $user = $this->userRepository->getByEmailWithOtp($email);
        if (!$user) {
            Response::error('Usuario no encontrado', 404, 'AUTH_OTP_USER_NOT_FOUND');
            return;
        }

        $attempts = (int)($user['otp_attempts'] ?? 0);
        if ($attempts >= 5) {
            Response::error('Demasiados intentos. Solicita un nuevo código.', 429, 'AUTH_OTP_TOO_MANY_ATTEMPTS');
            return;
        }

        $expiresAt = $user['otp_expires_at'] ?? null;
        if (!$expiresAt || strtotime($expiresAt) < time()) {
            Response::error('El código ha expirado. Solicita uno nuevo.', 400, 'AUTH_OTP_EXPIRED');
            return;
        }

        if (($user['otp_code'] ?? '') !== $code) {
            $this->userRepository->incrementOtpAttempts($user['id']);
            Response::error('Código incorrecto', 400, 'AUTH_OTP_INVALID');
            return;
        }

        $this->userRepository->markEmailVerifiedByOtp($user['id']);
        Response::json(['verified' => true], 200, null, 'Correo verificado correctamente.');
    }

    private function sendVerificationEmail($email, $name, $token) {
        $verifyUrl = $this->buildVerificationUrl($token);
        $subject = 'Confirma tu cuenta';

        $safeName = trim($name);
        $message = "Hola {$safeName},\n\n";
        $message .= "Gracias por registrarte en Para Mascotas EC. Para confirmar tu cuenta, visita el siguiente enlace:\n";
        $message .= "{$verifyUrl}\n\n";
        $message .= "Si no solicitaste este registro, puedes ignorar este correo.\n";

        return MailService::send($email, $subject, $message);
    }

    private function sendOtpEmail($email, $name, $code) {
        $subject = 'Tu código de verificación';

        $safeName = trim($name);
        $message = "Hola {$safeName},\n\n";
        $message .= "Tu código de verificación es: {$code}\n";
        $message .= "Este código expira en 10 minutos.\n\n";
        $message .= "Si no solicitaste este código, puedes ignorar este correo.\n";

        return MailService::send($email, $subject, $message);
    }

    private function buildVerificationUrl($token) {
        $baseUrl = TenantContext::appUrl() ?? ($_ENV['APP_URL'] ?? $this->getRequestBaseUrl());
        return rtrim($baseUrl, '/') . '/api/auth/verify?token=' . urlencode($token);
    }

    private function getRequestBaseUrl() {
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
        if (!$proto) {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $proto . '://' . $host;
    }
}

<?php

namespace App\Core;

use App\Repositories\UserRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth {
    private static ?array $payload = null;

    public static function requireUser(): array {
        $payload = self::decodeFromRequest(true);
        if (!$payload) {
            Response::error('No autorizado', 401, 'AUTH_REQUIRED');
            exit;
        }
        return $payload;
    }

    public static function optionalUser(): ?array {
        return self::decodeFromRequest(false);
    }

    public static function decodeFromRequest(bool $required): ?array {
        if (self::$payload !== null) {
            return self::$payload;
        }
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if (!$authHeader || !preg_match('/Bearer\\s(\\S+)/', $authHeader, $matches)) {
            return $required ? null : null;
        }
        $jwt = $matches[1];
        $secretKey = $_ENV['JWT_SECRET'] ?? 'default_secret';
        try {
            $decoded = JWT::decode($jwt, new Key($secretKey, 'HS256'));
        } catch (\Exception $e) {
            Response::error('Token inválido', 401, 'AUTH_TOKEN_INVALID');
            exit;
        }
        $payload = (array) $decoded;
        self::assertTenant($payload);
        self::assertActiveToken($payload);
        self::$payload = $payload;
        return $payload;
    }

    public static function validateRequestOrFail(): void {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if (!$authHeader || !preg_match('/Bearer\\s(\\S+)/', $authHeader, $matches)) {
            Response::error('No autorizado', 401, 'AUTH_REQUIRED');
            exit;
        }
        $jwt = $matches[1];
        $secretKey = $_ENV['JWT_SECRET'] ?? 'default_secret';
        try {
            $decoded = JWT::decode($jwt, new Key($secretKey, 'HS256'));
        } catch (\Exception $e) {
            Response::error('Token inválido', 401, 'AUTH_TOKEN_INVALID');
            exit;
        }
        $payload = (array) $decoded;
        self::assertTenant($payload);
        self::assertActiveToken($payload);
        self::$payload = $payload;
    }

    private static function assertTenant(array $payload): void {
        $tenantId = TenantContext::id();
        if (!$tenantId) {
            Response::error('Tenant requerido', 400, 'TENANT_REQUIRED');
            exit;
        }
        $payloadTenant = $payload['tenant_id'] ?? null;
        if (!$payloadTenant || $payloadTenant !== $tenantId) {
            Response::error('Tenant inválido', 403, 'TENANT_FORBIDDEN');
            exit;
        }
    }

    private static function assertActiveToken(array $payload): void {
        $sub = $payload['sub'] ?? null;
        $jti = $payload['jti'] ?? null;
        if ($sub && $sub !== 'service') {
            $repo = new UserRepository();
            $activeTokenId = $repo->getActiveTokenId($sub);
            if (!$activeTokenId || !$jti || $activeTokenId !== $jti) {
                Response::error('Token inválido', 401, 'AUTH_TOKEN_REVOKED');
                exit;
            }
        }
    }
}

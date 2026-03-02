<?php

namespace App\Core;

use App\Repositories\UserRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth {
    private static ?array $payload = null;

    private static function getJwtSecretOrFail(): string {
        $secretKey = (string)($_ENV['JWT_SECRET'] ?? '');
        if ($secretKey === '') {
            Response::error('Configuración JWT inválida', 500, 'AUTH_CONFIG_INVALID');
            exit;
        }
        if ($secretKey === 'default_secret') {
            error_log('[SECURITY] JWT_SECRET is using insecure default value.');
        }
        return $secretKey;
    }

    public static function requireUser(): array {
        $payload = self::decodeFromRequest(true);
        if (!$payload) {
            Response::error('No autorizado', 401, 'AUTH_REQUIRED');
            exit;
        }
        return $payload;
    }

    public static function requireAdmin(): array {
        $payload = self::requireUser();
        $sub = $payload['sub'] ?? null;
        $role = strtolower((string)($payload['role'] ?? 'customer'));

        if ($sub === 'service') {
            if (in_array($role, ['admin', 'service'], true)) {
                return $payload;
            }
            Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
            exit;
        }

        $repo = new UserRepository();
        $state = $repo->getAuthState($sub ?? '');
        $dbRole = strtolower((string)($state['role'] ?? 'customer'));
        if ($dbRole !== 'admin') {
            Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
            exit;
        }

        $payload['role'] = $dbRole;
        self::$payload = $payload;
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
        $secretKey = self::getJwtSecretOrFail();
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
        $secretKey = self::getJwtSecretOrFail();
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

    private static function assertActiveToken(array &$payload): void {
        $sub = $payload['sub'] ?? null;
        $jti = $payload['jti'] ?? null;
        if (!$sub) {
            Response::error('Token inválido', 401, 'AUTH_TOKEN_INVALID');
            exit;
        }

        if ($sub === 'service') {
            return;
        }

        $repo = new UserRepository();
        $state = $repo->getAuthState($sub);
        if (!$state) {
            Response::error('Token inválido', 401, 'AUTH_TOKEN_REVOKED');
            exit;
        }

        $activeTokenId = $state['active_token_id'] ?? null;
        if (!$activeTokenId || !$jti || $activeTokenId !== $jti) {
            Response::error('Token inválido', 401, 'AUTH_TOKEN_REVOKED');
            exit;
        }

        $tokenRole = strtolower((string)($payload['role'] ?? 'customer'));
        $dbRole = strtolower((string)($state['role'] ?? 'customer'));
        if ($tokenRole !== $dbRole) {
            Response::error('Token inválido', 401, 'AUTH_TOKEN_REVOKED');
            exit;
        }

        $payload['role'] = $dbRole;
    }
}

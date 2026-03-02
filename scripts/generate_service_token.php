<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Dotenv\Dotenv;

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$secretKey = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? '';
if ($secretKey === '') {
    fwrite(STDERR, "JWT_SECRET is required\n");
    exit(1);
}

$tenantId = $_ENV['DEFAULT_TENANT'] ?? getenv('DEFAULT_TENANT');
if (!is_string($tenantId) || trim($tenantId) === '') {
    $tenantId = 'paramascotasec';
}

$ttl = $_ENV['SERVICE_TOKEN_TTL'] ?? getenv('SERVICE_TOKEN_TTL') ?? '86400';
$ttlSeconds = (int)$ttl;
if ($ttlSeconds <= 0) {
    $ttlSeconds = 86400;
}

$now = time();
$payload = [
    'iat' => $now,
    'exp' => $now + $ttlSeconds,
    'sub' => 'service',
    'email' => 'service@paramascotasec.local',
    'name' => 'Service Token',
    'role' => 'admin',
    'tenant_id' => $tenantId,
    'jti' => bin2hex(random_bytes(16)),
];

$token = JWT::encode($payload, $secretKey, 'HS256');
echo $token . PHP_EOL;

<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;

$secretKey = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? 'default_secret';
$now = time();
$payload = [
    'iat' => $now,
    'exp' => $now + (60 * 60 * 24 * 365), // 1 year
    'sub' => 'service',
    'email' => 'service@paramascotasec.local',
    'name' => 'Service Token',
    'role' => 'admin'
];

$token = JWT::encode($payload, $secretKey, 'HS256');
echo $token . PHP_EOL;

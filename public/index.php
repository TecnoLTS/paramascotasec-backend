<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Core\Router;
use App\Core\Response;
use App\Core\TenantContext;
use App\Core\TenantResolver;
use App\Core\Auth;

// Load .env
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$tenants = require __DIR__ . '/../config/tenants.php';
$host = $_SERVER['HTTP_X_FORWARDED_HOST']
    ?? $_SERVER['HTTP_X_ORIGINAL_HOST']
    ?? $_SERVER['HTTP_HOST']
    ?? null;
if ($host && strpos($host, ',') !== false) {
    $host = trim(explode(',', $host)[0]);
}
$tenant = TenantResolver::resolveFromHost($tenants, $host);
if (!$tenant) {
    $localHosts = ['localhost', '127.0.0.1'];
    $normalizedHost = $host ? preg_replace('/:\\d+$/', '', strtolower($host)) : null;
    $fallbackSlug = $_ENV['DEFAULT_TENANT'] ?? 'paramascotasec';
    if ($normalizedHost && in_array($normalizedHost, $localHosts, true)) {
        $tenant = $tenants[$fallbackSlug] ?? null;
    }
}
if (!$tenant) {
    header('Content-Type: application/json');
    Response::error('Tenant no encontrado', 404, 'TENANT_NOT_FOUND');
    exit;
}
TenantContext::set($tenant);

$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Tenant');
header('Vary: Origin');
if ($origin && TenantContext::isOriginAllowed($origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: ' . ($tenant['app_url'] ?? '*'));
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if ($origin && !TenantContext::isOriginAllowed($origin)) {
        Response::error('Origen no permitido', 403, 'CORS_FORBIDDEN');
    }
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$router = new Router();

// Product Routes
$router->add('GET', '/api/products', 'ProductController@index');
$router->add('GET', '/api/products/{id}', 'ProductController@show');
$router->add('POST', '/api/products', 'ProductController@store');
$router->add('PUT', '/api/products/{id}', 'ProductController@update');
$router->add('DELETE', '/api/products/{id}', 'ProductController@destroy');

// User Routes
$router->add('GET', '/api/users', 'UserController@index');
$router->add('GET', '/api/user/addresses', 'UserController@getAddresses');
$router->add('PUT', '/api/user/addresses', 'UserController@updateAddresses');
$router->add('GET', '/api/user/profile', 'UserController@getProfile');
$router->add('PUT', '/api/user/profile', 'UserController@updateProfile');
$router->add('POST', '/api/auth/login', 'AuthController@login');
$router->add('POST', '/api/auth/register', 'AuthController@register');
$router->add('POST', '/api/auth/request-otp', 'AuthController@requestOtp');
$router->add('POST', '/api/auth/verify-otp', 'AuthController@verifyOtp');
$router->add('GET', '/api/auth/verify', 'AuthController@verify');

// Order Routes
$router->add('GET', '/api/orders', 'OrderController@index');
$router->add('GET', '/api/orders/my-orders', 'OrderController@myOrders'); // Specific route for user's orders
$router->add('GET', '/api/orders/{id}', 'OrderController@show');
$router->add('PATCH', '/api/orders/{id}/status', 'OrderController@updateStatus');
$router->add('GET', '/api/orders/{id}/invoice', 'OrderController@invoice');
$router->add('POST', '/api/orders', 'OrderController@store');
$router->add('POST', '/api/orders/quote', 'OrderController@quote');

// Admin Dashboard Routes
$router->add('GET', '/api/admin/dashboard/stats', 'DashboardController@stats');
$router->add('GET', '/api/admin/settings/tax', 'SettingsController@getVat');
$router->add('PUT', '/api/admin/settings/tax', 'SettingsController@updateVat');
$router->add('GET', '/api/settings/shipping', 'SettingsController@getShipping');
$router->add('GET', '/api/admin/settings/shipping', 'SettingsController@getShipping');
$router->add('PUT', '/api/admin/settings/shipping', 'SettingsController@updateShipping');
$router->add('GET', '/api/admin/settings/product-page', 'SettingsController@getProductPage');
$router->add('PUT', '/api/admin/settings/product-page', 'SettingsController@updateProductPage');
$router->add('GET', '/api/admin/settings/pricing-margins', 'SettingsController@getPricingMargins');
$router->add('PUT', '/api/admin/settings/pricing-margins', 'SettingsController@updatePricingMargins');
$router->add('GET', '/api/admin/settings/pricing-calc', 'SettingsController@getPricingCalc');
$router->add('PUT', '/api/admin/settings/pricing-calc', 'SettingsController@updatePricingCalc');
$router->add('GET', '/api/admin/settings/pricing-rules', 'SettingsController@getPricingRules');
$router->add('PUT', '/api/admin/settings/pricing-rules', 'SettingsController@updatePricingRules');
$router->add('GET', '/api/shipments', 'ShippingController@index');

// Reports (guide/example)
$router->add('GET', '/api/reports/recent-orders', 'ReportController@recentOrders');

// Health Route
$router->add('GET', '/api/health', 'HealthController@status');

// Global auth: all API requests require a valid token (except auth endpoints).
$publicPaths = [
    '/api/auth/login',
    '/api/auth/register',
    '/api/auth/request-otp',
    '/api/auth/verify-otp',
    '/api/auth/verify',
    '/api/products',
    '/api/products/',
    '/api/settings/shipping',
    '/api/orders/quote',
    '/api/health'
];

$isPublic = in_array($uri, $publicPaths, true) || str_starts_with($uri, '/api/products/');
$requiresAuth = str_starts_with($uri, '/api') && !$isPublic;
if ($requiresAuth) {
    Auth::validateRequestOrFail();
}

$router->dispatch($method, $uri);

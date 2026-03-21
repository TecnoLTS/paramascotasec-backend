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

header_remove('X-Powered-By');

$tenants = require __DIR__ . '/../config/tenants.php';
$host = null;
$rawHttpHost = $_SERVER['HTTP_HOST'] ?? null;
$normalizedHttpHost = is_string($rawHttpHost) ? preg_replace('/:\d+$/', '', strtolower(trim($rawHttpHost))) : null;
$isInternalBackendHost = is_string($normalizedHttpHost) && (
    $normalizedHttpHost === 'backend-web'
    || str_ends_with($normalizedHttpHost, '-backend-web')
);
$trustProxyHeaders = in_array(strtolower((string)($_ENV['TRUST_PROXY_HEADERS'] ?? 'false')), ['1', 'true', 'yes', 'on'], true)
    || $isInternalBackendHost;
$hostCandidates = [$rawHttpHost];
if ($trustProxyHeaders) {
    array_unshift(
        $hostCandidates,
        $_SERVER['HTTP_X_FORWARDED_HOST'] ?? null,
        $_SERVER['HTTP_X_ORIGINAL_HOST'] ?? null
    );
}
foreach ($hostCandidates as $candidate) {
    if (!is_string($candidate)) {
        continue;
    }
    $candidate = trim($candidate);
    if ($candidate !== '') {
        $host = $candidate;
        break;
    }
}
if ($host && strpos($host, ',') !== false) {
    $host = trim(explode(',', $host)[0]);
}
$tenant = TenantResolver::resolveFromHost($tenants, $host);
if (!$tenant) {
    $localHosts = ['localhost', '127.0.0.1'];
    $normalizedHost = $host ? preg_replace('/:\\d+$/', '', strtolower($host)) : null;
    $fallbackSlug = $_ENV['DEFAULT_TENANT'] ?? 'paramascotasec';
    $isInternalHost = is_string($normalizedHost) && (
        $normalizedHost === 'backend-web'
        || str_ends_with($normalizedHost, '-backend-web')
    );
    if ($normalizedHost && (in_array($normalizedHost, $localHosts, true) || filter_var($normalizedHost, FILTER_VALIDATE_IP) || $isInternalHost)) {
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
$isDev = in_array(strtolower((string)($_ENV['APP_ENV'] ?? 'production')), ['development', 'dev', 'local'], true);
$localHosts = ['localhost', '127.0.0.1'];
$normalizedHost = $host ? preg_replace('/:\\d+$/', '', strtolower($host)) : null;
$isLocalHostRequest = $normalizedHost && (in_array($normalizedHost, $localHosts, true) || (bool)filter_var($normalizedHost, FILTER_VALIDATE_IP));
$isLocalOrigin = false;
if ($origin) {
    $originHost = parse_url($origin, PHP_URL_HOST);
    if ($originHost) {
        $originHost = strtolower($originHost);
        $isLocalOrigin = in_array($originHost, ['localhost', '127.0.0.1'], true) || (bool)filter_var($originHost, FILTER_VALIDATE_IP);
    }
}
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Tenant');
header('Vary: Origin');
if ($origin && TenantContext::isOriginAllowed($origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} elseif ($origin && $isLocalOrigin && ($isDev || $isLocalHostRequest)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: ' . ($tenant['app_url'] ?? 'null'));
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if ($origin && !TenantContext::isOriginAllowed($origin) && !($isLocalOrigin && ($isDev || $isLocalHostRequest))) {
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
$router->add('PUT', '/api/user/password', 'UserController@updatePassword');
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
$router->add('GET', '/api/settings/store-status', 'SettingsController@getStoreStatus');
$router->add('GET', '/api/admin/settings/shipping', 'SettingsController@getShipping');
$router->add('PUT', '/api/admin/settings/shipping', 'SettingsController@updateShipping');
$router->add('GET', '/api/admin/settings/store-status', 'SettingsController@getStoreStatus');
$router->add('PUT', '/api/admin/settings/store-status', 'SettingsController@updateStoreStatus');
$router->add('GET', '/api/admin/settings/product-page', 'SettingsController@getProductPage');
$router->add('PUT', '/api/admin/settings/product-page', 'SettingsController@updateProductPage');
$router->add('GET', '/api/admin/settings/pricing-margins', 'SettingsController@getPricingMargins');
$router->add('PUT', '/api/admin/settings/pricing-margins', 'SettingsController@updatePricingMargins');
$router->add('GET', '/api/admin/settings/pricing-calc', 'SettingsController@getPricingCalc');
$router->add('PUT', '/api/admin/settings/pricing-calc', 'SettingsController@updatePricingCalc');
$router->add('GET', '/api/admin/settings/pricing-rules', 'SettingsController@getPricingRules');
$router->add('PUT', '/api/admin/settings/pricing-rules', 'SettingsController@updatePricingRules');
$router->add('GET', '/api/admin/settings/product-reference-data', 'SettingsController@getProductReferenceData');
$router->add('PUT', '/api/admin/settings/product-reference-data', 'SettingsController@updateProductReferenceData');
$router->add('GET', '/api/admin/discounts', 'DiscountController@index');
$router->add('POST', '/api/admin/discounts', 'DiscountController@store');
$router->add('GET', '/api/admin/discounts/audit', 'DiscountController@audit');
$router->add('GET', '/api/admin/discounts/{id}', 'DiscountController@show');
$router->add('PUT', '/api/admin/discounts/{id}', 'DiscountController@update');
$router->add('PATCH', '/api/admin/discounts/{id}/status', 'DiscountController@updateStatus');
$router->add('GET', '/api/admin/purchase-invoices', 'PurchaseInvoiceController@index');
$router->add('GET', '/api/admin/purchase-invoices/{id}', 'PurchaseInvoiceController@show');
$router->add('GET', '/api/admin/pos/shift/active', 'PosController@activeShift');
$router->add('GET', '/api/admin/pos/shifts', 'PosController@shifts');
$router->add('GET', '/api/admin/pos/movements', 'PosController@movements');
$router->add('GET', '/api/admin/pos/customer-by-document', 'PosController@customerByDocument');
$router->add('POST', '/api/admin/pos/shift/open', 'PosController@openShift');
$router->add('POST', '/api/admin/pos/shift/close', 'PosController@closeShift');
$router->add('POST', '/api/admin/pos/movements', 'PosController@addMovement');
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
    '/api/settings/store-status',
    '/api/orders/quote',
    '/api/health'
];

$isPublic = in_array($uri, $publicPaths, true) || str_starts_with($uri, '/api/products/');
$requiresAuth = str_starts_with($uri, '/api') && !$isPublic;
if ($requiresAuth) {
    Auth::validateRequestOrFail();
    $adminOnly = str_starts_with($uri, '/api/admin/')
        || str_starts_with($uri, '/api/reports/')
        || $uri === '/api/users'
        || $uri === '/api/shipments';
    if ($adminOnly) {
        Auth::requireAdmin();
    }
}

$router->dispatch($method, $uri);

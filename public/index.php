<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Core\Router;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Load .env
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$router = new Router();

// Product Routes
$router->add('GET', '/api/products', 'ProductController@index');
$router->add('GET', '/api/products/{id}', 'ProductController@show');
$router->add('POST', '/api/products', 'ProductController@store');
$router->add('PUT', '/api/products/{id}', 'ProductController@update');
$router->add('DELETE', '/api/products/{id}', 'ProductController@destroy');

// User Routes
$router->add('GET', '/api/users', 'UserController@index');
$router->add('POST', '/api/auth/login', 'AuthController@login');
$router->add('POST', '/api/auth/register', 'AuthController@register');

// Order Routes
$router->add('GET', '/api/orders', 'OrderController@index');
$router->add('GET', '/api/orders/my-orders', 'OrderController@myOrders'); // Specific route for user's orders
$router->add('GET', '/api/orders/{id}', 'OrderController@show');
$router->add('POST', '/api/orders', 'OrderController@store');

// Admin Dashboard Routes
$router->add('GET', '/api/admin/dashboard/stats', 'DashboardController@stats');
$router->add('GET', '/api/shipments', 'ShippingController@index');

// Health Route
$router->add('GET', '/api/health', 'HealthController@status');

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$router->dispatch($method, $uri);

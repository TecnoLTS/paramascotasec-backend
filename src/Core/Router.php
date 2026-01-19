<?php

namespace App\Core;

class Router {
    protected $routes = [];

    public function add($method, $path, $handler) {
        // Convert path like /api/products/{id} to regex
        $path = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[a-zA-Z0-9_-]+)', $path);
        $path = '#^' . $path . '$#';

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler
        ];
    }

    public function dispatch($method, $uri) {
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['path'], $uri, $matches)) {
                [$controller, $action] = explode('@', $route['handler']);
                $controllerClass = "App\\Controllers\\$controller";
                $instance = new $controllerClass();
                
                // Filter matches to get only named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                
                return call_user_func_array([$instance, $action], $params);
            }
        }
        
        http_response_code(404);
        echo json_encode(['error' => 'Route not found', 'uri' => $uri, 'method' => $method]);
    }
}

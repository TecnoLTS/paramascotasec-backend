<?php
header('Content-Type: application/json');
echo json_encode([
    'request_uri' => $_SERVER['REQUEST_URI'],
    'parsed_uri' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'query_string' => $_SERVER['QUERY_STRING'] ?? '',
    'script_name' => $_SERVER['SCRIPT_NAME'],
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'document_root' => $_SERVER['DOCUMENT_ROOT']
]);

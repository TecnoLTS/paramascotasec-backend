<?php

namespace App\Core;

class Response {
    public static function json($data = null, int $status = 200, ?array $meta = null, ?string $message = null): void {
        http_response_code($status);
        $payload = [
            'ok' => true,
            'data' => $data,
        ];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
        if ($message !== null) {
            $payload['message'] = $message;
        }
        echo json_encode($payload);
    }

    public static function error(string $message, int $status = 400, ?string $code = null, $details = null): void {
        http_response_code($status);
        $error = ['message' => $message];
        if ($code !== null) {
            $error['code'] = $code;
        }
        if ($details !== null) {
            $error['details'] = $details;
        }
        echo json_encode([
            'ok' => false,
            'error' => $error,
        ]);
    }
}

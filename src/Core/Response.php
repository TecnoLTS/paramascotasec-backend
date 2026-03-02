<?php

namespace App\Core;

class Response {
    private static function shouldExposeServerMessage(?string $code): bool {
        if ($code === null) {
            return false;
        }
        $allowed = [
            'STORE_SALES_DISABLED',
        ];
        return in_array($code, $allowed, true);
    }

    private static function isDevelopment(): bool {
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'production'));
        if ($env === 'development' || $env === 'dev' || $env === 'local') {
            return true;
        }
        $debug = strtolower((string)($_ENV['APP_DEBUG'] ?? 'false'));
        return in_array($debug, ['1', 'true', 'yes', 'on'], true);
    }

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
        $isDev = self::isDevelopment();
        if ($status >= 500 && !$isDev && !self::shouldExposeServerMessage($code)) {
            error_log(sprintf(
                '[API_ERROR] status=%d code=%s message=%s details=%s',
                $status,
                $code ?? 'N/A',
                $message,
                $details !== null ? json_encode($details) : 'null'
            ));
            $message = 'Error interno del servidor';
            $details = null;
        }

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

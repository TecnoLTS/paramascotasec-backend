<?php

namespace App\Services;

class FacturadorApiService {
    private string $baseUrl;
    private string $invoiceEndpoint;
    private int $timeoutSeconds;
    private string $apiKey;

    public function __construct(?string $baseUrl = null, ?int $timeoutSeconds = null) {
        $resolvedBaseUrl = rtrim((string)($baseUrl ?? ($_ENV['FACTURADOR_API_URL'] ?? getenv('FACTURADOR_API_URL') ?: 'http://facturador')), '/');
        if ($resolvedBaseUrl === '') {
            throw new \RuntimeException('FACTURADOR_API_URL no configurado');
        }

        $this->baseUrl = $resolvedBaseUrl;
        $configuredInvoicesPath = trim((string)($_ENV['FACTURADOR_API_INVOICES_PATH'] ?? getenv('FACTURADOR_API_INVOICES_PATH') ?: ''));
        if (preg_match('#/invoices/?$#', $resolvedBaseUrl) === 1) {
            $this->invoiceEndpoint = $resolvedBaseUrl;
        } else {
            $invoicesPath = $configuredInvoicesPath !== ''
                ? $configuredInvoicesPath
                : $this->defaultInvoicesPath();
            $this->invoiceEndpoint = $resolvedBaseUrl . $this->normalizeInvoicesPath($invoicesPath);
        }
        $this->timeoutSeconds = max(1, (int)($timeoutSeconds ?? ($_ENV['FACTURADOR_TIMEOUT'] ?? getenv('FACTURADOR_TIMEOUT') ?: 20)));
        $this->apiKey = trim((string)($_ENV['FACTURADOR_API_KEY'] ?? getenv('FACTURADOR_API_KEY') ?: ''));
        if ($this->apiKey === '') {
            throw new \RuntimeException('FACTURADOR_API_KEY no configurado');
        }
    }

    public function emitInvoice(array $payload): array {
        $requestBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($requestBody === false) {
            throw new \RuntimeException('No se pudo serializar la solicitud para facturador');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-API-Key: ' . $this->apiKey,
                    'Content-Length: ' . strlen($requestBody),
                ]),
                'content' => $requestBody,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($this->invoiceEndpoint, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $statusCode = $this->extractStatusCode($responseHeaders);

        if ($responseBody === false && $statusCode === 0) {
            throw new \RuntimeException('No fue posible conectar con el facturador');
        }

        $decoded = is_string($responseBody) && $responseBody !== '' ? json_decode($responseBody, true) : null;
        if ($statusCode >= 200 && $statusCode < 300 && is_array($decoded) && ($decoded['success'] ?? false) === true && is_array($decoded['data'] ?? null)) {
            return $decoded['data'];
        }

        $message = null;
        if (is_array($decoded)) {
            $message = $decoded['error']['message'] ?? $decoded['message'] ?? null;
        }
        if (!is_string($message) || trim($message) === '') {
            $message = 'Respuesta inválida del facturador';
        }

        throw new \RuntimeException(sprintf('Facturador respondió con error (%d): %s', $statusCode ?: 500, $message));
    }

    private function extractStatusCode(array $headers): int {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$header, $matches) === 1) {
                return (int)$matches[1];
            }
        }

        return 0;
    }

    private function normalizeInvoicesPath(string $path): string
    {
        $normalized = '/' . ltrim(trim($path), '/');
        return rtrim($normalized, '/');
    }

    private function defaultInvoicesPath(): string
    {
        $env = strtolower(trim((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production')));
        if (in_array($env, ['development', 'dev', 'local'], true)) {
            return '/api/test/v1/invoices';
        }

        return '/api/production/v1/invoices';
    }
}

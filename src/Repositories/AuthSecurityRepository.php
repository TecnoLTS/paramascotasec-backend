<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;

class AuthSecurityRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function recordEvent(
        string $eventType,
        string $status = 'info',
        ?string $userId = null,
        ?string $email = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $metadata = []
    ): void {
        $stmt = $this->db->prepare('
            INSERT INTO "AuthSecurityEvent" (
                id,
                tenant_id,
                user_id,
                email,
                event_type,
                status,
                ip_address,
                user_agent,
                metadata,
                created_at
            ) VALUES (
                :id,
                :tenant_id,
                :user_id,
                :email,
                :event_type,
                :status,
                :ip_address,
                :user_agent,
                :metadata,
                NOW()
            )
        ');

        $stmt->execute([
            'id' => bin2hex(random_bytes(10)),
            'tenant_id' => $this->getTenantId(),
            'user_id' => $userId,
            'email' => $email ? strtolower(trim($email)) : null,
            'event_type' => trim($eventType),
            'status' => trim($status) !== '' ? trim($status) : 'info',
            'ip_address' => $ipAddress ? trim($ipAddress) : null,
            'user_agent' => $userAgent ? trim($userAgent) : null,
            'metadata' => !empty($metadata)
                ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : '{}',
        ]);
    }

    private function getTenantId(): string {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }
}

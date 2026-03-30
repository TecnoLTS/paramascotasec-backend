<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;

class ContactMessageRepository
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create(array $payload): array
    {
        $id = $payload['id'] ?? ('contact_' . bin2hex(random_bytes(8)));
        $stmt = $this->db->prepare('
            INSERT INTO "ContactMessage" (
                id,
                tenant_id,
                name,
                email,
                phone,
                subject,
                message,
                source,
                status,
                ip_address,
                user_agent,
                metadata
            ) VALUES (
                :id,
                :tenant_id,
                :name,
                :email,
                :phone,
                :subject,
                :message,
                :source,
                :status,
                :ip_address,
                :user_agent,
                :metadata
            )
            RETURNING id, name, email, phone, subject, message, source, status, created_at
        ');

        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'name' => $payload['name'],
            'email' => $payload['email'],
            'phone' => $payload['phone'] ?: null,
            'subject' => $payload['subject'],
            'message' => $payload['message'],
            'source' => $payload['source'] ?? 'web',
            'status' => $payload['status'] ?? 'new',
            'ip_address' => $payload['ip_address'] ?? null,
            'user_agent' => $payload['user_agent'] ?? null,
            'metadata' => json_encode($payload['metadata'] ?? new \stdClass()),
        ]);

        return $stmt->fetch() ?: ['id' => $id];
    }

    private function getTenantId(): string
    {
        $tenant = TenantContext::get();
        return (string)($tenant['id'] ?? 'default');
    }
}

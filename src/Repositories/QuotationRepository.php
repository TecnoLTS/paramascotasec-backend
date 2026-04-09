<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use PDO;

class QuotationRepository {
    private $db;
    private static $schemaEnsured = false;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureSchema();
    }

    private function ensureSchema(): void {
        if (self::$schemaEnsured) {
            return;
        }

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS "Quotation" (
                id varchar(64) PRIMARY KEY,
                tenant_id varchar(120) NOT NULL,
                status varchar(24) NOT NULL DEFAULT \'quoted\',
                customer_name text NOT NULL,
                customer_document_type text NULL,
                customer_document_number text NULL,
                customer_email text NULL,
                customer_phone text NULL,
                customer_address jsonb NULL,
                delivery_method text NOT NULL DEFAULT \'pickup\',
                payment_method text NULL,
                discount_code text NULL,
                notes text NULL,
                items jsonb NOT NULL DEFAULT \'[]\'::jsonb,
                quote_snapshot jsonb NOT NULL DEFAULT \'{}\'::jsonb,
                created_by_user_id varchar(64) NULL,
                converted_order_id varchar(64) NULL,
                valid_until timestamptz NULL,
                converted_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT NOW(),
                updated_at timestamptz NOT NULL DEFAULT NOW()
            )
        ');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_quotation_tenant_created ON "Quotation"(tenant_id, created_at DESC)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_quotation_tenant_status ON "Quotation"(tenant_id, status, created_at DESC)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_quotation_tenant_converted_order ON "Quotation"(tenant_id, converted_order_id)');

        self::$schemaEnsured = true;
    }

    private function getTenantId(): string {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }

    private function decodeJson($value, array $fallback = []): array {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return $fallback;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $fallback;
    }

    private function normalizeRow(array $row): array {
        $row['customer_address'] = $this->decodeJson($row['customer_address'] ?? null);
        $row['items'] = $this->decodeJson($row['items'] ?? null);
        $row['quote_snapshot'] = $this->decodeJson($row['quote_snapshot'] ?? null);

        $items = is_array($row['items']) ? $row['items'] : [];
        $row['item_count'] = count($items);
        $row['units'] = array_reduce($items, static function (int $carry, $item): int {
            return $carry + max(0, (int)($item['quantity'] ?? 0));
        }, 0);

        return $row;
    }

    public function listRecent(int $limit = 20): array {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare('
            SELECT *
            FROM "Quotation"
            WHERE tenant_id = :tenant_id
            ORDER BY created_at DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':tenant_id', $this->getTenantId());
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn(array $row) => $this->normalizeRow($row), $stmt->fetchAll() ?: []);
    }

    public function getById(string $id): ?array {
        $stmt = $this->db->prepare('
            SELECT *
            FROM "Quotation"
            WHERE id = :id AND tenant_id = :tenant_id
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
        ]);
        $row = $stmt->fetch();
        return $row ? $this->normalizeRow($row) : null;
    }

    public function create(array $data): array {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            throw new \Exception('ID de cotización requerido.');
        }

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $quoteSnapshot = is_array($data['quote_snapshot'] ?? null) ? $data['quote_snapshot'] : [];
        $customerAddress = is_array($data['customer_address'] ?? null) ? $data['customer_address'] : [];
        $validUntil = $data['valid_until'] ?? null;
        $validUntilValue = null;
        if (is_string($validUntil) && trim($validUntil) !== '') {
            $validUntilValue = trim($validUntil);
        }

        $stmt = $this->db->prepare('
            INSERT INTO "Quotation" (
                id,
                tenant_id,
                status,
                customer_name,
                customer_document_type,
                customer_document_number,
                customer_email,
                customer_phone,
                customer_address,
                delivery_method,
                payment_method,
                discount_code,
                notes,
                items,
                quote_snapshot,
                created_by_user_id,
                valid_until,
                created_at,
                updated_at
            ) VALUES (
                :id,
                :tenant_id,
                :status,
                :customer_name,
                :customer_document_type,
                :customer_document_number,
                :customer_email,
                :customer_phone,
                :customer_address,
                :delivery_method,
                :payment_method,
                :discount_code,
                :notes,
                :items,
                :quote_snapshot,
                :created_by_user_id,
                :valid_until,
                NOW(),
                NOW()
            )
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'status' => trim((string)($data['status'] ?? 'quoted')) ?: 'quoted',
            'customer_name' => trim((string)($data['customer_name'] ?? 'Cliente')),
            'customer_document_type' => $data['customer_document_type'] ?? null,
            'customer_document_number' => $data['customer_document_number'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null,
            'customer_address' => json_encode($customerAddress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'delivery_method' => trim((string)($data['delivery_method'] ?? 'pickup')) ?: 'pickup',
            'payment_method' => $data['payment_method'] ?? null,
            'discount_code' => $data['discount_code'] ?? null,
            'notes' => $data['notes'] ?? null,
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'quote_snapshot' => json_encode($quoteSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
            'valid_until' => $validUntilValue,
        ]);

        $created = $this->getById($id);
        if (!$created) {
            throw new \Exception('No se pudo guardar la cotización.');
        }
        return $created;
    }

    public function markConverted(string $id, string $orderId): ?array {
        $stmt = $this->db->prepare('
            UPDATE "Quotation"
            SET status = :status,
                converted_order_id = :converted_order_id,
                converted_at = NOW(),
                updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'status' => 'converted',
            'converted_order_id' => $orderId,
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
        ]);

        return $this->getById($id);
    }
}

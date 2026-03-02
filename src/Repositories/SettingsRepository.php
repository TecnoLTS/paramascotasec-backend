<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;

class SettingsRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function get($key) {
        $stmt = $this->db->prepare('SELECT value FROM "Setting" WHERE key = :key');
        $stmt->execute(['key' => $this->scopedKey($key)]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : null;
    }

    public function set($key, $value) {
        $scopedKey = $this->scopedKey($key);
        $stmt = $this->db->prepare('INSERT INTO "Setting" (key, value, tenant_id) VALUES (:key, :value, :tenant_id) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, tenant_id = EXCLUDED.tenant_id');
        $stmt->execute([
            'key' => $scopedKey,
            'value' => $value,
            'tenant_id' => $this->getTenantId()
        ]);
        return $this->get($key);
    }

    private function scopedKey($key) {
        $tenantId = $this->getTenantId();
        return $tenantId ? ($tenantId . ':' . $key) : $key;
    }

    private function getTenantId() {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }
}

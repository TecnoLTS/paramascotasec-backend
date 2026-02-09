<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;

class SettingsRepository {
    private $db;
    private $hasTenantColumn = false;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureTable();
        $this->ensureTenantColumn();
    }

    private function ensureTable() {
        $this->db->exec('CREATE TABLE IF NOT EXISTS "Setting" (key text PRIMARY KEY, value text NOT NULL)');
    }

    public function get($key) {
        $stmt = $this->db->prepare('SELECT value FROM "Setting" WHERE key = :key');
        $stmt->execute(['key' => $this->scopedKey($key)]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : null;
    }

    public function set($key, $value) {
        $scopedKey = $this->scopedKey($key);
        if ($this->hasTenantColumn) {
            $stmt = $this->db->prepare('INSERT INTO "Setting" (key, value, tenant_id) VALUES (:key, :value, :tenant_id) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, tenant_id = EXCLUDED.tenant_id');
            $stmt->execute([
                'key' => $scopedKey,
                'value' => $value,
                'tenant_id' => $this->getTenantId()
            ]);
        } else {
            $stmt = $this->db->prepare('INSERT INTO "Setting" (key, value) VALUES (:key, :value) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value');
            $stmt->execute([
                'key' => $scopedKey,
                'value' => $value
            ]);
        }
        return $this->get($key);
    }

    private function scopedKey($key) {
        $tenantId = $this->getTenantId();
        return $tenantId ? ($tenantId . ':' . $key) : $key;
    }

    private function ensureTenantColumn() {
        $check = $this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'Setting' AND column_name = 'tenant_id'");
        $check->execute();
        if ($check->fetch()) {
            $this->hasTenantColumn = true;
            return;
        }
        $this->db->exec('ALTER TABLE "Setting" ADD COLUMN IF NOT EXISTS tenant_id text');
        $this->hasTenantColumn = true;
    }

    private function getTenantId() {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }
}

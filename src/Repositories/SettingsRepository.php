<?php

namespace App\Repositories;

use App\Core\Database;

class SettingsRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureTable();
    }

    private function ensureTable() {
        $this->db->exec('CREATE TABLE IF NOT EXISTS "Setting" (key text PRIMARY KEY, value text NOT NULL)');
    }

    public function get($key) {
        $stmt = $this->db->prepare('SELECT value FROM "Setting" WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : null;
    }

    public function set($key, $value) {
        $stmt = $this->db->prepare('INSERT INTO "Setting" (key, value) VALUES (:key, :value) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value');
        $stmt->execute([
            'key' => $key,
            'value' => $value
        ]);
        return $this->get($key);
    }
}

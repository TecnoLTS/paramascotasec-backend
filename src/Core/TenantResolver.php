<?php

namespace App\Core;

class TenantResolver {
    public static function resolveFromHost(array $tenants, ?string $host): ?array {
        if (!$host) {
            return null;
        }
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host);
        foreach ($tenants as $tenant) {
            $domains = $tenant['domains'] ?? [];
            foreach ($domains as $domain) {
                if ($host === strtolower($domain)) {
                    return $tenant;
                }
            }
        }
        return null;
    }
}

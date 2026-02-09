<?php

namespace App\Core;

use PDO;
use PDOException;
use App\Core\TenantContext;

class Database {
    private static $instances = [];
    private $connection;

    private function __construct(array $config) {
        $host = $config['host'];
        $port = $config['port'];
        $db   = $config['database'];
        $user = $config['username'];
        $pass = $config['password'];

        $dsn = "pgsql:host=$host;port=$port;dbname=$db";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->connection = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    private static function resolveConfig(): array {
        $tenant = TenantContext::get();
        $tenantDb = $tenant['db'] ?? [];

        $host = $tenantDb['host'] ?? ($_ENV['DB_HOST'] ?? 'localhost');
        $port = $tenantDb['port'] ?? ($_ENV['DB_PORT'] ?? 5432);
        $database = $tenantDb['database'] ?? ($_ENV['DB_DATABASE'] ?? 'paramascotasec');
        $username = $tenantDb['username'] ?? ($_ENV['DB_USERNAME'] ?? 'postgres');
        $password = $tenantDb['password'] ?? ($_ENV['DB_PASSWORD'] ?? '');

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password
        ];
    }

    public static function getInstance() {
        $config = self::resolveConfig();
        $key = implode('|', [
            $config['host'],
            $config['port'],
            $config['database'],
            $config['username']
        ]);
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($config);
        }
        return self::$instances[$key]->connection;
    }
}

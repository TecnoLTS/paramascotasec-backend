<?php

namespace App\Repositories;

use App\Core\Database;

class UserRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll() {
        $stmt = $this->db->query('SELECT id, name, email, role FROM "User"');
        return $stmt->fetchAll();
    }

    public function getByEmail($email) {
        $stmt = $this->db->prepare('SELECT id, name, email, password, email_verified, role FROM "User" WHERE email = :email');
        $stmt->execute(['email' => $email]);
        return $stmt->fetch();
    }

    public function create($data) {
        $sql = 'INSERT INTO "User" (id, name, email, password, updated_at, verification_token) VALUES (:id, :name, :email, :password, NOW(), :token)';
        $stmt = $this->db->prepare($sql);
        $id = bin2hex(random_bytes(10));
        $token = bin2hex(random_bytes(32));
        $stmt->execute([
            'id' => $id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'token' => $token
        ]);
        return ['id' => $id, 'token' => $token];
    }

    public function verifyToken($token) {
        $stmt = $this->db->prepare('UPDATE "User" SET email_verified = TRUE, verification_token = NULL WHERE verification_token = :token RETURNING id');
        $stmt->execute(['token' => $token]);
        return $stmt->fetch();
    }

    public function getNewUsersCount() {
        $stmt = $this->db->query('SELECT COUNT(*) as count FROM "User" WHERE created_at >= NOW() - INTERVAL \'7 days\'');
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }

    public function getClientsProgress() {
        $thisWeek = $this->db->query('SELECT COUNT(*) FROM "User" WHERE created_at >= DATE_TRUNC(\'week\', NOW())')->fetchColumn() ?: 0;
        $lastWeek = $this->db->query('SELECT COUNT(*) FROM "User" WHERE created_at >= DATE_TRUNC(\'week\', NOW() - INTERVAL \'1 week\') AND created_at < DATE_TRUNC(\'week\', NOW())')->fetchColumn() ?: 0;
        
        $percentage = $lastWeek > 0 ? (($thisWeek - $lastWeek) / $lastWeek) * 100 : 100;
        return [
            'current' => $thisWeek,
            'previous' => $lastWeek,
            'percentage' => round($percentage, 1)
        ];
    }
}

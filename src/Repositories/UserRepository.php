<?php

namespace App\Repositories;

use App\Core\Database;

class UserRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll() {
        $stmt = $this->db->query('SELECT "id", "name", "email", "role" FROM "User"');
        return $stmt->fetchAll();
    }

    public function getByEmail($email) {
        $stmt = $this->db->prepare('SELECT "id", "name", "email", "password", "emailVerified", "role" FROM "User" WHERE "email" = :email');
        $stmt->execute(['email' => $email]);
        return $stmt->fetch();
    }

    public function create($data) {
        $sql = 'INSERT INTO "User" ("id", "name", "email", "password", "updatedAt", "verificationToken") VALUES (:id, :name, :email, :password, NOW(), :token)';
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
        $stmt = $this->db->prepare('UPDATE "User" SET "emailVerified" = TRUE, "verificationToken" = NULL WHERE "verificationToken" = :token RETURNING id');
        $stmt->execute(['token' => $token]);
        return $stmt->fetch();
    }

    public function getNewUsersCount() {
        // Last 7 days? Or "This Week"
        $stmt = $this->db->query('SELECT COUNT(*) as count FROM "User" WHERE "updatedAt" >= NOW() - INTERVAL \'7 days\''); // Using updatedAt as createdAt approximate or assuming similar
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }
}

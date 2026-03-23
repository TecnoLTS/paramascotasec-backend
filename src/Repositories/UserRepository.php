<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;

class UserRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll() {
        $stmt = $this->db->prepare('
            SELECT
                u.id,
                u.name,
                u.email,
                u.role,
                u.email_verified,
                u.document_type,
                u.document_number,
                u.business_name,
                u.profile,
                u.addresses,
                u.created_at,
                u.updated_at,
                COALESCE(stats.orders_total, 0)::int AS orders_total,
                COALESCE(stats.orders_active, 0)::int AS orders_active,
                COALESCE(stats.orders_completed, 0)::int AS orders_completed,
                COALESCE(stats.total_spent, 0)::numeric(12,2) AS total_spent,
                stats.last_order_at,
                stats.last_order_id,
                o_last.shipping_address AS last_shipping_address,
                o_last.billing_address AS last_billing_address
            FROM "User" u
            LEFT JOIN (
                SELECT
                    o.user_id,
                    COUNT(*) AS orders_total,
                    COUNT(*) FILTER (
                        WHERE LOWER(COALESCE(o.status, \'pending\')) NOT IN (\'canceled\', \'cancelled\')
                    ) AS orders_active,
                    COUNT(*) FILTER (
                        WHERE LOWER(COALESCE(o.status, \'pending\')) IN (\'completed\', \'delivered\')
                    ) AS orders_completed,
                    SUM(
                        CASE
                            WHEN LOWER(COALESCE(o.status, \'pending\')) NOT IN (\'canceled\', \'cancelled\')
                            THEN COALESCE(o.total, 0)
                            ELSE 0
                        END
                    ) AS total_spent,
                    MAX(o.created_at) AS last_order_at,
                    (ARRAY_AGG(o.id ORDER BY o.created_at DESC))[1] AS last_order_id
                FROM "Order" o
                WHERE o.tenant_id = :tenant_id_orders
                GROUP BY o.user_id
            ) stats ON stats.user_id = u.id
            LEFT JOIN "Order" o_last
                ON o_last.id = stats.last_order_id
                AND o_last.tenant_id = :tenant_id_orders_latest
            WHERE u.tenant_id = :tenant_id_users
            ORDER BY u.created_at DESC, u.name ASC
        ');
        $tenantId = $this->getTenantId();
        $stmt->execute([
            'tenant_id_orders' => $tenantId,
            'tenant_id_orders_latest' => $tenantId,
            'tenant_id_users' => $tenantId
        ]);
        return $stmt->fetchAll();
    }

    public function getByEmail($email) {
        $stmt = $this->db->prepare('SELECT id, name, email, password, email_verified, role FROM "User" WHERE email = :email AND tenant_id = :tenant_id');
        $stmt->execute([
            'email' => $email,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function getByEmailWithOtp($email) {
        $stmt = $this->db->prepare('SELECT id, name, email, password, email_verified, role, otp_code, otp_expires_at, otp_attempts FROM "User" WHERE email = :email AND tenant_id = :tenant_id');
        $stmt->execute([
            'email' => $email,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function getById($id) {
        $stmt = $this->db->prepare('SELECT id, name, email, role FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function getAdminUserById($id) {
        $stmt = $this->db->prepare('
            SELECT
                id,
                name,
                email,
                role,
                email_verified,
                document_type,
                document_number,
                business_name,
                profile,
                addresses,
                created_at,
                updated_at
            FROM "User"
            WHERE id = :id AND tenant_id = :tenant_id
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function emailExists(string $email, ?string $excludeId = null): bool {
        $sql = 'SELECT 1 FROM "User" WHERE email = :email AND tenant_id = :tenant_id';
        $params = [
            'email' => $email,
            'tenant_id' => $this->getTenantId()
        ];

        if ($excludeId) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    public function getAuthState($id) {
        $stmt = $this->db->prepare('SELECT id, role, active_token_id FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function getAddresses($userId) {
        $stmt = $this->db->prepare('SELECT addresses FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        $row = $stmt->fetch();
        return $row ? $row['addresses'] : null;
    }

    public function updateAddresses($userId, $addresses) {
        $stmt = $this->db->prepare('UPDATE "User" SET addresses = :addresses, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'addresses' => json_encode($addresses)
        ]);
        return $this->getAddresses($userId);
    }

    public function getProfile($userId) {
        $stmt = $this->db->prepare('SELECT name, profile, document_type, document_number, business_name FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function updateProfile($userId, $name, $profile) {
        $docType = $profile['documentType'] ?? ($profile['document_type'] ?? null);
        $docNumber = $profile['documentNumber'] ?? ($profile['document_number'] ?? null);
        $businessName = $profile['businessName'] ?? ($profile['business_name'] ?? ($profile['company'] ?? null));
        $stmt = $this->db->prepare('UPDATE "User" SET name = :name, profile = :profile, document_type = :document_type, document_number = :document_number, business_name = :business_name, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'name' => $name,
            'profile' => json_encode($profile),
            'document_type' => $docType,
            'document_number' => $docNumber,
            'business_name' => $businessName
        ]);
        return $this->getProfile($userId);
    }

    public function getPasswordHash($userId) {
        $stmt = $this->db->prepare('SELECT password FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        $row = $stmt->fetch();
        return $row ? $row['password'] : null;
    }

    public function updatePassword($userId, $newPasswordHash, $newTokenId) {
        $stmt = $this->db->prepare('UPDATE "User" SET password = :password, active_token_id = :token_id, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'password' => $newPasswordHash,
            'token_id' => $newTokenId
        ]);
    }

    public function setOtpForEmail($email, $code, $expiresAt) {
        $stmt = $this->db->prepare('UPDATE "User" SET otp_code = :code, otp_expires_at = :expires_at, otp_attempts = 0, updated_at = NOW() WHERE email = :email AND tenant_id = :tenant_id');
        $stmt->execute([
            'email' => $email,
            'tenant_id' => $this->getTenantId(),
            'code' => $code,
            'expires_at' => $expiresAt
        ]);
    }

    public function markEmailVerifiedByOtp($userId) {
        $stmt = $this->db->prepare('UPDATE "User" SET email_verified = TRUE, verification_token = NULL, otp_code = NULL, otp_expires_at = NULL, otp_attempts = 0, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        return $this->getById($userId);
    }

    public function incrementOtpAttempts($userId) {
        $stmt = $this->db->prepare('UPDATE "User" SET otp_attempts = COALESCE(otp_attempts, 0) + 1 WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
    }

    public function setActiveTokenId($userId, $tokenId) {
        $stmt = $this->db->prepare('UPDATE "User" SET active_token_id = :tokenId, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'tokenId' => $tokenId
        ]);
    }

    public function getActiveTokenId($userId) {
        $stmt = $this->db->prepare('SELECT active_token_id FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        $row = $stmt->fetch();
        return $row ? $row['active_token_id'] : null;
    }

    public function create($data, $options = []) {
        $skipToken = (bool)($options['skip_verification_token'] ?? false);
        $sql = 'INSERT INTO "User" (id, tenant_id, name, email, password, updated_at, verification_token, document_type, document_number, business_name) VALUES (:id, :tenant_id, :name, :email, :password, NOW(), :token, :document_type, :document_number, :business_name)';
        $stmt = $this->db->prepare($sql);
        $id = bin2hex(random_bytes(10));
        $token = $skipToken ? null : bin2hex(random_bytes(32));
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'token' => $token,
            'document_type' => $data['document_type'] ?? null,
            'document_number' => $data['document_number'] ?? null,
            'business_name' => $data['business_name'] ?? null
        ]);
        return ['id' => $id, 'token' => $token];
    }

    public function createManaged(array $data) {
        $id = bin2hex(random_bytes(10));
        $stmt = $this->db->prepare('
            INSERT INTO "User" (
                id,
                tenant_id,
                name,
                email,
                password,
                role,
                email_verified,
                verification_token,
                document_type,
                document_number,
                business_name,
                profile,
                updated_at
            ) VALUES (
                :id,
                :tenant_id,
                :name,
                :email,
                :password,
                :role,
                :email_verified,
                NULL,
                :document_type,
                :document_number,
                :business_name,
                :profile,
                NOW()
            )
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'role' => $data['role'],
            'email_verified' => !empty($data['email_verified']),
            'document_type' => $data['document_type'] ?? null,
            'document_number' => $data['document_number'] ?? null,
            'business_name' => $data['business_name'] ?? null,
            'profile' => json_encode($data['profile'] ?? (object)[]),
        ]);

        return $this->getAdminUserById($id);
    }

    public function updateManaged(string $id, array $data) {
        $fields = [
            'name = :name',
            'email = :email',
            'role = :role',
            'email_verified = :email_verified',
            'verification_token = NULL',
            'document_type = :document_type',
            'document_number = :document_number',
            'business_name = :business_name',
            'profile = :profile',
            'updated_at = NOW()',
        ];

        $params = [
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'email_verified' => !empty($data['email_verified']),
            'document_type' => $data['document_type'] ?? null,
            'document_number' => $data['document_number'] ?? null,
            'business_name' => $data['business_name'] ?? null,
            'profile' => json_encode($data['profile'] ?? (object)[]),
        ];

        if (!empty($data['password'])) {
            $fields[] = 'password = :password';
            $fields[] = 'active_token_id = :active_token_id';
            $params['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            $params['active_token_id'] = bin2hex(random_bytes(16));
        }

        $sql = sprintf(
            'UPDATE "User" SET %s WHERE id = :id AND tenant_id = :tenant_id',
            implode(', ', $fields)
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $this->getAdminUserById($id);
    }

    public function verifyToken($token) {
        $stmt = $this->db->prepare('UPDATE "User" SET email_verified = TRUE, verification_token = NULL WHERE verification_token = :token AND tenant_id = :tenant_id RETURNING id');
        $stmt->execute([
            'token' => $token,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function markEmailVerifiedById($id) {
        $stmt = $this->db->prepare('UPDATE "User" SET email_verified = TRUE, verification_token = NULL WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
        return $this->getById($id);
    }

    public function getNewUsersCount() {
        $stmt = $this->db->prepare('SELECT COUNT(*) as count FROM "User" WHERE tenant_id = :tenant_id AND created_at >= NOW() - INTERVAL \'7 days\'');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }

    public function getClientsProgress() {
        $stmtThis = $this->db->prepare('SELECT COUNT(*) FROM "User" WHERE tenant_id = :tenant_id AND created_at >= DATE_TRUNC(\'week\', NOW())');
        $stmtThis->execute(['tenant_id' => $this->getTenantId()]);
        $thisWeek = $stmtThis->fetchColumn() ?: 0;
        $stmtLast = $this->db->prepare('SELECT COUNT(*) FROM "User" WHERE tenant_id = :tenant_id AND created_at >= DATE_TRUNC(\'week\', NOW() - INTERVAL \'1 week\') AND created_at < DATE_TRUNC(\'week\', NOW())');
        $stmtLast->execute(['tenant_id' => $this->getTenantId()]);
        $lastWeek = $stmtLast->fetchColumn() ?: 0;
        
        $percentage = $lastWeek > 0
            ? (($thisWeek - $lastWeek) / $lastWeek) * 100
            : ($thisWeek > 0 ? 100 : 0);
        return [
            'current' => $thisWeek,
            'previous' => $lastWeek,
            'percentage' => round($percentage, 1)
        ];
    }

    private function getTenantId() {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }
}

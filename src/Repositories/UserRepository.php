<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;

class UserRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureTenantColumn();
    }

    public function getAll() {
        $stmt = $this->db->prepare('SELECT id, name, email, role FROM "User" WHERE tenant_id = :tenant_id');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
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
        $this->ensureOtpColumns();
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

    public function getAddresses($userId) {
        $this->ensureAddressesColumn();
        $stmt = $this->db->prepare('SELECT addresses FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        $row = $stmt->fetch();
        return $row ? $row['addresses'] : null;
    }

    public function updateAddresses($userId, $addresses) {
        $this->ensureAddressesColumn();
        $stmt = $this->db->prepare('UPDATE "User" SET addresses = :addresses, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'addresses' => json_encode($addresses)
        ]);
        return $this->getAddresses($userId);
    }

    private function ensureAddressesColumn() {
        $check = $this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'User' AND column_name = 'addresses'");
        $check->execute();
        if ($check->fetch()) {
            return;
        }
        $this->db->exec('ALTER TABLE "User" ADD COLUMN IF NOT EXISTS addresses jsonb');
    }

    public function getProfile($userId) {
        $this->ensureProfileColumn();
        $this->ensureIdentityColumns();
        $stmt = $this->db->prepare('SELECT name, profile, document_type, document_number, business_name FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function updateProfile($userId, $name, $profile) {
        $this->ensureProfileColumn();
        $this->ensureIdentityColumns();
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

    private function ensureProfileColumn() {
        $check = $this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'User' AND column_name = 'profile'");
        $check->execute();
        if ($check->fetch()) {
            return;
        }
        $this->db->exec('ALTER TABLE "User" ADD COLUMN IF NOT EXISTS profile jsonb');
    }

    private function ensureIdentityColumns() {
        $check = $this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'User' AND column_name = 'document_type'");
        $check->execute();
        if ($check->fetch()) {
            return;
        }
        $this->db->exec('ALTER TABLE "User" ADD COLUMN IF NOT EXISTS document_type text');
        $this->db->exec('ALTER TABLE "User" ADD COLUMN IF NOT EXISTS document_number text');
        $this->db->exec('ALTER TABLE "User" ADD COLUMN IF NOT EXISTS business_name text');
    }

    public function setOtpForEmail($email, $code, $expiresAt) {
        $this->ensureOtpColumns();
        $stmt = $this->db->prepare('UPDATE "User" SET otp_code = :code, otp_expires_at = :expires_at, otp_attempts = 0, updated_at = NOW() WHERE email = :email AND tenant_id = :tenant_id');
        $stmt->execute([
            'email' => $email,
            'tenant_id' => $this->getTenantId(),
            'code' => $code,
            'expires_at' => $expiresAt
        ]);
    }

    public function markEmailVerifiedByOtp($userId) {
        $this->ensureOtpColumns();
        $stmt = $this->db->prepare('UPDATE "User" SET email_verified = TRUE, verification_token = NULL, otp_code = NULL, otp_expires_at = NULL, otp_attempts = 0, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        return $this->getById($userId);
    }

    public function incrementOtpAttempts($userId) {
        $this->ensureOtpColumns();
        $stmt = $this->db->prepare('UPDATE "User" SET otp_attempts = COALESCE(otp_attempts, 0) + 1 WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
    }

    private function ensureOtpColumns() {
        $check = $this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'User' AND column_name = 'otp_code'");
        $check->execute();
        if ($check->fetch()) {
            return;
        }
        $this->db->exec('ALTER TABLE "User" ADD COLUMN IF NOT EXISTS otp_code text');
        $this->db->exec('ALTER TABLE "User" ADD COLUMN IF NOT EXISTS otp_expires_at timestamp');
        $this->db->exec('ALTER TABLE "User" ADD COLUMN IF NOT EXISTS otp_attempts integer');
    }

    public function setActiveTokenId($userId, $tokenId) {
        $this->ensureActiveTokenColumn();
        $stmt = $this->db->prepare('UPDATE "User" SET active_token_id = :tokenId, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'tokenId' => $tokenId
        ]);
    }

    public function getActiveTokenId($userId) {
        $this->ensureActiveTokenColumn();
        $stmt = $this->db->prepare('SELECT active_token_id FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        $row = $stmt->fetch();
        return $row ? $row['active_token_id'] : null;
    }

    private function ensureActiveTokenColumn() {
        $check = $this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'User' AND column_name = 'active_token_id'");
        $check->execute();
        if ($check->fetch()) {
            return;
        }
        $this->db->exec('ALTER TABLE "User" ADD COLUMN IF NOT EXISTS active_token_id text');
    }

    public function create($data, $options = []) {
        $this->ensureIdentityColumns();
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
        
        $percentage = $lastWeek > 0 ? (($thisWeek - $lastWeek) / $lastWeek) * 100 : 100;
        return [
            'current' => $thisWeek,
            'previous' => $lastWeek,
            'percentage' => round($percentage, 1)
        ];
    }

    private function ensureTenantColumn() {
        $check = $this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'User' AND column_name = 'tenant_id'");
        $check->execute();
        if ($check->fetch()) {
            return;
        }
        $this->db->exec('ALTER TABLE "User" ADD COLUMN IF NOT EXISTS tenant_id text');
        $this->db->exec('UPDATE "User" SET tenant_id = COALESCE(tenant_id, \'' . $this->getTenantId() . '\')');
    }

    private function getTenantId() {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }
}

<?php

namespace App\Controllers;

use App\Repositories\UserRepository;
use App\Core\Response;
use App\Core\Auth;

class UserController {
    private $userRepository;

    public function __construct() {
        $this->userRepository = new UserRepository();
    }

    private function authenticate() {
        return Auth::requireUser();
    }

    private function normalizeText($value, int $maxLength = 255): ?string {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string)$value);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > $maxLength) {
            $normalized = mb_substr($normalized, 0, $maxLength);
        }

        return $normalized;
    }

    private function normalizeRole($value): string {
        $normalized = strtolower(trim((string)$value));
        if ($normalized === 'admin') {
            return 'admin';
        }
        return 'customer';
    }

    private function normalizeManagedProfile(array $data, array $existingProfile = []): array {
        $profile = $existingProfile;
        $phone = $this->normalizeText($data['phone'] ?? ($data['profile']['phone'] ?? null), 60);

        if ($phone !== null) {
            $profile['phone'] = $phone;
        } else {
            unset($profile['phone']);
        }

        return $profile;
    }

    private function validateManagedPayload(array $data, bool $isCreate, array $existingUser = []): array {
        $name = $this->normalizeText($data['name'] ?? null, 160);
        if ($name === null || mb_strlen($name) < 3) {
            Response::error('El nombre debe tener al menos 3 caracteres', 400, 'USER_NAME_INVALID');
            exit;
        }

        $email = strtolower((string)$this->normalizeText($data['email'] ?? null, 190));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Correo electrónico inválido', 400, 'USER_EMAIL_INVALID');
            exit;
        }

        $role = $this->normalizeRole($data['role'] ?? 'customer');
        $password = trim((string)($data['password'] ?? ''));
        if ($isCreate && $password === '') {
            Response::error('La contraseña es obligatoria', 400, 'USER_PASSWORD_REQUIRED');
            exit;
        }

        if ($password !== '' && mb_strlen($password) < 12) {
            Response::error('La contraseña debe tener al menos 12 caracteres', 400, 'USER_PASSWORD_WEAK');
            exit;
        }

        $documentType = $this->normalizeText($data['documentType'] ?? ($data['document_type'] ?? null), 40);
        $documentNumber = $this->normalizeText($data['documentNumber'] ?? ($data['document_number'] ?? null), 80);

        if (($documentType && !$documentNumber) || ($documentNumber && !$documentType)) {
            Response::error('Tipo y número de documento deben completarse juntos', 400, 'USER_DOCUMENT_INCOMPLETE');
            exit;
        }

        $existingProfile = [];
        if (!empty($existingUser['profile'])) {
            $decoded = json_decode((string)$existingUser['profile'], true);
            if (is_array($decoded)) {
                $existingProfile = $decoded;
            }
        }

        return [
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'password' => $password,
            'email_verified' => (bool)($data['emailVerified'] ?? ($data['email_verified'] ?? true)),
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'business_name' => $this->normalizeText($data['businessName'] ?? ($data['business_name'] ?? null), 180),
            'profile' => $this->normalizeManagedProfile($data, $existingProfile),
        ];
    }

    public function index() {
        Auth::requireAdmin();
        try {
            $users = $this->userRepository->getAll();
            Response::json($users);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USERS_LIST_FAILED');
        }
    }

    public function store() {
        $admin = Auth::requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Carga inválida', 400, 'USER_PAYLOAD_INVALID');
            return;
        }

        $payload = $this->validateManagedPayload($data, true);

        if ($this->userRepository->emailExists($payload['email'])) {
            Response::error('Ya existe un usuario con ese correo', 409, 'USER_EMAIL_EXISTS');
            return;
        }

        try {
            $created = $this->userRepository->createManaged($payload);
            Response::json($created, 201, null, sprintf('Usuario creado correctamente por %s.', $admin['name'] ?? 'administrador'));
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_CREATE_FAILED');
        }
    }

    public function update($id) {
        $admin = Auth::requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Carga inválida', 400, 'USER_PAYLOAD_INVALID');
            return;
        }

        $existingUser = $this->userRepository->getAdminUserById($id);
        if (!$existingUser) {
            Response::error('Usuario no encontrado', 404, 'USER_NOT_FOUND');
            return;
        }

        $payload = $this->validateManagedPayload($data, false, $existingUser);

        if (($admin['sub'] ?? null) === $id && $payload['role'] !== 'admin') {
            Response::error('No puedes quitarte tu propio rol de administrador desde aquí', 400, 'USER_SELF_ROLE_CHANGE_FORBIDDEN');
            return;
        }

        if ($this->userRepository->emailExists($payload['email'], $id)) {
            Response::error('Ya existe otro usuario con ese correo', 409, 'USER_EMAIL_EXISTS');
            return;
        }

        try {
            $updated = $this->userRepository->updateManaged($id, $payload);
            Response::json($updated, 200, null, 'Usuario actualizado correctamente.');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_UPDATE_FAILED');
        }
    }

    public function unlock($id) {
        Auth::requireAdmin();

        $existingUser = $this->userRepository->getAdminUserById($id);
        if (!$existingUser) {
            Response::error('Usuario no encontrado', 404, 'USER_NOT_FOUND');
            return;
        }

        try {
            $updated = $this->userRepository->unlockManagedUser($id);
            Response::json($updated, 200, null, 'Usuario desbloqueado correctamente.');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_UNLOCK_FAILED');
        }
    }

    public function getAddresses() {
        $user = $this->authenticate();
        try {
            $addresses = $this->userRepository->getAddresses($user['sub']);
            Response::json(['addresses' => $addresses ? json_decode($addresses, true) : []]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_ADDRESSES_FETCH_FAILED');
        }
    }

    public function updateAddresses() {
        $user = $this->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['addresses'])) {
            Response::error('Direcciones requeridas', 400, 'USER_ADDRESSES_REQUIRED');
            return;
        }

        try {
            $addresses = $this->userRepository->updateAddresses($user['sub'], $data['addresses']);
            Response::json(['addresses' => $addresses ? json_decode($addresses, true) : []]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_ADDRESSES_UPDATE_FAILED');
        }
    }

    public function getProfile() {
        $user = $this->authenticate();
        try {
            $profileData = $this->userRepository->getProfile($user['sub']);
            $profile = [];
            $name = null;
            $email = null;
            $phone = null;
            if ($profileData) {
                $name = $profileData['name'] ?? null;
                $email = $profileData['email'] ?? null;
                if (!empty($profileData['profile'])) {
                    $profile = json_decode($profileData['profile'], true) ?: [];
                }
                if (!empty($profile['phone'])) {
                    $phone = trim((string)$profile['phone']);
                }
                if (!empty($profileData['document_type'])) {
                    $profile['documentType'] = $profileData['document_type'];
                }
                if (!empty($profileData['document_number'])) {
                    $profile['documentNumber'] = $profileData['document_number'];
                }
                if (!empty($profileData['business_name'])) {
                    $profile['businessName'] = $profileData['business_name'];
                }
            }
            Response::json([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'profile' => $profile,
            ]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_PROFILE_FETCH_FAILED');
        }
    }

    public function updateProfile() {
        $user = $this->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['profile']) || !is_array($data['profile'])) {
            Response::error('Perfil requerido', 400, 'USER_PROFILE_REQUIRED');
            return;
        }

        $name = $data['name'] ?? null;
        if (!$name) {
            $first = trim($data['profile']['firstName'] ?? '');
            $last = trim($data['profile']['lastName'] ?? '');
            $name = trim($first . ' ' . $last);
        }

        try {
            $updated = $this->userRepository->updateProfile($user['sub'], $name, $data['profile']);
            $profile = [];
            $savedName = null;
            if ($updated) {
                $savedName = $updated['name'] ?? null;
                if (!empty($updated['profile'])) {
                    $profile = json_decode($updated['profile'], true) ?: [];
                }
                if (!empty($updated['document_type'])) {
                    $profile['documentType'] = $updated['document_type'];
                }
                if (!empty($updated['document_number'])) {
                    $profile['documentNumber'] = $updated['document_number'];
                }
                if (!empty($updated['business_name'])) {
                    $profile['businessName'] = $updated['business_name'];
                }
            }
            Response::json(['name' => $savedName, 'profile' => $profile]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_PROFILE_UPDATE_FAILED');
        }
    }

    public function updatePassword() {
        $user = $this->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);

        $currentPassword = trim((string)($data['currentPassword'] ?? ''));
        $newPassword = trim((string)($data['newPassword'] ?? ''));

        if ($currentPassword === '' || $newPassword === '') {
            Response::error('La contraseña actual y la nueva son obligatorias', 400, 'USER_PASSWORD_REQUIRED');
            return;
        }

        if (mb_strlen($newPassword) < 12) {
            Response::error('La nueva contraseña debe tener al menos 12 caracteres', 400, 'USER_PASSWORD_WEAK');
            return;
        }

        if ($currentPassword === $newPassword) {
            Response::error('La nueva contraseña debe ser diferente a la actual', 400, 'USER_PASSWORD_SAME');
            return;
        }

        try {
            $passwordHash = $this->userRepository->getPasswordHash($user['sub']);
            if (!$passwordHash || !password_verify($currentPassword, $passwordHash)) {
                Response::error('La contraseña actual es incorrecta', 400, 'USER_PASSWORD_INVALID_CURRENT');
                return;
            }

            $newTokenId = bin2hex(random_bytes(16));
            $this->userRepository->updatePassword(
                $user['sub'],
                password_hash($newPassword, PASSWORD_DEFAULT),
                $newTokenId
            );

            Response::json(['passwordUpdated' => true], 200, null, 'Contraseña actualizada correctamente.');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_PASSWORD_UPDATE_FAILED');
        }
    }
}

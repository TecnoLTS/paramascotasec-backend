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

    public function index() {
        Auth::requireAdmin();
        try {
            $users = $this->userRepository->getAll();
            Response::json($users);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USERS_LIST_FAILED');
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
            if ($profileData) {
                $name = $profileData['name'] ?? null;
                if (!empty($profileData['profile'])) {
                    $profile = json_decode($profileData['profile'], true) ?: [];
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
            Response::json(['name' => $name, 'profile' => $profile]);
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

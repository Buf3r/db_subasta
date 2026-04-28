<?php

namespace App\Controllers\Api;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\RESTful\ResourceController;
use App\Models\UserModel;

class User extends ResourceController
{
    use ResponseTrait;

    protected String|null $userId;

    public function __construct()
    {
        helper('cloudinary');
        $session = \Config\Services::session();
        $this->userId = $session->getFlashdata('user_id');
    }

    public function index()
    {
        $db = new UserModel;
        $users = $db->getUser();

        if (!$users) {
            return $this->failNotFound('Usuarios no encontrados');
        }

        return $this->respond([
            'status' => 200,
            'messages' => ['success' => 'OK'],
            'data' => $users,
        ]);
    }

    public function show($id = null)
    {
        $db = new UserModel;
        $user = $db->getUser($id);

        if (!$user) {
            return $this->failNotFound('Usuario no encontrado');
        }

        return $this->respond([
            'status' => 200,
            'messages' => ['success' => 'OK'],
            'data' => $user,
        ]);
    }

    public function create()
    {
        if (!$this->validate([
            'username'      => 'required|min_length[4]',
            'password'      => 'required|min_length[6]',
            'name'          => 'required',
            'email'         => 'required|valid_email',
            'phone'         => 'required',
            'profile_image' => 'permit_empty|max_size[profile_image,5120]',
        ])) {
            return $this->failValidationErrors(\Config\Services::validation()->getErrors());
        }

        $db = new UserModel;

        // Verificar email único
        $existingEmail = $db->where('email', $this->request->getVar('email'))->first();
        if ($existingEmail) {
            return $this->fail('Este correo ya está registrado. Intenta con otro.', 409);
        }

        // Verificar username único
        $existingUsername = $db->where('username', $this->request->getVar('username'))->first();
        if ($existingUsername) {
            return $this->fail('Este nombre de usuario ya está en uso.', 409);
        }

        $fileName = null;

        if ($imagefile = $this->request->getFiles()) {
            $img = $imagefile['profile_image'] ?? null;

            if ($img && $img->isValid() && !$img->hasMoved()) {
                try {
                    $fileName = uploadToCloudinary($img->getTempName(), 'auction/users');
                } catch (\Exception $e) {
                    return $this->failServerError($e->getMessage());
                }
            }
        }

        $insert = [
            'username'      => $this->request->getVar('username'),
            'password_hash' => password_hash($this->request->getVar('password'), PASSWORD_DEFAULT),
            'name'          => $this->request->getVar('name'),
            'email'         => $this->request->getVar('email'),
            'phone'         => $this->request->getVar('phone'),
            'city'          => $this->request->getVar('city'),
            'profile_image' => $fileName,
        ];

        $save = $db->insert($insert);

        if (!$save) {
            return $this->failServerError(description: 'Porfavor, verifica los datos e intenta nuevamente');
        }

        return $this->respondCreated([
            'status' => 201,
            'messages' => ['success' => 'OK'],
            'data' => ['user_id' => $db->getInsertID()],
        ]);
    }

    public function update($id = null)
    {
        if (!$this->validate([
            'name'  => 'permit_empty',
            'email' => 'permit_empty|valid_email',
            'phone' => 'permit_empty',
        ])) {
            return $this->failValidationErrors(\Config\Services::validation()->getErrors());
        }

        $db = new UserModel;
        $exist = $db->where(['user_id' => $this->userId])->first();

        if (!$exist) {
            return $this->failNotFound(description: 'Usuario no encontrado');
        }

        $update = [
                'name'  => $this->request->getRawInputVar('name') ?? $exist['name'],
                'email' => $this->request->getRawInputVar('email') ?? $exist['email'],
                'phone' => $this->request->getRawInputVar('phone') ?? $exist['phone'],
                'city'  => $this->request->getRawInputVar('city') ?? $exist['city'],
            ];

        $save = $db->update($this->userId, $update);

        if (!$save) {
            return $this->failServerError(description: 'Fallo al actualizar el usuario');
        }

        return $this->respondUpdated([
            'status' => 200,
            'messages' => ['success' => 'Usuario actualizado exitosamente'],
        ]);
    }

    public function changeProfileImage()
    {
        if (!$this->validate([
            'profile_image' => 'permit_empty|mime_in[profile_image,image/png,image/jpeg]|is_image[profile_image]|max_size[profile_image,5120]',
        ])) {
            return $this->failValidationErrors(\Config\Services::validation()->getErrors());
        }

        $db = new UserModel;
        $exist = $db->where(['user_id' => $this->userId])->first();

        if (!$exist) {
            return $this->failNotFound(description: 'Usuario no encontrado');
        }

        $fileName = null;

        if ($imagefile = $this->request->getFiles()) {
            $img = $imagefile['profile_image'] ?? null;

            if ($img && $img->isValid() && !$img->hasMoved()) {
                if ($exist['profile_image']) {
                    deleteFromCloudinary($exist['profile_image']);
                }
                try {
                    $fileName = uploadToCloudinary($img->getTempName(), 'auction/users');
                } catch (\Exception $e) {
                    return $this->failServerError($e->getMessage());
                }
            }
        }

        $save = $db->update($this->userId, ['profile_image' => $fileName]);

        if (!$save) {
            return $this->failServerError(description: 'Fallo al actualizar la imagen de perfil');
        }

        return $this->respondUpdated([
            'status' => 200,
            'messages' => ['success' => 'Imagen de perfil actualizada exitosamente'],
        ]);
    }

    public function updateFcmToken()
    {
        $db = new UserModel;
        $exist = $db->where(['user_id' => $this->userId])->first();

        if (!$exist) {
            return $this->failNotFound(description: 'Usuario no encontrado');
        }

        $fcmToken = $this->request->getVar('fcm_token');

        if (!$fcmToken) {
            return $this->failValidationErrors(['fcm_token' => 'fcm_token is required']);
        }

        $db->update($this->userId, ['fcm_token' => $fcmToken]);

        return $this->respondUpdated([
            'status' => 200,
            'messages' => ['success' => 'FCM token updated'],
        ]);
    }

    public function changePassword()
    {
        if (!$this->validate([
            'old_password' => 'required|min_length[6]',
            'new_password' => 'required|min_length[6]',
        ])) {
            return $this->failValidationErrors(\Config\Services::validation()->getErrors());
        }

        $db = new UserModel;
        $exist = $db->where(['user_id' => $this->userId])->first();

        if (!$exist) {
            return $this->failNotFound(description: 'Usuario no encontrado');
        }

        if (!password_verify($this->request->getVar('old_password'), $exist['password_hash'])) {
            return $this->fail('Old password does not match');
        }

        $update = [
            'password_hash' => password_hash($this->request->getVar('new_password'), PASSWORD_DEFAULT),
        ];

        $save = $db->update($this->userId, $update);

        if (!$save) {
            return $this->failServerError(description: 'Failed to change password');
        }

        return $this->respondUpdated([
            'status' => 200,
            'messages' => ['success' => 'Password updated successfully'],
        ]);
    }

    public function myCredits()
    {
        $db = new UserModel;
        $user = $db->find($this->userId);

        if (!$user) {
            return $this->failNotFound('Usuario no encontrado');
        }

        $freeRemaining = max(0, 2 - $user['free_auctions_used']);

        return $this->respond([
            'status' => 200,
            'data'   => [
                'vip'                => (bool) $user['vip'],
                'credits'            => (int) $user['credits'],
                'free_auctions_used' => (int) $user['free_auctions_used'],
                'free_remaining'     => $freeRemaining,
                'can_publish'        => $user['vip'] == 1 || $freeRemaining > 0 || $user['credits'] > 0,
            ],
        ]);
    }

    public function delete($id = null)
    {
        $db = new UserModel;
        $exist = $db->where(['user_id' => $this->userId])->first();

        if (!$exist) return $this->failNotFound(description: 'Usuario no encontrado');

        if ($exist['profile_image']) {
            deleteFromCloudinary($exist['profile_image']);
        }

        $delete = $db->delete($this->userId);

        if (!$delete) return $this->failServerError(description: 'Fallo al eliminar el usuario');

        return $this->respond([
            'status' => 200,
            'messages' => ['success' => 'Usuario eliminado exitosamente'],
        ]);
    }

    public function addCredits($id = null)
    {
        // Solo para admin — agregar una clave secreta
        $adminKey = $this->request->getVar('admin_key');
        if ($adminKey !== env('ADMIN_KEY')) {
            return $this->failForbidden('Access forbidden');
        }

        $amount = intval($this->request->getVar('amount'));
        if ($amount <= 0) {
            return $this->fail('Amount must be greater than 0');
        }

        $db = new UserModel;
        $user = $db->find($id);

        if (!$user) {
            return $this->failNotFound('Usuario no encontrado');
        }

        $db->update($id, [
            'credits' => $user['credits'] + $amount
        ]);

        return $this->respondUpdated([
            'status'   => 200,
            'messages' => ['success' => "Creditos agregados exitosamente al usuario $id"],
            'data'     => ['new_balance' => $user['credits'] + $amount],
        ]);
    }
}
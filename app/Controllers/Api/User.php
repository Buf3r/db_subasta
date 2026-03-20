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
            return $this->failNotFound('Users not found');
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
            return $this->failNotFound('User not found');
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
            'username'      => 'required|is_unique[users.username]|min_length[4]',
            'password'      => 'required|min_length[6]',
            'name'          => 'required',
            'email'         => 'required|valid_email',
            'phone'         => 'required',
            'profile_image' => 'permit_empty|mime_in[profile_image,image/png,image/jpeg]|is_image[profile_image]|max_size[profile_image,5120]',
        ])) {
            return $this->failValidationErrors(\Config\Services::validation()->getErrors());
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

        $db = new UserModel;
        $save = $db->insert($insert);

        if (!$save) {
            return $this->failServerError(description: 'Failed to create user');
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
            return $this->failNotFound(description: 'User not found');
        }

        $update = [
                'name'  => $this->request->getRawInputVar('name') ?? $exist['name'],
                'email' => $this->request->getRawInputVar('email') ?? $exist['email'],
                'phone' => $this->request->getRawInputVar('phone') ?? $exist['phone'],
                'city'  => $this->request->getRawInputVar('city') ?? $exist['city'],
            ];

        $save = $db->update($this->userId, $update);

        if (!$save) {
            return $this->failServerError(description: 'Failed to update user');
        }

        return $this->respondUpdated([
            'status' => 200,
            'messages' => ['success' => 'User updated successfully'],
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
            return $this->failNotFound(description: 'User not found');
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
            return $this->failServerError(description: 'Failed to update profile image');
        }

        return $this->respondUpdated([
            'status' => 200,
            'messages' => ['success' => 'Profile image updated successfully'],
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
            return $this->failNotFound(description: 'User not found');
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

    public function delete($id = null)
    {
        $db = new UserModel;
        $exist = $db->where(['user_id' => $this->userId])->first();

        if (!$exist) return $this->failNotFound(description: 'User not found');

        if ($exist['profile_image']) {
            deleteFromCloudinary($exist['profile_image']);
        }

        $delete = $db->delete($this->userId);

        if (!$delete) return $this->failServerError(description: 'Failed to delete user');

        return $this->respond([
            'status' => 200,
            'messages' => ['success' => 'User successfully deleted'],
        ]);
    }
}
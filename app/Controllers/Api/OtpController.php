<?php

namespace App\Controllers\Api;

use App\Models\OtpModel;
use App\Models\UserModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;
use App\Libraries\JWTCI4;

class OtpController extends ResourceController
{
    use ResponseTrait;

    public function send()
    {
        $phone = $this->request->getVar('phone');
        if (!$phone) return $this->fail('El teléfono es requerido', 400);

        $cleanPhone = preg_replace('/\D/', '', $phone);

        // ✅ Verificar límites ANTES de cualquier cosa
        $attemptsDb = new \App\Models\OtpAttemptsModel();
        $normalizedPhone = $this->normalizePhone($cleanPhone);
        $check = $attemptsDb->checkCanRequest($normalizedPhone);

        if ($check !== null) {
            return $this->respond([
                'status'  => 429,
                'messages' => ['error' => $check['message']],
                'data'    => [
                    'error_code'  => $check['error'],
                    'retry_after' => $check['retry_after']
                ]
            ], 429);
        }

        // Verificar que existe el usuario
        $userDb = new UserModel;
        $user = $userDb->groupStart()
            ->where('phone', $cleanPhone)
            ->orWhere('phone', '0' . $cleanPhone)
            ->orWhere('phone', ltrim($cleanPhone, '0'))
            ->groupEnd()
            ->first();

        if (!$user) return $this->fail('No existe una cuenta con ese número.', 404);

        // Generar código
        $otpDb = new OtpModel;
        $code = $otpDb->generateCode($normalizedPhone);

        // ✅ Registrar el envío DESPUÉS de generarlo
        $attemptsDb->registerSend($normalizedPhone);

        return $this->respond([
            'status'   => 200,
            'messages' => ['success' => 'Código generado exitosamente'],
            'data'     => ['phone' => $cleanPhone, 'code' => $code]
        ]);
    }

    
    
    
        private function normalizePhone(string $phone): string
    {
        // Elimina todo excepto dígitos
        $phone = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '0')) {
            return '58' . substr($phone, 1);
        }
        
        if (!str_starts_with($phone, '58')) {
            return '58' . $phone;
        }
        
        return $phone;
    }

    public function verify()
    {
        $phone = $this->request->getVar('phone');
        $code  = $this->request->getVar('code');

        if (!$phone || !$code) {
            return $this->fail('Teléfono y código son requeridos', 400);
        }

        // 1. Limpiamos el teléfono
        $cleanPhone = preg_replace('/\D/', '', $phone);

        $otpDb = new OtpModel;
        
        // 2. Buscamos el código verificando diferentes formatos
        $valid = $otpDb->verifyCode($cleanPhone, $code);

        // Si no es válido de inmediato, probamos con la validación alternativa
        if (!$valid) {
            $altPhone = ltrim($cleanPhone, '0');
            $valid = $otpDb->verifyCode($altPhone, $code);
        }

        if (!$valid) {
            // Este mensaje se verá en el BLoC de Flutter y ya no aparecerá vacío
            return $this->failUnauthorized('Código inválido o expirado.');
        }

        $userDb = new UserModel;
        // Buscamos al usuario en la BD para generar el token
        $user = $userDb->groupStart()
            ->where('phone', $cleanPhone)
            ->orWhere('phone', '0' . $cleanPhone)
            ->orWhere('phone', ltrim($cleanPhone, '0'))
            ->orWhere('phone', '58' . $cleanPhone)
            ->groupEnd()
            ->first();

        if (!$user) {
            return $this->failNotFound('Usuario no encontrado.');
        }

        // Generamos el token de autenticación
        $token = $this->generateJWT($user['user_id']);

        // En verify() — al final, si es exitoso, limpiar el registro:
        // Justo antes del return final en verify():
        $attemptsDb = new \App\Models\OtpAttemptsModel();
        $attemptsDb->clearRecord($this->normalizePhone($cleanPhone));

        return $this->respond([
            'status'   => 200,
            'messages' => ['success' => 'OK'],
            'data'     => [
                'token' => $token,
                'user'  => [
                    'user_id' => $user['user_id'],
                    'phone'   => $user['phone']
                ]
            ],
        ]);
    }

    private function sendWhatsApp(string $phone, string $code, string $apiKey, string $phoneId): bool
    {
        try {
            // Estructura fija (sin variables de componente) para evitar el rechazo de la API
            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $phone,
                'type'              => 'template',
                'template'          => [
                    'name'     => 'hello_world',
                    'language' => [
                        'code' => 'en_US'
                    ]
                ],
            ];

            $ch = curl_init("https://graph.facebook.com/v25.0/{$phoneId}/messages");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

            $responseRaw = curl_exec($ch);
            
            if (curl_errno($ch)) {
                log_message('error', 'cURL Error: ' . curl_error($ch));
                curl_close($ch);
                return false;
            }
            
            curl_close($ch);
            $response = json_decode($responseRaw, true);

            // Registrar el error de Meta si existe
            if (isset($response['error'])) {
                log_message('error', 'Meta Error de validación: ' . json_encode($response['error']));
                // En este punto, puedes revisar los logs de tu servidor para ver el mensaje exacto
                return false;
            }

            return isset($response['messages'][0]['id']);
            
        } catch (\Exception $e) {
            log_message('error', 'OTP WhatsApp error general: ' . $e->getMessage());
            return false;
        }
    }

    // Reemplaza el método generateJWT completo
    private function generateJWT(string $userId): string
    {
        $userDb = new UserModel;
        $user = $userDb->find($userId);

        $jwt = new JWTCI4();
        return $jwt->token(
            $user['user_id'],
            $user['username'] ?? '',
            $user['name']     ?? '',
            $user['email']    ?? '',
            $user['phone']    ?? '',
        );
    }
}
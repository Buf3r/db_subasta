<?php

namespace App\Controllers\Api;

use App\Models\OtpModel;
use App\Models\UserModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class OtpController extends ResourceController
{
    use ResponseTrait;

   public function send()
    {
        $phone = $this->request->getVar('phone');

        if (!$phone) {
            return $this->fail('El teléfono es requerido', 400);
        }

        // 1. Limpiamos el teléfono de caracteres no numéricos
        $cleanPhone = preg_replace('/\D/', '', $phone);

        // 2. Buscamos el usuario en la base de datos
        $userDb = new UserModel;
        $user = $userDb->groupStart()
            ->where('phone', $cleanPhone)
            ->orWhere('phone', '0' . $cleanPhone)
            ->orWhere('phone', ltrim($cleanPhone, '0'))
            ->groupEnd()
            ->first();

        if (!$user) {
            return $this->fail('No existe una cuenta con ese número de teléfono.', 404);
        }

        // 3. Generamos el código
        try {
            $otpDb = new OtpModel;
            $code = $otpDb->generateCode($this->normalizePhone($cleanPhone));
        } catch (\Throwable $e) {
            return $this->failServerError('Error generando código: ' . $e->getMessage());
        }

        // 4. Asegurarnos de que las variables de entorno existen antes de usarlas
        $zernioApiKey = env('ZERNIO_API_KEY');
        $zernioPhoneId = env('ZERNIO_PHONE_ID');

        if (empty($zernioApiKey) || empty($zernioPhoneId)) {
            return $this->failServerError('Faltan variables de configuración de WhatsApp en el servidor (ENV)');
        }

        // 5. Enviamos por WhatsApp
        $normalizedPhone = $this->normalizePhone($cleanPhone);
        $sent = $this->sendWhatsApp($normalizedPhone, $code, $zernioApiKey, $zernioPhoneId);

        if (!$sent) {
            return $this->failServerError('No se pudo enviar el código. Intenta de nuevo.');
        }

        return $this->respond([
            'status'   => 200,
            'messages' => ['success' => 'Código enviado por WhatsApp'],
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

        $otpDb = new OtpModel;
        $valid = $otpDb->verifyCode($phone, $code);

        if (!$valid) {
            return $this->fail('Código inválido o expirado.', 401);
        }

        $userDb = new UserModel;
        $user = $userDb->where('phone', $phone)->first();

        if (!$user) {
            return $this->failNotFound('Usuario no encontrado.');
        }

        $token = $this->generateJWT($user['user_id']);

        return $this->respond([
            'status'   => 200,
            'messages' => ['success' => 'OK'],
            'data'     => ['token' => $token],
        ]);
    }

    private function sendWhatsApp(string $phone, string $code, string $apiKey, string $phoneId): bool
        {
            try {
                // Estructura adaptada para enviar variables dinámicas en la plantilla de Meta
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'to'                => $phone,
                    'type'              => 'template',
                    'template'          => [
                        'name'     => 'hello_world', // Nombre de tu plantilla aprobada
                        'language' => [
                            'code' => 'en_US'
                        ],
                        'components' => [
                            [
                                'type'       => 'body',
                                'parameters' => [
                                    [
                                        'type' => 'text',
                                        'text' => $code // Aquí se inyecta dinámicamente tu OTP
                                    ]
                                ]
                            ]
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

                return isset($response['messages'][0]['id']);
                
            } catch (\Exception $e) {
                log_message('error', 'OTP WhatsApp error general: ' . $e->getMessage());
                return false;
            }
        }

    private function generateJWT(string $userId): string
    {
        $key    = env('JWT_SECRET_KEY');
        $ttl    = (int) env('JWT_TTL', 3600);
        $now    = time();

        $header  = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode([
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + $ttl,
        ]));

        $signature = base64_encode(hash_hmac('sha256', "$header.$payload", $key, true));
        return "$header.$payload.$signature";
    }
}
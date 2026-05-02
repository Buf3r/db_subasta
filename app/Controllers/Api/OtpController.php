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
        $cleanPhoneClean = preg_replace('/\D/', '', $phone);

        // 2. Buscamos el usuario en la base de datos
        $userDb = new UserModel;
        $user = $userDb->groupStart()
            ->where('phone', $PhoneClean)
            ->orWhere('phone', '0' . $PhoneClean)
            ->orWhere('phone', ltrim($PhoneClean, '0'))
            ->groupEnd()
            ->first();

        if (!$user) {
            return $this->fail('No existe una cuenta con ese número de teléfono.', 404);
        }

        // 3. Generamos el código dentro de un try-catch para capturar excepciones
        try {
            $otpDb = new OtpModel;
            $code = $otpDb->generateCode($this->normalizePhone($PhoneClean));
        } catch (\Throwable $e) {
            return $this->failServerError('Error generando código: ' . $e->getMessage());
        }

        // 4. Enviamos el mensaje de WhatsApp
        $normalizedPhone = $this->normalizePhone($PhoneClean);
        $sent = $this->sendWhatsApp($normalizedPhone, $code);

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
        // Si empieza con 0 → reemplaza por 58
        if (str_starts_with($phone, '0')) {
            return '58' . substr($phone, 1);
        }
        
        // Si no empieza con 58 → agrega 58
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

    private function sendWhatsApp(string $phone, string $code): bool
    {
        try {
            $zernioApiKey = env('ZERNIO_API_KEY');
            $zernioPhoneId = env('ZERNIO_PHONE_ID');

            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $phone,
                'type'              => 'text',
                'text'              => [
                    'body' => "🔐 Tu código de verificación de *Subastalo* es:\n\n*$code*\n\nVálido por 10 minutos. No lo compartas con nadie."
                ],
            ];

            $ch = curl_init("https://graph.facebook.com/v19.0/{$zernioPhoneId}/messages");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $zernioApiKey,
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

            $response = json_decode(curl_exec($ch), true);
            curl_close($ch);

            return isset($response['messages'][0]['id']);
        } catch (\Exception $e) {
            log_message('error', 'OTP WhatsApp error: ' . $e->getMessage());
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
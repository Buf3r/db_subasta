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

        // Verificar que el teléfono existe en la BD
        $userDb = new UserModel;
        $user = $userDb->where('phone', $phone)->first();

        if (!$user) {
            return $this->fail('No existe una cuenta con ese número de teléfono.', 404);
        }

        $otpDb = new OtpModel;
        $code = $otpDb->generateCode($phone);

        // Enviar por WhatsApp via Zernio
        $sent = $this->sendWhatsApp($phone, $code);

        if (!$sent) {
            return $this->failServerError('No se pudo enviar el código. Intenta de nuevo.');
        }

        return $this->respond([
            'status'   => 200,
            'messages' => ['success' => 'Código enviado por WhatsApp'],
        ]);
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

        // Obtener el usuario y generar JWT igual que en login
        $userDb = new UserModel;
        $user = $userDb->where('phone', $phone)->first();

        if (!$user) {
            return $this->failNotFound('Usuario no encontrado.');
        }

        // Generar JWT usando el mismo helper del AuthController
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

            // Formatear número venezolano: 04121234567 → 584121234567
            $formattedPhone = $this->formatVenezuelanPhone($phone);

            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $formattedPhone,
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

    private function formatVenezuelanPhone(string $phone): string
    {
        // 04121234567 → 584121234567
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0')) {
            $phone = '58' . substr($phone, 1);
        }
        return $phone;
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
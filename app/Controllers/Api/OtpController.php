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

            // 4. RESPUESTA EXITOSA PARA FLUTTER Y POSTMAN
            // Se devuelve el estado 200 para que la app no muestre el error inesperado
            return $this->respond([
                'status'   => 200,
                'messages' => ['success' => 'Código generado exitosamente'],
                'data'     => [
                    'phone'      => $cleanPhone,
                    // Puedes comentar o descomentar esta línea dependiendo si quieres ver el código en desarrollo
                    'code'       => $code
                ]
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

    public function verifyCode(string $phone, string $code): bool
    {
        // 1. Limpiamos cualquier carácter no numérico
        $cleanPhone = preg_replace('/\D/', '', $phone);
        
        // Extraemos los últimos 10 dígitos (el número nacional estándar sin prefijo de país)
        // Por ejemplo, de 584122944927 o 04122944927 toma 4122944927
        $nationalNumber = substr($cleanPhone, -10);

        // 2. Buscamos el código en la base de datos comparando los últimos 10 dígitos
        $otp = $this->where('used', 0)
                    ->where('code', $code)
                    ->where('expires_at >=', date('Y-m-d H:i:s'))
                    ->orderBy('created_at', 'DESC')
                    ->findAll(); // Traemos varios posibles si los hay para iterar

        foreach ($otp as $row) {
            // Limpiamos el teléfono de la base de datos para comparar sus últimos 10 dígitos
            $dbPhone = preg_replace('/\D/', '', $row['phone']);
            $dbNationalNumber = substr($dbPhone, -10);

            if ($dbNationalNumber === $nationalNumber) {
                // Marcamos como usado y retornamos éxito
                $this->update($row['id'], ['used' => 1]);
                return true;
            }
        }

        return false;
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
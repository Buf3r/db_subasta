<?php

namespace App\Libraries;

class FCMNotification
{
    private string $projectId = 'subastalo-9bcbf';
    private array $credentials;

    public function __construct()
    {
        $credentialsJson = env('FCM_CREDENTIALS');
        if (!$credentialsJson) {
            throw new \Exception('FCM_CREDENTIALS not found in environment');
        }
        $this->credentials = json_decode($credentialsJson, true);
        if (!$this->credentials) {
            throw new \Exception('FCM_CREDENTIALS is not valid JSON: ' . json_last_error_msg());
        }
    }

    private function getAccessToken(): string
    {
        $now = time();
        $payload = [
            'iss'   => $this->credentials['client_email'],
            'sub'   => $this->credentials['client_email'],
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        ];

        $jwt = $this->createJWT($payload, $this->credentials['private_key']);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]));

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        return $response['access_token'];
    }

    private function createJWT(array $payload, string $privateKey): string
    {
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $body   = base64_encode(json_encode($payload));

        $data = "$header.$body";
        openssl_sign($data, $signature, $privateKey, 'SHA256');

        return "$data." . base64_encode($signature);
    }

    public function sendNotification(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        try {
            $accessToken = $this->getAccessToken();

            $message = [
                'message' => [
                    'token'        => $fcmToken,
                    'notification' => [
                        'title' => $title,
                        'body'  => $body,
                    ],
                    'data'    => array_map('strval', $data),
                    'android' => [
                        'notification' => [
                            'channel_id' => 'subastalo_channel',
                            'priority'   => 'high',
                        ],
                    ],
                ],
            ];

            $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));

            $response = json_decode(curl_exec($ch), true);
            curl_close($ch);

            return isset($response['name']);
        } catch (\Exception $e) {
            log_message('error', 'FCM error: ' . $e->getMessage());
            return false;
        }
    }
}
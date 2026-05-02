<?php
// App/Libraries/WhatsAppNotifier.php
namespace App\Libraries;

class WhatsAppNotifier
{
    public function sendOtp(string $phone, string $code): bool
    {
        // CallMeBot solo funciona para números registrados con ellos
        // Para usuarios: usa UltraMsg (gratis 300 msg/mes)
        $apiKey  = env('ULTRAMSG_API_KEY');
        $instance = env('ULTRAMSG_INSTANCE');

        $message = "🔐 *SUBASTALO*\n\nTu código de verificación: *{$code}*\n\nVálido 10 minutos. No lo compartas.";

        $ch = curl_init("https://api.ultramsg.com/{$instance}/messages/chat");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'token'  => $apiKey,
            'to'     => '+' . ltrim($phone, '0'),
            'body'   => $message,
        ]));

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        log_message('info', 'UltraMsg response: ' . json_encode($response));
        log_message('info', 'Sending to phone: ' . $phone . ' code: ' . $code);

        return ($response['sent'] ?? '') === 'true';
    }
}
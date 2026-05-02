<?php
namespace App\Models;
use CodeIgniter\Model;

class OtpAttemptsModel extends Model
{
    protected $table      = 'otp_attempts';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'phone', 'attempts', 'last_attempt_at',
        'blocked_until', 'resend_allowed_at'
    ];
    protected $useTimestamps = false;

    public function getRecord(string $phone): ?array
    {
        return $this->where('phone', $phone)->first();
    }

    /**
     * Verifica si puede solicitar OTP.
     * Retorna null si puede, o un mensaje de error si no.
     */
    public function checkCanRequest(string $phone): ?array
    {
        $record = $this->getRecord($phone);
        $now = time();

        if (!$record) return null; // Primera vez, libre

        // ¿Está bloqueado 24h?
        if ($record['blocked_until'] && strtotime($record['blocked_until']) > $now) {
            $remaining = strtotime($record['blocked_until']) - $now;
            $hours   = floor($remaining / 3600);
            $minutes = floor(($remaining % 3600) / 60);
            return [
                'error'     => 'blocked_24h',
                'message'   => "Bloqueado. Intenta en {$hours}h {$minutes}m.",
                'retry_after' => strtotime($record['blocked_until'])
            ];
        }

        // ¿Debe esperar 5 minutos para reenviar?
        if ($record['resend_allowed_at'] && strtotime($record['resend_allowed_at']) > $now) {
            $remaining = strtotime($record['resend_allowed_at']) - $now;
            $minutes = ceil($remaining / 60);
            return [
                'error'     => 'wait_5min',
                'message'   => "Espera {$minutes} minuto(s) para solicitar otro código.",
                'retry_after' => strtotime($record['resend_allowed_at'])
            ];
        }

        return null; // Puede solicitar
    }

    /**
     * Registra que se envió un OTP.
     * attempts=1 → permite reenvío en 5min
     * attempts=2 → bloquea 24h
     */
    public function registerSend(string $phone): void
    {
        $record = $this->getRecord($phone);
        $now = date('Y-m-d H:i:s');

        if (!$record) {
            // Primera solicitud del día
            $this->insert([
                'phone'             => $phone,
                'attempts'          => 1,
                'last_attempt_at'   => $now,
                'blocked_until'     => null,
                'resend_allowed_at' => null,
            ]);
            return;
        }

        $newAttempts = ($record['attempts'] ?? 0) + 1;

        if ($newAttempts >= 2) {
            // Segundo reenvío → bloqueo 24h
            $this->where('phone', $phone)->set([
                'attempts'          => $newAttempts,
                'last_attempt_at'   => $now,
                'blocked_until'     => date('Y-m-d H:i:s', strtotime('+24 hours')),
                'resend_allowed_at' => null,
            ])->update();
        } else {
            // Primer reenvío → esperar 5 min para el próximo
            $this->where('phone', $phone)->set([
                'attempts'          => $newAttempts,
                'last_attempt_at'   => $now,
                'resend_allowed_at' => date('Y-m-d H:i:s', strtotime('+5 minutes')),
                'blocked_until'     => null,
            ])->update();
        }
    }

    /** Limpia el registro al verificar exitosamente */
    public function clearRecord(string $phone): void
    {
        $this->where('phone', $phone)->delete();
    }
}
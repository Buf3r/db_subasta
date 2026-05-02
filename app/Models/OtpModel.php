<?php

namespace App\Models;

use CodeIgniter\Model;

class OtpModel extends Model
{
    protected $table      = 'otp_codes';
    protected $primaryKey = 'id';

    protected $allowedFields = ['phone', 'code', 'expires_at', 'used', 'created_at'];

    // Asegúrate de que los campos de fecha se manejen como objetos de fecha si usas un formato de base de datos.
    protected $useTimestamps = false; 

    public function generateCode(string $phone): string
    {
        $cleanPhone = preg_replace('/\D/', '', $phone);
        
        // 1. Generamos un número aleatorio de 6 dígitos
        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // 2. Definimos la expiración (+10 minutos usando la hora local de Venezuela/Caracas configurada en el servidor)
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        // 3. Guardamos en la base de datos
        $data = [
            'phone'      => $cleanPhone,
            'code'       => $code,
            'expires_at' => $expiresAt,
            'used'       => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $this->insert($data);

        return $code;
    }

    public function verifyCode(string $phone, string $code): bool
    {
        $cleanPhone = preg_replace('/\D/', '', $phone);
        $nationalNumber = substr($cleanPhone, -10); // Toma los últimos 10 dígitos (ej. 4122944927)

        // Buscamos los códigos no usados
        $otps = $this->where('used', 0)
                     ->where('code', $code)
                     ->where('expires_at >=', date('Y-m-d H:i:s'))
                     ->findAll();

        foreach ($otps as $otp) {
            $dbPhone = preg_replace('/\D/', '', $otp['phone']);
            $dbNationalNumber = substr($dbPhone, -10);

            if ($dbNationalNumber === $nationalNumber) {
                // Actualizamos a usado
                $this->update($otp['id'], ['used' => 1]);
                return true;
            }
        }

        return false;
    }
}
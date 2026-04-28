<?php

namespace App\Models;

use CodeIgniter\Model;

class OtpModel extends Model
{
    protected $table      = 'otp_codes';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['phone', 'code', 'expires_at', 'used', 'created_at'];

    public function generateCode(string $phone): string
    {
        // Invalida códigos anteriores del mismo teléfono
        $this->where('phone', $phone)->set('used', 1)->update();

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        $this->insert([
            'phone'      => $phone,
            'code'       => $code,
            'expires_at' => $expiresAt,
            'used'       => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $code;
    }

    public function verifyCode(string $phone, string $code): bool
    {
        $record = $this->where([
            'phone' => $phone,
            'code'  => $code,
            'used'  => 0,
        ])->where('expires_at >', date('Y-m-d H:i:s'))->first();

        if (!$record) return false;

        $this->update($record['id'], ['used' => 1]);
        return true;
    }
}
<?php

namespace App\Models;

use CodeIgniter\Model;

class StatusModel extends Model
{
    protected $table      = 'status';
    protected $primaryKey = 'Status_Server';
    protected $returnType = 'array';
    protected $allowedFields = ['Status_Server'];

    public function getStatus(): array
    {
        return $this->first() ?? [];
    }
}
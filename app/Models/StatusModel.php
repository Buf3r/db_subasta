<?php

namespace App\Models;

use CodeIgniter\Model;

class StatusModel extends Model
{
    protected $table      = 'status';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    public function getStatus(): array
    {
        return $this->orderBy('id', 'DESC')->first();
    }
}
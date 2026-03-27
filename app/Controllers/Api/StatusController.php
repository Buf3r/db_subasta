<?php

namespace App\Controllers\Api;

use App\Models\StatusModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class StatusController extends ResourceController
{
    use ResponseTrait;

    public function index()
    {
        $db = new StatusModel;
        $status = $db->getStatus();

        return $this->respond([
            'status' => 200,
            'data'   => $status,
        ]);
    }
}
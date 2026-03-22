<?php

namespace App\Controllers\Api;

use App\Models\AuctionModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class CronController extends ResourceController
{
    use ResponseTrait;

    public function closeExpired()
{
    try {
        $db = new AuctionModel;
        $now = date('Y-m-d H:i:s');

        $expired = $db->where('status', 'open')
            ->where('date_completed <', $now)
            ->whereNotNull('date_completed')
            ->findAll();

        if (empty($expired)) {
            return $this->respond([
                'status' => 200,
                'messages' => ['success' => 'No expired auctions'],
                'closed' => 0,
            ]);
        }

        $count = 0;
        foreach ($expired as $auction) {
            $db->update($auction['auction_id'], ['status' => 'closed']);
            $count++;
        }

        return $this->respond([
            'status' => 200,
            'messages' => ['success' => "Closed $count auctions"],
            'closed' => $count,
        ]);
    } catch (\Throwable $e) {
        return $this->failServerError($e->getMessage() . ' in ' . $e->getFile() . ' line ' . $e->getLine());
    }
}
}
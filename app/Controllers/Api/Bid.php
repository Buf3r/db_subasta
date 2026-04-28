<?php

namespace App\Controllers\Api;

use App\Models\AuctionModel;
use App\Models\BidModel;
use App\Models\UserModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;
use Config\Services;

class Bid extends ResourceController
{
    use ResponseTrait;

    protected String $userId;

    public function __construct()
    {
        $session = \Config\Services::session();
        $this->userId = $session->getFlashdata('user_id');
    }

    // Basic CRUD operation

    public function index()
    {
        $db = new BidModel;
        $bids = $db->getBid();

        if (!$bids) {
            return $this->failNotFound('Ofertas no encontradas');
        }

        $userDb = new UserModel;

        foreach ($bids as $key => $value) {
            if ($value['profile_image']) {
                $bids[$key]['profile_image'] = Services::fullImageURL($value['profile_image']);
            }

            $bids[$key]['bidder'] = $userDb->getUser($value['user_id'] ?? null);

            $bids[$key]['mine'] = $bids[$key]['user_id'] == $this->userId;
        }

        return $this->respond([
            'status' => 200,
            'messages' => ['success' => 'OK'],
            'data' => $bids,
        ]);
    }

    public function showBids($auctionId = null)
    {
        $db = new BidModel;
        $bids = $db->getBid(where: ['auction_id' => $auctionId]);

        if ($bids) {
            $userDb = new UserModel;

            foreach ($bids as $key => $value) {
                $bids[$key]['bidder'] = $userDb->getUser($value['user_id'] ?? null);

                if ($bids[$key]['bidder']['profile_image']) {
                    $bids[$key]['profile_image'] = Services::fullImageURL($bids[$key]['bidder']['profile_image']);
                } else {
                    $bids[$key]['profile_image'] = null;
                }

                $bids[$key]['mine'] = $bids[$key]['user_id'] == $this->userId;
            }
        }

        return $this->respond([
            'status' => 200,
            'messages' => ['success' => 'OK'],
            'data' => $bids,
        ]);
    }

    public function show($id = null)
    {
        $db = new BidModel;
        $bid = $db->getBid($id);

        if (!$bid) {
            return $this->failNotFound('Oferta no encontrada');
        }

        $userDb = new UserModel;

        $bid['bidder'] = $userDb->getUser($value['user_id'] ?? null);

        if ($bid['bidder']['profile_image']) {
            $bid['bidder']['profile_image'] = Services::fullImageURL($bid['bidder']['profile_image']);
        }

        return $this->respond([
            'status' => 200,
            'messages' => ['success' => 'OK'],
            'data' => $bid,
        ]);
    }

    public function create()
    {
        if (!$this->validate([
            'auction_id' => 'required|numeric',
            'bid_price'  => 'required|numeric',
        ])) {
            return $this->failValidationErrors(\Config\Services::validation()->getErrors());
        }

        $auctionDb = new AuctionModel;
        $checkAuction = $auctionDb->find($this->request->getVar('auction_id'));

        if (!$checkAuction) {
            return $this->failNotFound(description: 'Fallo al encontrar la subasta');
        }

        $insert = [
            'user_id'    => $this->userId,
            'auction_id' => $this->request->getVar('auction_id'),
            'bid_price'  => $this->request->getVar('bid_price'),
        ];

        $db = new BidModel;
        $save = $db->insert($insert);

        if (!$save) {
            return $this->failServerError(description: 'Fallo al colocar la oferta');
        }

        // DEBUG temporal llegan mensajes de prueba con https://dbsubasta-production.up.railway.app/api/bids y con un auth y token
        //$debugInfo = $this->_sendBidNotificationsDebug(
          //  auctionId: $this->request->getVar('auction_id'),
           // bidPrice: $this->request->getVar('bid_price'),
           // bidderUserId: $this->userId,
           // auction: $checkAuction
       // );

        return $this->respondCreated([
            'status'   => 201,
            'messages' => ['success' => 'OK'],
            'debug'    => $debugInfo, // ← temporal
        ]);
    }

    private function _sendBidNotifications(string $auctionId, string $bidPrice, string $bidderUserId, array $auction): void
    {
        try {
            $userDb = new UserModel;
            $fcm = new \App\Libraries\FCMNotification();
            $bidDb = new BidModel;

            log_message('info', "Sending bid notifications for auction $auctionId, bidder $bidderUserId");

            // 1. Notificar al dueño de la subasta
            $auctionOwner = $userDb->find($auction['user_id']);
            
            log_message('info', "Auction owner: " . json_encode([
                'user_id' => $auctionOwner['user_id'] ?? 'null',
                'has_token' => !empty($auctionOwner['fcm_token']),
                'is_same_user' => $auctionOwner['user_id'] == $bidderUserId
            ]));

            if (
                $auctionOwner &&
                $auctionOwner['fcm_token'] &&
                $auctionOwner['user_id'] != $bidderUserId
            ) {
                $result = $fcm->sendNotification(
                    fcmToken: $auctionOwner['fcm_token'],
                    title: '💰 Nueva oferta en tu subasta',
                    body: "Alguien ofreció \${$bidPrice} por \"{$auction['item_name']}\"",
                    data: ['auction_id' => $auctionId, 'type' => 'new_bid']
                );
                log_message('info', "Owner notification result: " . ($result ? 'sent' : 'failed'));
            }

            // 2. Notificar al anterior postor
            $previousBids = $bidDb->where('auction_id', $auctionId)
                ->where('user_id !=', $bidderUserId)
                ->orderBy('bid_price', 'DESC')
                ->first();

            log_message('info', "Previous bidder: " . json_encode([
                'found' => $previousBids ? true : false,
                'has_token' => $previousBids ? !empty($userDb->find($previousBids['user_id'])['fcm_token']) : false
            ]));

        } catch (\Exception $e) {
            log_message('error', 'Notification error: ' . $e->getMessage());
        }
    }

    /*private function _sendBidNotificationsDebug(string $auctionId, string $bidPrice, string $bidderUserId, array $auction): array
    {
        $debug = [];
        
        try {
            $userDb = new UserModel;
            $fcm = new \App\Libraries\FCMNotification();
            $bidDb = new BidModel;

            $auctionOwner = $userDb->find($auction['user_id']);
            
            $debug['auction_owner'] = [
                'user_id'    => $auctionOwner['user_id'] ?? null,
                'has_token'  => !empty($auctionOwner['fcm_token']),
                'is_bidder'  => ($auctionOwner['user_id'] ?? null) == $bidderUserId,
            ];

            if ($auctionOwner && $auctionOwner['fcm_token'] && $auctionOwner['user_id'] != $bidderUserId) {
                $result = $fcm->sendNotificationDebug(
                    fcmToken: $auctionOwner['fcm_token'],
                    title: '💰 Nueva oferta en tu subasta',
                    body: "Alguien ofreció \${$bidPrice}",
                );
                $debug['owner_notification'] = $result;
            } else {
                $debug['owner_notification'] = 'skipped';
            }

            $previousBid = $bidDb->where('auction_id', $auctionId)
                ->where('user_id !=', $bidderUserId)
                ->orderBy('bid_price', 'DESC')
                ->first();

            $debug['previous_bidder'] = $previousBid ? [
                'user_id'   => $previousBid['user_id'],
                'has_token' => !empty($userDb->find($previousBid['user_id'])['fcm_token']),
            ] : null;

            if ($previousBid) {
                $previousBidder = $userDb->find($previousBid['user_id']);
                if ($previousBidder && $previousBidder['fcm_token']) {
                    $result = $fcm->sendNotificationDebug(
                        fcmToken: $previousBidder['fcm_token'],
                        title: '⚡ ¡Te superaron!',
                        body: "Alguien ofreció \${$bidPrice}",
                    );
                    $debug['previous_bidder_notification'] = $result;
                }
            }

        } catch (\Exception $e) {
            $debug['error'] = $e->getMessage();
        }

        return $debug;
    }*/

    public function update($id = null)
    {
        if (!$this->validate([
            'auction_id'       => 'permit_empty|numeric',
            'bid_price'       => 'permit_empty|numeric',
        ])) {
            return $this->failValidationErrors(\Config\Services::validation()->getErrors());
        }

        $db = new BidModel;
        $exist = $db->where([
            'bid_id' => $id,
            'auction_id' => $this->request->getRawInputVar('auction_id'),
            'user_id' => $this->userId
        ])->first();

        if (!$exist) {
            return $this->failNotFound(description: 'Oferta no encontrada');
        }

        $update = [
            'bid_price' => $this->request->getRawInputVar('bid_price')
                ?? $exist['bid_price'],
        ];

        $save = $db->update($id, $update);

        if (!$save) {
            return $this->failServerError(description: 'Failed to update bid');
        }

        return $this->respondUpdated([
            'status' => 200,
            'messages' => [
                'success' => 'Bid updated successfully'
            ]
        ]);
    }

    public function delete($id = null)
    {
        $db = new BidModel;
        $exist = $db->where(['bid_id' => $id, 'user_id' => $this->userId])->first();

        if (!$exist) return $this->failNotFound(description: 'Oferta no encontrada');

        $delete = $db->delete($id);

        if (!$delete) return $this->failServerError(description: 'Fallo al eliminar la oferta');

        return $this->respondDeleted([
            'status' => 200,
            'messages' => ['success' => 'Bid successfully deleted']
        ]);
    }
}

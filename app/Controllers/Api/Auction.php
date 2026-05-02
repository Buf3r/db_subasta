<?php

namespace App\Controllers\Api;

use App\Models\AuctionModel;
use App\Models\BidModel;
use App\Models\ImageModel;
use App\Models\ItemModel;
use App\Models\UserModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class Auction extends ResourceController
{
    use ResponseTrait;

    protected String $userId;

    public function __construct()
    {
        $session = \Config\Services::session();
        $this->userId = $session->getFlashdata('user_id');
    }

    public function index()
    {
        try {
            $db = new AuctionModel;
            $city = $this->request->getGet('city');
            $appOrigin = $this->request->getGet('app_origin') ?? 'subastalo';

            $auctions = $db->getAuction(
                page: max(1, intval($this->request->getGet('page'))),
                city: $city,
                appOrigin: $appOrigin,
                allStatus: true
            );
            // En lugar de failNotFound, devolvemos un array vacío con estatus 200
            if (!$auctions) {
                // return $this->failNotFound('Auctions not found'); <--- ESTO ES LO QUE ESTÁ MATANDO LA APP
                return $this->respond([
                    'status' => 200,
                    'messages' => ['success' => 'OK'],
                    'data' => [], 
                ]);
            }

            $imageDb = new ImageModel;
            $bidDb = new BidModel;
            $userDb = new UserModel;

            foreach ($auctions as $key1 => $value1) {
                $imageArray = $imageDb->where(['item_id' => $value1['item_id']])->findAll();

                if ($imageArray) {
                    foreach ($imageArray as $key2 => $value2) {
                        $auctions[$key1]['images'][$key2]['image'] = $value2['image'];
                    }
                }

                $highestBid = $bidDb->select('MAX(bid_price) as highest_bid')
                    ->where('auction_id', $value1['auction_id'])
                    ->first();
                $auctions[$key1]['highest_bid'] = $highestBid['highest_bid'] ?? $value1['initial_price'];

                $auctions[$key1]['bid_count'] = count($bidDb->getBid(where: ['auction_id' => $auctions[$key1]['auction_id']]));
                $auctions[$key1]['author'] = $value1['user_id'] ? $userDb->getUser($value1['user_id']) : null;
                $auctions[$key1]['winner'] = $value1['winner_user_id'] ? $userDb->getUser($value1['winner_user_id']) : null;
            }

            return $this->respond([
                'status' => 200,
                'messages' => ['success' => 'OK'],
                'data' => $auctions,
            ]);
        } catch (\Throwable $e) {
            return $this->failServerError($e->getMessage() . ' in ' . $e->getFile() . ' line ' . $e->getLine());
        }
    }

    public function show($id = null)
    {
        $db = new AuctionModel;
        $auction = $db->getAuction($id, allStatus: true);

        if (!$auction) {
            return $this->failNotFound('No has creaddo ninguna subasta aún');
        }

        $userDb = new UserModel;
        $auction['author'] = $auction['user_id'] ? $userDb->getUser($auction['user_id']) : null;
        $auction['winner'] = $auction['winner_user_id'] ? $userDb->getUser($auction['winner_user_id']) : null;

        $imageDb = new ImageModel;
        $imageArray = $imageDb->where(['item_id' => $auction['item_id']])->findAll();

        if ($imageArray) {
            foreach ($imageArray as $key2 => $value2) {
                $auction['images'][$key2]['image'] = $value2['image'];
            }
        }

        $bidDb = new BidModel;
        $highestBid = $bidDb->select('MAX(bid_price) as highest_bid')
            ->where('auction_id', $auction['auction_id'])
            ->first();
        $auction['highest_bid'] = $highestBid['highest_bid'] ?? $auction['initial_price'];

        return $this->respond([
            'status' => 200,
            'messages' => ['success' => 'OK'],
            'data' => $auction,
        ]);
    }

    public function create()
    {
         if (!$this->validate([
        'item_id'        => 'required|numeric',
        'date_completed' => 'permit_empty|valid_date',
        ])) {
            return $this->failValidationErrors(\Config\Services::validation()->getErrors());
        }

        $itemDb = new ItemModel;
        $itemExist = $itemDb->where([
            'item_id' => $this->request->getVar('item_id'),
            'user_id' => $this->userId
        ])->first();

        if (!$itemExist) {
            return $this->failNotFound(description: 'No se encontró el item o no tienes permiso para subastarlo');
        }
        // 1. Verificar permisos (Sin actualizar la DB todavía)
        $userDb = new UserModel;
        $user = $userDb->find($this->userId);

        $useFree = false;
        $useCredit = false;

        if (($user['vip'] ?? 0) == 1) {
            // Es VIP: No hacemos nada, permitimos el flujo
        } elseif (($user['free_auctions_used'] ?? 0) < 2) {
            $useFree = true;
        } elseif (($user['credits'] ?? 0) > 0) {
            $useCredit = true;
        } else {
            return $this->fail('No tienes créditos ni subastas gratis disponibles.', 403);
        }

        // 2. Intentar la inserción de la subasta
        $db = new AuctionModel;
        $save = $db->insert($insert = [
            'item_id'          => $this->request->getVar('item_id'),
            'user_id'          => $this->userId,
            'status'           => 'open',
            'date_completed'   => $this->request->getVar('date_completed'),
            'delivery_method'  => $this->request->getVar('delivery_method') ?? 'personal',
        ]);

        if (!$save) {
            return $this->failServerError('Error al crear la subasta');
        }

        // 3. SI LA SUBASTA SE CREÓ, cobramos al usuario
        if ($useFree) {
            $userDb->update($this->userId, ['free_auctions_used' => $user['free_auctions_used'] + 1]);
        } elseif ($useCredit) {
            $userDb->update($this->userId, ['credits' => $user['credits'] - 1]);
        }

        return $this->respondCreated([
            'status' => 201,
            'messages' => ['success' => 'OK'],
            'data' => ['auction_id' => $db->getInsertID()],
        ]);
    }

    public function update($id = null)
    {
        if (!$this->validate([
            'status' => 'permit_empty|alpha_numeric',
        ])) {
            return $this->failValidationErrors(\Config\Services::validation()->getErrors());
        }

        $db = new AuctionModel;
        $exist = $db->getAuction($id, where: ['items.user_id' => $this->userId]);

        if (!$exist) {
            return $this->failNotFound(description: 'No se encontró la subasta o no tienes permiso para modificarla');
        }

        $update = [
            'status' => $this->request->getRawInputVar('status') ?? $exist['status'],
        ];

        $db = new AuctionModel;
        $save = $db->update($id, $update);

        if (!$save) {
            return $this->failServerError(description: 'Failed to update auction');
        }

        return $this->respondUpdated([
            'status' => 200,
            'messages' => ['success' => 'Auction updated successfully'],
        ]);
    }

    public function delete($id = null)
    {
        $db = new AuctionModel;
        $exist = $db->getAuction($id, allStatus: true);

        if (!$exist) return $this->failNotFound(description: 'No se encontró la subasta o no tienes permiso para eliminarla');

        $db = new AuctionModel;
        $delete = $db->delete($id);

        if (!$delete) return $this->failServerError(description: 'Failed to delete auction');

        return $this->respondDeleted([
            'status' => 200,
            'messages' => ['success' => 'Auction successfully deleted'],
        ]);
    }

    public function myAuctions()
    {
        $db = new AuctionModel;
        $auctions = $db->getAuction(
            where: ['items.user_id' => $this->userId],
            allStatus: true
        );

        if (!$auctions) {
            // return $this->failNotFound('Auctions not found'); <--- ESTO ES LO QUE ESTÁ MATANDO LA APP
            return $this->respond([
                'status' => 200,
                'messages' => ['success' => 'OK'],
                'data' => [], 
            ]);
        }

        $imageDb = new ImageModel;
        $bidDb = new BidModel;
        $userDb = new UserModel;

        foreach ($auctions as $key1 => $value1) {
            $imageArray = $imageDb->where(['item_id' => $value1['item_id']])->findAll();

            if ($imageArray) {
                foreach ($imageArray as $key2 => $value2) {
                    $auctions[$key1]['images'][$key2]['image'] = $value2['image'];
                }
            }

            $highestBid = $bidDb->select('MAX(bid_price) as highest_bid')
                ->where('auction_id', $value1['auction_id'])
                ->first();
            $auctions[$key1]['highest_bid'] = $highestBid['highest_bid'] ?? $value1['initial_price'];

            $auctions[$key1]['bid_count'] = count($bidDb->getBid(where: ['auction_id' => $auctions[$key1]['auction_id']]));
            $auctions[$key1]['author'] = $value1['user_id'] ? $userDb->getUser($value1['user_id']) : null;
            $auctions[$key1]['winner'] = $value1['winner_user_id'] ? $userDb->getUser($value1['winner_user_id']) : null;
        }

        return $this->respond([
            'status' => 200,
            'messages' => ['success' => 'OK'],
            'data' => $auctions,
        ]);
    }

    public function myBids()
    {
        $db = new AuctionModel;
        $auctions = $db->getBidAuctions($this->userId);

        $bidDb = new BidModel;
        $bids = $bidDb->getBid(where: ['user_id' => $this->userId]);

        $imageDb = new ImageModel;
        $userDb = new UserModel;

        $newData = [];

        foreach ($auctions as $key1 => $value1) {
            $_bids = [];

            foreach ($bids as $value2) {
                if ($value2['auction_id'] == $value1['auction_id']) {
                    array_push($_bids, $value2);
                }
            }

            $newData[$key1]['auction'] = $value1;

            $imageArray = $imageDb->where(['item_id' => $value1['item_id']])->findAll();

            if ($imageArray) {
                foreach ($imageArray as $key2 => $value2) {
                    $newData[$key1]['auction']['images'][$key2]['image'] = $value2['image'];
                }
            }

            $highestBid = $bidDb->select('MAX(bid_price) as highest_bid')
                ->where('auction_id', $value1['auction_id'])
                ->first();
            $newData[$key1]['auction']['highest_bid'] = $highestBid['highest_bid'] ?? $value1['initial_price'];

            $newData[$key1]['auction']['author'] = $value1['user_id'] ? $userDb->getUser($value1['user_id']) : null;
            $newData[$key1]['auction']['winner'] = $value1['winner_user_id'] ? $userDb->getUser($value1['winner_user_id']) : null;
            $newData[$key1]['bids'] = $_bids;
        }

        return $this->respond([
            'status' => 200,
            'messages' => ['success' => 'OK'],
            'data' => $newData,
        ]);
    }

    public function showMyAuction($id = null)
    {
        $db = new AuctionModel;
        $auction = $db->getAuction(
            $id,
            where: ['items.user_id' => $this->userId],
            allStatus: true
        );

        if (!$auction) {
            return $this->failNotFound('Subasta no encontrada');
        }

        $imageDb = new ImageModel;
        $imageArray = $imageDb->where(['item_id' => $auction['item_id']])->findAll();

        if ($imageArray) {
            foreach ($imageArray as $key2 => $value2) {
                $auction['images'][$key2]['image'] = $value2['image'];
            }
        }

        $bidDb = new BidModel;
        $highestBid = $bidDb->select('MAX(bid_price) as highest_bid')
            ->where('auction_id', $auction['auction_id'])
            ->first();
        $auction['highest_bid'] = $highestBid['highest_bid'] ?? $auction['initial_price'];

        return $this->respond([
            'status' => 200,
            'messages' => ['success' => 'OK'],
            'data' => $auction,
        ]);
    }

    public function setVip($id)
    {
        $db = new AuctionModel;
        $auction = $db->where([
            'auction_id' => $id,
            'user_id'    => $this->userId,
        ])->first();

        if (!$auction) {
            return $this->failNotFound('Subasta no encontrada');
        }

        $userDb = new UserModel;
        $user = $userDb->find($this->userId);

        if ($user['credits'] < 1) {
            return $this->fail('No tienes créditos suficientes para VIP', 403);
        }

        $now = new \DateTime();
        $end = new \DateTime('+48 hours');

        $userDb->update($this->userId, [
            'credits' => $user['credits'] - 1
        ]);

        $db->update($id, [
            'vip_start' => $now->format('Y-m-d H:i:s'),
            'vip_end'   => $end->format('Y-m-d H:i:s'),
        ]);

        return $this->respondUpdated([
            'status'   => 200,
            'messages' => ['success' => 'Auction is now VIP for 48 hours'],
        ]);
    }

    public function setWinner($id)
    {
        if (!$this->validate([
            'bid_id' => 'required|numeric',
        ])) {
            return $this->failValidationErrors(\Config\Services::validation()->getErrors());
        }

        $bidId = $this->request->getRawInputVar('bid_id');

        $bidDb = new BidModel;
        $bid = $bidDb->where(['bid_id' => $bidId])->first();

        if (!$bid) {
            return $this->failNotFound('Oferta no encontrada');
        }

        $db = new AuctionModel;
        $verifyAuction = $db->where([
            'auction_id' => $id,
            'user_id'    => $this->userId
        ])->first();

        if (!$verifyAuction) {
            return $this->failForbidden('Access Forbidden');
        }

        $update = [
            'winner_user_id' => $bid['user_id'],
            'final_price'    => $bid['bid_price'],
        ];

        $save = $db->update($id, $update);

        // Notificar al ganador
            try {
                $userDb = new UserModel;
                $winner = $userDb->find($bid['user_id']);
                if ($winner && $winner['fcm_token']) {
                    $fcm = new \App\Libraries\FCMNotification();
                    $fcm->sendNotification(
                        fcmToken: $winner['fcm_token'],
                        title: '🏆 ¡Ganaste la subasta!',
                        body: "Felicitaciones, ganaste la subasta. El vendedor se pondrá en contacto contigo.",
                        data: ['auction_id' => $id, 'type' => 'winner']
                    );
                }
            } catch (\Exception $e) {
                log_message('error', 'Winner notification error: ' . $e->getMessage());
            }

        if (!$save) {
            return $this->failServerError(description: 'Failed to set auction winner');
        }

        return $this->respondUpdated([
            'status' => 200,
            'messages' => ['success' => 'Auction winner successfully added'],
        ]);
    }

    public function close($id)
    {
        $db = new AuctionModel;
        $verifyAuction = $db->where([
            'auction_id' => $id,
            'user_id'    => $this->userId
        ])->first();

        if (!$verifyAuction) {
            return $this->failForbidden('Access Forbidden');
        }

        $update = ['status' => 'closed'];

        $save = $db->update($id, $update);

        if (!$save) {
            return $this->failServerError(description: 'Failed to set auction status');
        }

        return $this->respondUpdated([
            'status' => 200,
            'messages' => ['success' => 'Auction status successfully changed'],
        ]);
    }
}
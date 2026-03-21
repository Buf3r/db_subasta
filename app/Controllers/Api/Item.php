<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\AuctionModel;
use App\Models\ImageModel;
use App\Models\ItemModel;
use CodeIgniter\API\ResponseTrait;

class Item extends BaseController
{
    use ResponseTrait;

    protected String $userId;

    public function __construct()
    {
        helper('cloudinary');
        $session = \Config\Services::session();
        $this->userId = $session->getFlashdata('user_id');
    }

    public function index()
    {
        $db = new ItemModel;
        $items = $db->where(['user_id' => $this->userId])->findAll();

        if (!$items) {
            return $this->failNotFound('Items not found');
        }

        $auctionDb = new AuctionModel;
        $imageDb = new ImageModel;

        foreach ($items as $key1 => $value1) {
            $imageArray = $imageDb->where(['item_id' => $value1['item_id']])->findAll();

            $items[$key1]['auctioned'] = !empty($auctionDb->where(['item_id' => $value1['item_id']])->findAll(limit: 1));
            $items[$key1]['images'] = $imageArray != null ? $imageArray : [];
        }

        return $this->respond([
            'status' => 200,
            'messages' => ['success' => 'OK'],
            'data' => $items,
        ]);
    }

    public function show($id = null)
    {
        $db = new ItemModel;
        $item = $db->where(['item_id' => $id, 'user_id' => $this->userId])->first();

        if (!$item) {
            return $this->failNotFound('Item not found');
        }

        $auctionDb = new AuctionModel;
        $imageDb = new ImageModel;

        $imageArray = $imageDb->where(['item_id' => $item['item_id']])->findAll();

        $item['auctioned'] = !empty($auctionDb->where(['item_id' => $item['item_id']])->findAll(limit: 1));
        $item['images'] = $imageArray != [] ? $imageArray : null;

        return $this->respond([
            'status' => 200,
            'messages' => ['success' => 'OK'],
            'data' => $item,
        ]);
    }

    public function create()
    {
        if (!$this->validate([
            'item_name'     => 'required',
            'initial_price' => 'required|numeric',
            'location'      => 'permit_empty',
            'condition'     => 'permit_empty|in_list[new,used,refurbished]',
            'images'        => 'permit_empty|mime_in[images,image/png,image/jpeg]|is_image[images]|max_size[images,5120]',
            'category'      => 'permit_empty|in_list[tecnologia,vehiculos,hogar,ropa_accesorios,alimentos,herramientas,arte_coleccion,deportes,otros]',
        ])) {
            return $this->failValidationErrors(\Config\Services::validation()->getErrors());
        }

        $insert = [
            'user_id'       => $this->userId,
            'item_name'     => $this->request->getVar('item_name'),
            'description'   => $this->request->getVar('description'),
            'location'      => $this->request->getVar('location'),
            'condition'     => $this->request->getVar('condition'),
            'initial_price' => $this->request->getVar('initial_price'),
            'category'      => $this->request->getVar('category'),
        ];

        $db = new ItemModel;
        $save = $db->insert($insert);

        if (!$save) {
            return $this->failServerError(description: 'Failed to create item');
        }

        if ($imagefile = $this->request->getFiles()) {
            $imageDb = new ImageModel;

            foreach ($imagefile['images'] as $img) {
                if ($img->isValid() && !$img->hasMoved()) {
                    $tempPath = $img->getTempName();
                    try {
                        $cloudinaryUrl = uploadToCloudinary($tempPath, 'auction/items');
                        if (empty($cloudinaryUrl)) {
                            return $this->failServerError('Cloudinary returned empty URL for item image');
                        }
                        $imageDb->save(['item_id' => $db->getInsertID(), 'image' => $cloudinaryUrl]);
                    } catch (\Exception $e) {
                        return $this->failServerError('Cloudinary error: ' . $e->getMessage());
                    }
                }
            }
        }

        return $this->respondCreated([
            'status' => 201,
            'messages' => ['success' => 'OK'],
            'data' => ['item_id' => $db->getInsertID()],
        ]);
    }

    public function update($id = null)
    {
        if (!$this->validate([
            'item_name'     => 'permit_empty',
            'description'   => 'permit_empty',
            'location'      => 'permit_empty',
            'condition'     => 'permit_empty|in_list[new,used,refurbished]',
            'initial_price' => 'permit_empty|numeric',
            'category'      => 'permit_empty|in_list[tecnologia,vehiculos,hogar,ropa_accesorios,alimentos,herramientas,arte_coleccion,deportes,otros]',
        ])) {
            return $this->failValidationErrors(\Config\Services::validation()->getErrors());
        }

        $db = new ItemModel;
        $exist = $db->where(['item_id' => $id, 'user_id' => $this->userId])->first();

        if (!$exist) {
            return $this->failNotFound(description: 'Item not found');
        }

        $update = [
            'item_name'     => $this->request->getRawInputVar('item_name') ?? $exist['item_name'],
            'description'   => $this->request->getRawInputVar('description') ?? $exist['description'],
            'location'      => $this->request->getRawInputVar('location') ?? $exist['location'],
            'condition'     => $this->request->getRawInputVar('condition') ?? $exist['condition'],
            'initial_price' => $this->request->getRawInputVar('initial_price') ?? $exist['initial_price'],
            'category'      => $this->request->getRawInputVar('category') ?? $exist['category'],
        ];

        $save = $db->update($id, $update);

        if (!$save) {
            return $this->failServerError(description: 'Failed to update item');
        }

        return $this->respondUpdated([
            'status' => 200,
            'messages' => ['success' => 'Item updated successfully'],
        ]);
    }

    public function updateItemImages($id = null)
    {
        if (!$this->validate([
            'former_images_id' => 'permit_empty',
            'images'           => 'permit_empty|mime_in[images,image/png,image/jpeg]|is_image[images]|max_size[images,5120]',
        ])) {
            return $this->failValidationErrors(\Config\Services::validation()->getErrors());
        }

        $db = new ItemModel;
        $exist = $db->where(['item_id' => $id, 'user_id' => $this->userId])->first();

        if (!$exist) {
            return $this->failNotFound(description: 'Item not found');
        }

        $removedCount = 0;
        $addedCount = 0;

        if ($this->request->getVar('former_images_id')) {
            $formerImageIds = json_decode($this->request->getVar('former_images_id'));
            $imageDb = new ImageModel;
            $images = $imageDb->where(['item_id' => $id])->findAll();

            foreach ($images as $image) {
                if (!in_array($image['image_id'], $formerImageIds)) {
                    deleteFromCloudinary($image['image']);
                    $imageDb->delete($image['image_id']);
                    $removedCount++;
                }
            }
        }

        if ($imagefile = $this->request->getFiles()) {
            $imageDb = new ImageModel;

            foreach ($imagefile['images'] as $img) {
                if ($img->isValid() && !$img->hasMoved()) {
                    $tempPath = $img->getTempName();
                    $cloudinaryUrl = uploadToCloudinary($tempPath, 'auction/items');
                    $imageDb->save(['item_id' => $id, 'image' => $cloudinaryUrl]);
                    $addedCount++;
                }
            }
        }

        return $this->respondUpdated([
            'status' => 200,
            'messages' => ['success' => 'Item images updated successfully'],
            'removed' => $removedCount,
            'added'   => $addedCount,
        ]);
    }

    public function delete($id = null)
    {
        $db = new ItemModel;
        $exist = $db->where(['item_id' => $id, 'user_id' => $this->userId])->first();

        if (!$exist) return $this->failNotFound(description: 'Item not found');

        $imageDb = new ImageModel;
        $images = $imageDb->where(['item_id' => $id])->findAll();

        foreach ($images as $image) {
            deleteFromCloudinary($image['image']);
            $imageDb->delete($image['image_id']);
        }

        $delete = $db->delete($id);

        if (!$delete) return $this->failServerError(description: 'Failed to delete item');

        return $this->respondDeleted([
            'status' => 200,
            'messages' => ['success' => 'Item successfully deleted'],
        ]);
    }
}
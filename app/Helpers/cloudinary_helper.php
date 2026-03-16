<?php

use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;

function uploadToCloudinary(string $filePath, string $folder = 'auction'): string
{
    $cloudName = getenv('CLOUDINARY_CLOUD_NAME');
    $apiKey = getenv('CLOUDINARY_API_KEY');
    $apiSecret = getenv('CLOUDINARY_API_SECRET');

    $cloudinary = new Cloudinary(
        "cloudinary://{$apiKey}:{$apiSecret}@{$cloudName}"
    );

    $result = $cloudinary->uploadApi()->upload($filePath, [
        'folder' => $folder,
    ]);

    return $result['secure_url'];
}

function deleteFromCloudinary(string $imageUrl): void
{
    if (empty($imageUrl)) return;

    $cloudName = getenv('CLOUDINARY_CLOUD_NAME');
    $apiKey = getenv('CLOUDINARY_API_KEY');
    $apiSecret = getenv('CLOUDINARY_API_SECRET');

    $cloudinary = new Cloudinary(
        "cloudinary://{$apiKey}:{$apiSecret}@{$cloudName}"
    );

    $parts = explode('/', $imageUrl);
    $filename = pathinfo(end($parts), PATHINFO_FILENAME);
    $folder = $parts[count($parts) - 2];
    $publicId = $folder . '/' . $filename;

    $cloudinary->uploadApi()->destroy($publicId);
}
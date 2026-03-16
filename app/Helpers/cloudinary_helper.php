<?php

use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;

function uploadToCloudinary(string $filePath, string $folder = 'auction'): string
{
    $cloudName = getenv('CLOUDINARY_CLOUD_NAME');
    $apiKey = getenv('CLOUDINARY_API_KEY');
    $apiSecret = getenv('CLOUDINARY_API_SECRET');

    // DEBUG
    file_put_contents('/tmp/cloudinary_debug.txt', "cloudName: $cloudName\napiKey: $apiKey\nfilePath: $filePath\n");

    $cloudinary = new Cloudinary(
        "cloudinary://{$apiKey}:{$apiSecret}@{$cloudName}"
    );

    try {
        $result = $cloudinary->uploadApi()->upload($filePath, [
            'folder' => $folder,
        ]);
        file_put_contents('/tmp/cloudinary_debug.txt', "result: " . json_encode($result) . "\n", FILE_APPEND);
        return $result['secure_url'];
    } catch (\Exception $e) {
        file_put_contents('/tmp/cloudinary_debug.txt', "error: " . $e->getMessage() . "\n", FILE_APPEND);
        throw $e;
    }
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
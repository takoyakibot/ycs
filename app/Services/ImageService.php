<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ImageService
{
    protected $imageManager;

    public function __construct(Driver $driver)
    {
        $this->imageManager = new ImageManager($driver);
    }

    public function downloadThumbnail($thumbnailUrl)
    {
        // 画像データを取得
        $response = Http::get($thumbnailUrl);

        if ($response->ok()) {
            // マネージャーから画像を作成
            $image = $this->imageManager
                ->read($response->body())
                ->resize(30, 30);

            // Base64形式にエンコード
            return $image->toJpeg()->toDataUri();
        }

        return null; // エラー時はnullを返す
    }
}

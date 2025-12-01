<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ImageService
{
    /** サムネイル画像のキャッシュ時間（7日間） */
    private const THUMBNAIL_CACHE_TTL = 60 * 60 * 24 * 7;

    protected $imageManager;

    public function __construct(Driver $driver)
    {
        $this->imageManager = new ImageManager($driver);
    }

    /**
     * サムネイル画像をダウンロードしてBase64形式で返す
     *
     * 画像は7日間キャッシュされます。
     *
     * @param  string  $thumbnailUrl  サムネイル画像のURL
     * @return string|null Base64エンコードされた画像データ、エラー時はnull
     */
    public function downloadThumbnail(string $thumbnailUrl): ?string
    {
        if (empty($thumbnailUrl)) {
            return null;
        }

        // URLからキャッシュキーを生成（URLのハッシュを使用）
        $cacheKey = 'thumbnail_'.md5($thumbnailUrl);

        // キャッシュから取得を試みる
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // 画像データを取得
        $response = Http::get($thumbnailUrl);

        if ($response->ok()) {
            // マネージャーから画像を作成
            $image = $this->imageManager
                ->read($response->body())
                ->resize(30, 30);

            // Base64形式にエンコード
            $dataUri = $image->toJpeg()->toDataUri();

            // キャッシュに保存（7日間）
            Cache::put($cacheKey, $dataUri, self::THUMBNAIL_CACHE_TTL);

            return $dataUri;
        }

        return null; // エラー時はnullを返す
    }
}

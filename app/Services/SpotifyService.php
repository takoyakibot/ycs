<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpotifyService
{
    private $clientId;

    private $clientSecret;

    private $accessToken;

    public function __construct()
    {
        $this->clientId = config('services.spotify.client_id');
        $this->clientSecret = config('services.spotify.client_secret');
    }

    /**
     * Spotify APIのアクセストークンを取得
     */
    private function getAccessToken()
    {
        // キャッシュからトークンを取得（有効期限は1時間）
        $cachedToken = Cache::get('spotify_access_token');
        if ($cachedToken) {
            return $cachedToken;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->post('https://accounts.spotify.com/api/token', [
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->accessToken = $data['access_token'];

                // トークンをキャッシュに保存（有効期限の少し前まで）
                Cache::put('spotify_access_token', $this->accessToken, now()->addSeconds($data['expires_in'] - 60));

                return $this->accessToken;
            }
        } catch (Exception $e) {
            Log::error('Spotify API token error: '.$e->getMessage());
        }

        throw new Exception('Spotify API access token取得に失敗しました');
    }

    /**
     * 楽曲検索
     */
    public function searchTracks($query, $limit = 20)
    {
        try {
            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken)
                ->get('https://api.spotify.com/v1/search', [
                    'q' => $query,
                    'type' => 'track',
                    'limit' => $limit,
                    'market' => 'JP',
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return $this->formatTracks($data['tracks']['items'] ?? []);
            }
        } catch (Exception $e) {
            Log::error('Spotify API search error: '.$e->getMessage());
        }

        return [];
    }

    /**
     * 楽曲データをフォーマット
     */
    private function formatTracks($tracks)
    {
        return collect($tracks)->map(function ($track) {
            return [
                'id' => $track['id'],
                'name' => $track['name'],
                'artist' => collect($track['artists'])->pluck('name')->implode(', '),
                'album' => $track['album']['name'] ?? '',
                'preview_url' => $track['preview_url'],
                'spotify_url' => $track['external_urls']['spotify'] ?? '',
                'uri' => $track['uri'],
                'image_url' => $track['album']['images'][0]['url'] ?? null,
                'duration_ms' => $track['duration_ms'],
                'raw_data' => $track,
            ];
        })->toArray();
    }

    /**
     * 楽曲IDから詳細情報を取得
     */
    public function getTrack($trackId)
    {
        try {
            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken)
                ->get("https://api.spotify.com/v1/tracks/{$trackId}", [
                    'market' => 'JP',
                ]);

            if ($response->successful()) {
                $track = $response->json();

                return $this->formatTracks([$track])[0];
            }
        } catch (Exception $e) {
            Log::error('Spotify API track get error: '.$e->getMessage());
        }

        return null;
    }
}

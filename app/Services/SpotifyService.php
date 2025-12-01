<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpotifyService
{
    private const CACHE_KEY = 'spotify_access_token';

    private const CACHE_TTL_BUFFER = 300; // 期限の5分前にキャッシュ期限切れとする

    private $accessToken;

    /**
     * Spotifyアクセストークンを取得
     */
    public function authenticate($clientId, $clientSecret)
    {
        // キャッシュからトークンを取得
        $cachedToken = Cache::get(self::CACHE_KEY);
        if ($cachedToken) {
            $this->accessToken = $cachedToken;

            return true;
        }

        // キャッシュにない場合はAPIから取得
        $response = Http::asForm()->post('https://accounts.spotify.com/api/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $this->accessToken = $data['access_token'];
            $expiresIn = $data['expires_in'] ?? 3600;

            // 期限の5分前までキャッシュに保存
            $cacheTtl = max(0, $expiresIn - self::CACHE_TTL_BUFFER);
            if ($cacheTtl > 0) {
                Cache::put(self::CACHE_KEY, $this->accessToken, $cacheTtl);
            }

            return true;
        }

        // レート制限エラーをチェック
        if ($response->status() === 429) {
            Log::warning('Spotify API rate limit exceeded in authenticate', [
                'status' => $response->status(),
            ]);
            throw new \Exception('Spotify APIのレート制限に達しました。しばらく待ってからお試しください。');
        }

        throw new \Exception('Spotify authentication failed with status '.$response->status());
    }

    /**
     * 認証してから楽曲を検索（一連の処理を統合）
     *
     * @param  string  $query  検索クエリ
     * @param  int  $limit  取得件数（デフォルト: 10）
     * @return array 検索結果のトラック配列
     *
     * @throws \Exception 認証失敗または検索失敗時
     */
    public function searchWithAuth(string $query, int $limit = 10): array
    {
        $clientId = config('services.spotify.client_id');
        $clientSecret = config('services.spotify.client_secret');

        if (! $clientId || ! $clientSecret) {
            Log::error('Spotify API credentials are not configured');
            throw new \Exception('Spotify API credentials are not configured.');
        }

        // 認証処理
        try {
            $this->authenticate($clientId, $clientSecret);
        } catch (\Exception $e) {
            Log::error('Spotify authentication failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // レート制限エラーの場合はそのまま再スロー
            if (strpos($e->getMessage(), 'レート制限') !== false) {
                throw $e;
            }
            throw new \Exception('Spotify API認証に失敗しました。認証情報を確認してください。');
        }

        // 検索処理
        try {
            return $this->searchTracks($query, $limit);
        } catch (\Exception $e) {
            Log::error('Spotify search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // レート制限エラーの場合はそのまま再スロー
            if (strpos($e->getMessage(), 'レート制限') !== false) {
                throw $e;
            }
            throw new \Exception('Spotify API検索に失敗しました。しばらくしてから再度お試しください。');
        }
    }

    /**
     * 楽曲を検索
     *
     * market=JP を指定することで、日本市場向けのローカライズされた
     * アーティスト名が返される可能性があります。
     *
     * @throws \Exception レート制限エラー時
     */
    public function searchTracks($query, $limit = 10)
    {
        if (! $this->accessToken) {
            throw new \Exception('Spotify API is not authenticated.');
        }

        $response = Http::withToken($this->accessToken)->get('https://api.spotify.com/v1/search', [
            'q' => $query,
            'type' => 'track',
            'limit' => $limit,
            'market' => 'JP',
        ]);

        if ($response->successful()) {
            return $response->json()['tracks']['items'];
        }

        // レート制限エラーをチェック
        if ($response->status() === 429) {
            Log::warning('Spotify API rate limit exceeded in searchTracks', [
                'query' => $query,
                'status' => $response->status(),
            ]);
            throw new \Exception('Spotify APIのレート制限に達しました。しばらく待ってからお試しください。');
        }

        throw new \Exception('Spotify search request failed with status '.$response->status());
    }

    /**
     * トラック情報を取得
     *
     * market=JP を指定することで、日本市場向けのローカライズされた
     * アーティスト名が返される可能性があります。
     *
     * @throws \Exception レート制限エラー時
     */
    public function getTrack($trackId)
    {
        if (! $this->accessToken) {
            throw new \Exception('Spotify API is not authenticated.');
        }

        $response = Http::withToken($this->accessToken)->get("https://api.spotify.com/v1/tracks/{$trackId}", [
            'market' => 'JP',
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        // レート制限エラーをチェック
        if ($response->status() === 429) {
            Log::warning('Spotify API rate limit exceeded in getTrack', [
                'track_id' => $trackId,
                'status' => $response->status(),
            ]);
            throw new \Exception('Spotify APIのレート制限に達しました。しばらく待ってからお試しください。');
        }

        throw new \Exception('Spotify get track request failed with status '.$response->status());
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpotifyService
{
    private $accessToken;

    /**
     * Spotifyアクセストークンを取得
     */
    public function authenticate($clientId, $clientSecret)
    {
        $response = Http::asForm()->post('https://accounts.spotify.com/api/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if ($response->successful()) {
            $this->accessToken = $response->json()['access_token'];

            return true;
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
            throw new \Exception('Spotify API authentication failed. Please check your credentials.');
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
            throw new \Exception('Spotify API search failed. Please try again later.');
        }
    }

    /**
     * 楽曲を検索
     *
     * market=JP を指定することで、日本市場向けのローカライズされた
     * アーティスト名が返される可能性があります。
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

        throw new \Exception('Spotify search request failed with status '.$response->status());
    }

    /**
     * トラック情報を取得
     *
     * market=JP を指定することで、日本市場向けのローカライズされた
     * アーティスト名が返される可能性があります。
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

        throw new \Exception('Spotify get track request failed with status '.$response->status());
    }
}

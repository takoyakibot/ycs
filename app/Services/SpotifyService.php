<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

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

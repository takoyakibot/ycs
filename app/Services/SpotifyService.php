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

        return false;
    }

    /**
     * 楽曲を検索
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
        ]);

        if ($response->successful()) {
            return $response->json()['tracks']['items'];
        }

        return [];
    }

    /**
     * トラック情報を取得
     */
    public function getTrack($trackId)
    {
        if (! $this->accessToken) {
            throw new \Exception('Spotify API is not authenticated.');
        }

        $response = Http::withToken($this->accessToken)->get("https://api.spotify.com/v1/tracks/{$trackId}");

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }
}

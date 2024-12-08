<?php

namespace App\Services;

use Google_Client;
use Google_Service_YouTube;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

class YouTubeService
{
    protected $client;
    protected $youtube;

    public function __construct()
    {
        $this->client = new Google_Client();
    }

    public function setApiKey()
    {
        // 定義済みの場合は終了
        if ($this->youtube) {
            return;
        }
        $apiKey = Crypt::decryptString(Auth::user()->api_key);
        $this->client->setDeveloperKey($apiKey);
        $this->youtube = new Google_Service_YouTube($this->client);
    }

    public function getChannelByHandle($handle)
    {
        $this->setApiKey();

        $response = $this->youtube->channels->listChannels('snippet', [
            'forHandle' => $handle,
        ]);

        // 検索結果が存在するかを確認
        if (count($response->getItems()) > 0) {
            $channel = $response->getItems()[0];
            return [
                'title' => $channel['snippet']['title'],
                'channel_id' => $channel['id'],
                'thumbnail' => $channel['snippet']['thumbnails']['default']['url'],
            ];
        }

        return null; // 該当するチャンネルが見つからない場合
    }
}

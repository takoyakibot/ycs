<?php

namespace App\Services;

use Google_Client;
use Google_Service_YouTube;

class YouTubeService
{
    protected $client;
    protected $youtube;

    public function __construct()
    {
        $this->client = new Google_Client();
    }

    public function setApiKey($apiKey)
    {
        $this->client->setDeveloperKey($apiKey);
        $this->youtube = new Google_Service_YouTube($this->client);
    }

    public function getChannelByHandle($handle)
    {
        // ハンドル名の「@」を取り除く
        $query = ltrim($handle, '@');

        // YouTube APIの `search` エンドポイントを使用して検索
        $response = $this->youtube->search->listSearch('snippet', [
            'q' => $query,
            'type' => 'channel',
            'part' => 'snippet',
            'maxResults' => 1, // 必要なチャンネルのみ取得
        ]);

        // 検索結果が存在するかを確認
        if (count($response->getItems()) > 0) {
            $channel = $response->getItems()[0];
            return [
                'title' => $channel['snippet']['title'],
                'channel_id' => $channel['id']['channelId'],
                'thumbnail' => $channel['snippet']['thumbnails']['default']['url'],
            ];
        }

        return null; // 該当するチャンネルが見つからない場合
    }
}

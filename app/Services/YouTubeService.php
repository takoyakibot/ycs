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

    public function getArchives($channel_id)
    {
        $this->setApiKey();

        // チャンネルIDの先頭2文字をUUに置き換える
        $playlist_id = 'UU' . substr($channel_id, 2);

        // nextPageTokenが取得できなくなるまでループ
        $response = null;
        $archives = [];
        do {
            $response = $this->youtube->playlistItems->listPlaylistItems('snippet,contentDetails', [
                'playlistId' => $playlist_id,
                'maxResults' => 20,
                'pageToken' => $response ? $response->getNextPageToken() : "",
            ]);

            foreach ($response->getItems() as $item) {
                $comments = [];
                $comments = $this->getTimeStampsFromText($item['snippet']['description']);

                $archives[] = [
                    'channel_id' => $channel_id,
                    'archive_id' => $item['contentDetails']['videoId'],
                    'archive_name' => $item['snippet']['title'],
                    'comments' => $comments,
                ];
            }
            // TODO: テスト用にブレイクする
            if (count($archives) >= 40) {
                break;
            }
        } while (!empty($response->getNextPageToken()));

        return $archives;
    }

    private function getTimeStampsFromText($description): array
    {
        // 正規表現でタイムスタンプを抽出 (MM:SS または HH:MM:SS)
        $pattern = '/\b(\d{1,2}:\d{2}(?::\d{2})?)\b/';
        $lines = explode("\n", $description); // 改行で分割
        $results = [];

        foreach ($lines as $line) {
            // 各行からタイムスタンプを抽出
            if (preg_match($pattern, $line, $matches)) {
                $timestamp = $matches[1]; // タイムスタンプ部分
                $comment = trim(str_replace($timestamp, '', $line)); // タイムスタンプを除外した部分

                // 結果に追加
                $results[] = [
                    'timestamp' => $timestamp,
                    'seconds' => $this->timestampToSeconds($timestamp),
                    'comment' => $comment
                ];
            }
        }
        return $results;
    }
    private function timestampToSeconds($timestamp): int
    {
        $parts = explode(':', $timestamp);
        $count = count($parts);

        if ($count === 2) {
            return ($parts[0] * 60) + $parts[1];
        } elseif ($count === 3) {
            return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }

        return 0; // 不正なフォーマットの場合
    }
}

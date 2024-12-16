<?php

namespace App\Services;

use Carbon\Carbon;
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

    public function getArchivesAndTsItems($channel_id)
    {
        $this->setApiKey();

        // チャンネルIDの先頭2文字をUUに置き換える
        $playlist_id = 'UU' . substr($channel_id, 2);

        // nextPageTokenが取得できなくなるまでループ
        $maxResults = config('app.debug') ? 2 : 50;
        $response = null;
        $archives = [];
        $ts_items = [];
        do {
            $response = $this->youtube->playlistItems->listPlaylistItems('snippet', [
                'playlistId' => $playlist_id,
                'maxResults' => 2,
                'pageToken' => $response ? $response->getNextPageToken() : "",
            ]);

            foreach ($response->getItems() as $item) {
                $ts_items_tmp = $this->getTimeStampsFromText(
                    $item['snippet']['resourceId']['videoId'],
                    '1', // description
                    $item['snippet']['description']
                );
                foreach ($ts_items_tmp as $ts_item_tmp) {
                    $ts_items[] = $ts_item_tmp;
                }

                $archives[] = [
                    'channel_id' => $channel_id,
                    'video_id' => $item['snippet']['resourceId']['videoId'],
                    'title' => $item['snippet']['title'],
                    'thumbnail' => $item['snippet']['thumbnails']['default']['url'],
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => Carbon::parse($item['snippet']['publishedAt'])->format('Y-m-d H:i:s'),
                    'comments_updated_at' => today(),
                ];
            }
            if (config('app.debug') && count($archives) >= 4) {
                break;
            }
        } while (!empty($response->getNextPageToken()));

        return [$archives, $ts_items];
    }

    private function getTimeStampsFromText($video_id, $type, $description): array
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
                    'video_id' => $video_id,
                    'type' => $type,
                    'ts_text' => $timestamp,
                    'ts_num' => $this->timestampToSeconds($timestamp),
                    'text' => $comment,
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

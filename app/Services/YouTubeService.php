<?php

namespace App\Services;

use Carbon\Carbon;
use Exception;
use Google_Client;
use Google_Service_YouTube;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

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

        $archives = $this->getArchives($channel_id);
        $rtn_archives = [];
        foreach ($archives as &$archive) {
            $archive['ts_items'] = $this->getTimeStampsFromText(
                $archive['video_id'],
                '1', // description
                $archive['description'],
            );
            // タイムスタンプがなかった場合はコメントを検索する
            if (empty($archive['ts_items'])) {
                // コメントを個別取得のみにする場合はここをコメントアウト
                $archive['ts_items'] = $this->getTimeStampsFromComments($archive['video_id']);
            }
            $rtn_archives[] = $archive;
        }
        return $rtn_archives;
    }

    private function getArchives($channel_id)
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
                'maxResults' => $maxResults,
                'pageToken' => $response ? $response->getNextPageToken() : "",
            ]);

            foreach ($response->getItems() as $item) {
                $archives[] = [
                    'channel_id' => $channel_id,
                    'video_id' => $item['snippet']['resourceId']['videoId'],
                    'title' => $item['snippet']['title'],
                    'thumbnail' => $item['snippet']['thumbnails']['medium']['url'],
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => Carbon::parse($item['snippet']['publishedAt'])->format('Y-m-d H:i:s'),
                    'comments_updated_at' => today(),
                    'description' => $item['snippet']['description'],
                ];
            }
            if (config('app.debug') && count($archives) >= 4) {
                break;
            }
        } while ($response->getNextPageToken());

        return $archives;
    }

    private function getTimeStampsFromText($video_id, $type, $description): array
    {
        // 引数のバリデーション
        // 最低限のチェック
        if (!is_string($video_id) || !is_string($description)) {
            // 無効なデータが来た場合、空の結果を返却
            error_log("Invalid video_id or description:"
                . var_export($video_id, true) . ", " . var_export($description, true));
            return [];
        }

        if (!in_array($type, [1, 2], true)) {
            // タイプが不正ならデフォルト値にする（例えば1）
            error_log("Invalid type" . var_export($type, true));
            return [];
        }

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
                    'id' => Str::ulid(),
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

    private function getTimeStampsFromComments($video_id)
    {
        $comments = [];
        $response = null;
        do {
            // リクエストパラメータを設定
            $params = [
                'videoId' => $video_id,
                'part' => 'snippet,replies', // コメントのスニペットとリプライを取得
                'maxResults' => 100, // 1回のリクエストで取得するコメント数
                'pageToken' => $response ? $response->getNextPageToken() : "",
            ];

            try {
                // コメントスレッドを取得
                $response = $this->youtube->commentThreads->listCommentThreads('snippet,replies', $params);
            } catch (Exception $e) {
                // コメントが無効な場合はスキップ
                if (strpos($e->getMessage(), 'has disabled comments') !== false) {
                    continue;
                }
            }

            // 各コメントを処理
            foreach ($response->getItems() as $item) {
                $topLevelComment = $item['snippet']['topLevelComment']['snippet']['textOriginal'];
                $comments[] = $topLevelComment;

                // リプライコメントがある場合
                if (!empty($item['replies']['comments'])) {
                    foreach ($item['replies']['comments'] as $reply) {
                        $comments[] = $reply['snippet']['textOriginal'];
                    }
                }
            }
            // 次のページトークンを取得
        } while ($response && $response->getNextPageToken());

        $rtn_ts_items = [];
        foreach ($comments as $comment) {
            $ts_items = $this->getTimeStampsFromText(
                $video_id,
                '2', // comment
                $comment,
            );
            foreach ($ts_items as $ts_item) {
                $rtn_ts_items[] = $ts_item;
            }
        }

        return $rtn_ts_items;
    }
}

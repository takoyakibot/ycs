<?php

namespace App\Services;

use Carbon\Carbon;
use Exception;
use Google\Client as Google_Client;
use Google\Service\YouTube as Google_Service_YouTube;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * YouTube Data API v3 通信サービス
 *
 * APIキーを使用してYouTube APIへの通信のみを担当
 */
class YouTubeApiService
{
    protected $client;

    protected $youtube;

    public function __construct()
    {
        $this->client = new Google_Client;
    }

    /**
     * APIキーを設定してYouTube APIクライアントを初期化
     *
     * @throws Exception APIキーが設定されていない、または無効な場合
     */
    public function setApiKey(): void
    {
        // 定義済みの場合は終了
        if ($this->youtube) {
            return;
        }

        $user = Auth::user();

        if (! $user || ! $user->api_key) {
            throw new Exception('APIキーが設定されていません。プロフィール画面でYouTube Data APIキーを登録してください。');
        }

        // モデルのcastsで自動復号化されるため、Crypt::decryptString()は不要
        $this->client->setDeveloperKey($user->api_key);
        $this->youtube = new Google_Service_YouTube($this->client);
    }

    /**
     * ハンドル名からチャンネル情報を取得
     *
     * @param  string  $handle  チャンネルハンドル
     * @return array|null チャンネル情報、見つからない場合null
     */
    public function getChannelByHandle(string $handle): ?array
    {
        $this->setApiKey();

        $response = $this->youtube->channels->listChannels('snippet', [
            'forHandle' => $handle,
        ]);

        // 検索結果が存在するかを確認
        if (count($response->getItems()) > 0) {
            $channel = $response->getItems()[0];

            // 安全なアクセサーメソッドを使用
            $snippet = $channel->getSnippet();
            $thumbnails = $snippet ? $snippet->getThumbnails() : null;
            $defaultThumb = $thumbnails ? ($thumbnails->getDefault() ? $thumbnails->getDefault()->getUrl() : null) : null;

            return [
                'title' => $snippet ? $snippet->getTitle() : '',
                'channel_id' => $channel->getId(),
                'thumbnail' => $defaultThumb ?? '',
            ];
        }

        return null; // 該当するチャンネルが見つからない場合
    }

    /**
     * チャンネルIDからアーカイブ一覧を取得
     *
     * @param  string  $channelId  チャンネルID
     * @return array アーカイブ配列
     */
    public function getArchives(string $channelId): array
    {
        $this->setApiKey();

        // チャンネルIDの先頭2文字をUUに置き換える
        $playlistId = 'UU'.substr($channelId, 2);

        // nextPageTokenが取得できなくなるまでループ
        $maxResults = App::environment('local') ? config('utils.page') : 50;
        $response = null;
        $archives = [];
        do {
            $response = $this->youtube->playlistItems->listPlaylistItems('snippet', [
                'playlistId' => $playlistId,
                'maxResults' => $maxResults,
                'pageToken' => $response ? $response->getNextPageToken() : '',
            ]);

            if (is_array($response->getItems())) {
                foreach ($response->getItems() as $item) {
                    $snippet = $item->getSnippet();
                    $resourceId = $snippet?->getResourceId();
                    $mediumThumb = $snippet?->getThumbnails()?->getMedium()?->getUrl() ?? '';

                    $archives[] = [
                        'id' => Str::ulid(),
                        'channel_id' => $channelId,
                        'video_id' => $resourceId?->getVideoId() ?? '',
                        'title' => $snippet?->getTitle() ?? '',
                        'thumbnail' => $mediumThumb,
                        'is_public' => true,
                        'is_display' => true,
                        'published_at' => ($snippet && ($publishedAt = $snippet->getPublishedAt()))
                            ? Carbon::parse($publishedAt)->format('Y-m-d H:i:s')
                            : now()->format('Y-m-d H:i:s'),
                        'comments_updated_at' => today(),
                        'description' => $snippet?->getDescription() ?? '',
                    ];
                }
            }
            if (App::environment('local') && count($archives) >= config('utils.max_archive_count')) {
                break;
            }
        } while ($response->getNextPageToken());

        return $archives;
    }

    /**
     * 動画IDからコメント一覧を取得
     *
     * @param  string  $videoId  動画ID
     * @return array コメント配列
     */
    public function getComments(string $videoId): array
    {
        $this->setApiKey();

        $comments = [];
        $response = null;
        do {
            // リクエストパラメータを設定
            $params = [
                'videoId' => $videoId,
                'part' => 'snippet,replies', // コメントのスニペットとリプライを取得
                'maxResults' => 100,               // 1回のリクエストで取得するコメント数
                'pageToken' => $response ? $response->getNextPageToken() : '',
            ];

            try {
                // コメントスレッドを取得
                $response = $this->youtube->commentThreads->listCommentThreads('snippet', $params);
            } catch (Exception $e) {
                // コメントが無効な場合はスキップ
                if (strpos($e->getMessage(), 'has disabled comments') !== false) {
                    Log::info('YouTube API: Comments disabled for video', [
                        'video_id' => $videoId,
                    ]);
                    break; // コメントが無効の場合はループを抜ける
                } else {
                    Log::error('YouTube API: Failed to fetch comments', [
                        'video_id' => $videoId,
                        'error' => $e->getMessage(),
                        'code' => $e->getCode(),
                    ]);
                    break; // その他のエラーもループを抜ける
                }
            }

            // レスポンスがない場合はスキップ
            if (! $response || ! $response->getItems()) {
                break;
            }

            // 各コメントを処理
            foreach ($response->getItems() as $item) {
                $commentId = $item->getId();
                $snippet = $item->getSnippet();
                $topLevelComment = $snippet ? $snippet->getTopLevelComment() : null;
                $topLevelSnippet = $topLevelComment ? $topLevelComment->getSnippet() : null;
                $textOriginal = $topLevelSnippet ? $topLevelSnippet->getTextOriginal() : '';

                $comments[] = [
                    'id' => $commentId,
                    'description' => $textOriginal,
                ];
            }
            // 次のページトークンを取得
        } while ($response && $response->getNextPageToken());

        return $comments;
    }
}

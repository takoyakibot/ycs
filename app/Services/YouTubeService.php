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
 * YouTube Data API v3 サービス
 *
 * Google OAuth認証を使用してYouTube APIにアクセスします。
 * 従来のAPIキー方式は廃止され、OAuth専用となっています。
 */
class YouTubeService
{
    protected $client;

    protected $youtube;

    public function __construct()
    {
        $this->client = new Google_Client;
    }

    /**
     * Google OAuth認証を設定
     *
     * トークンが期限切れの場合は自動的にリフレッシュします。
     */
    private function setAuth()
    {
        // 定義済みの場合は終了
        if ($this->youtube) {
            return;
        }

        $user = Auth::user();

        // Google OAuthトークンを使用（必須）
        if ($user->google_token) {
            try {
                $this->client->setAccessToken($user->google_token);

                // トークン期限切れの場合はリフレッシュ
                if ($this->client->isAccessTokenExpired()) {
                    if (! $user->google_refresh_token) {
                        Log::error('Google OAuth: Refresh token not found', [
                            'user_id' => $user->id,
                            'user_email' => $user->email,
                        ]);
                        throw new Exception('Google OAuth refresh token not found. Please re-authenticate with Google.');
                    }

                    Log::info('Google OAuth: Refreshing access token', [
                        'user_id' => $user->id,
                    ]);

                    try {
                        $this->client->fetchAccessTokenWithRefreshToken($user->google_refresh_token);
                        $newToken = $this->client->getAccessToken();

                        if (isset($newToken['error'])) {
                            Log::error('Google OAuth: Token refresh failed', [
                                'user_id' => $user->id,
                                'error' => $newToken['error'],
                                'error_description' => $newToken['error_description'] ?? null,
                            ]);
                            throw new Exception('Failed to refresh Google OAuth token: '.$newToken['error']);
                        }

                        // 新しいトークンをDB保存（トークン全体を保存）
                        $user->update(['google_token' => $newToken]);

                        Log::info('Google OAuth: Access token refreshed successfully', [
                            'user_id' => $user->id,
                        ]);
                    } catch (Exception $e) {
                        Log::error('Google OAuth: Exception during token refresh', [
                            'user_id' => $user->id,
                            'exception' => $e->getMessage(),
                        ]);
                        throw new Exception('Failed to refresh Google OAuth token. Please re-authenticate with Google.');
                    }
                }
            } catch (Exception $e) {
                Log::error('Google OAuth: Error setting access token', [
                    'user_id' => $user->id,
                    'exception' => $e->getMessage(),
                ]);
                throw $e;
            }
        } else {
            Log::warning('Google OAuth: Token not found', [
                'user_id' => $user->id,
                'user_email' => $user->email,
            ]);
            throw new Exception('Google OAuth token not found. Please log in again.');
        }

        $this->youtube = new Google_Service_YouTube($this->client);
    }

    public function getChannelByHandle($handle)
    {
        $this->setAuth();

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

    public function getArchivesAndTsItems($channel_id)
    {
        $this->setAuth();

        $archives = $this->getArchives($channel_id);
        $rtn_archives = [];
        foreach ($archives as &$archive) {
            // 概要欄に存在するタイムスタンプをts_itemsとして取得する
            // 概要欄なので、comment_idにvideo_idを設定している
            // typeがあるんだからいいじゃないかという気がするがchangeListの管理方法とズレているためこんなことになっている
            $archive['ts_items'] = $this->getTimeStampsFromText(
                $archive['video_id'],
                '1', // description
                $archive['description'],
                $archive['video_id'],
            );
            // 歌枠の場合は一旦表示にする
            $archive['is_display'] = $this->isSingingStream($archive['title']);
            // コメントを個別取得のみにする場合はここをコメントアウト
            // 以下の場合にコメントを検索する
            // 概要欄にタイムスタンプが1件以下（過去のコピペなどで0:00:00が残っている場合がある）
            // かつ、歌枠の場合（タイトルに特定の文字列が含まれる場合）
            if ((empty($archive['ts_items']) || count($archive['ts_items']) <= 1) && $archive['is_display']) {
                $comment_ts_items = $this->getTimeStampsFromComments($archive['video_id']);
                foreach ($comment_ts_items as $ts_item) {
                    $archive['ts_items'][] = $ts_item;
                }
            }
            $this->updateDisplayTsItems($archive['ts_items']);

            $rtn_archives[] = $archive;
        }

        return $rtn_archives;
    }

    private function getArchives($channel_id)
    {
        // チャンネルIDの先頭2文字をUUに置き換える
        $playlist_id = 'UU'.substr($channel_id, 2);

        // nextPageTokenが取得できなくなるまでループ
        $maxResults = App::environment('local') ? config('utils.page') : 50;
        $response = null;
        $archives = [];
        do {
            $response = $this->youtube->playlistItems->listPlaylistItems('snippet', [
                'playlistId' => $playlist_id,
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
                        'channel_id' => $channel_id,
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

    private function getTimeStampsFromText($video_id, $type, $description, $comment_id): array
    {
        // 引数のバリデーション
        // 最低限のチェック
        if (! is_string($video_id) || ! is_string($description)) {
            // 無効なデータが来た場合、空の結果を返却
            error_log('Invalid video_id or description: '
                .var_export($video_id, true).', '.var_export($description, true));

            return [];
        }

        if (! in_array($type, ['1', '2'])) {
            // タイプが不正ならデフォルト値にする（例えば1）
            error_log('Invalid type: '.var_export($type, true));

            return [];
        }

        // 正規表現でタイムスタンプを抽出 (MM:SS または HH:MM:SS)
        $pattern = '/\b(\d{1,2}:\d{2}(?::\d{2})?)\b/';
        $lines = explode("\n", $description); // 改行で分割
        $results = [];

        foreach ($lines as $line) {
            // 各行からタイムスタンプを抽出
            if (preg_match($pattern, $line, $matches)) {
                $timestamp = $matches[1];                              // タイムスタンプ部分
                $comment = trim(str_replace($timestamp, '', $line)); // タイムスタンプを除外した部分
                // 先頭の全角スペースを除外
                $comment = \App\Helpers\TextNormalizer::trimFullwidthSpace($comment);

                // 結果に追加
                $results[] = [
                    'id' => Str::ulid(),
                    'comment_id' => $comment_id,
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

    public function getTimeStampsFromComments($video_id)
    {
        $this->setAuth();

        $comments = [];
        $response = null;
        do {
            // リクエストパラメータを設定
            $params = [
                'videoId' => $video_id,
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
                        'video_id' => $video_id,
                    ]);
                    break; // コメントが無効の場合はループを抜ける
                } else {
                    Log::error('YouTube API: Failed to fetch comments', [
                        'video_id' => $video_id,
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

                // // リプライコメントがある場合
                // $replies = $item->getReplies();
                // if ($replies && $replies->getComments()) {
                //     foreach ($replies->getComments() as $reply) {
                //         $comments[] = $reply['snippet']['textOriginal'];
                //     }
                // }
                // いやリプライにタイムスタンプ置くやつなんておらんやろ
            }
            // 次のページトークンを取得
        } while ($response && $response->getNextPageToken());

        $rtn_ts_items = [];
        foreach ($comments as $comment) {
            $ts_items = $this->getTimeStampsFromText(
                $video_id,
                '2', // comment
                $comment['description'],
                $comment['id'],
            );
            foreach ($ts_items as $ts_item) {
                $rtn_ts_items[] = $ts_item;
            }
        }

        return $rtn_ts_items;
    }

    private function isSingingStream(string $title): bool
    {
        // 特定の歌��タイトルに含まれるかを判定する
        $keywords = ['singing stream', '歌枠', 'カラオケ', 'karaoke'];
        foreach ($keywords as $keyword) {
            if (str_contains(strtolower($title), $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function updateDisplayTsItems(array &$ts_items): void
    {
        if (empty($ts_items)) {
            return;
        }

        // comment_idごとの出現回数をカウント
        $count_by_comment_id = [];
        foreach ($ts_items as &$item) {
            $item['is_display'] = '0';
            $comment_id = $item['comment_id'];
            $count_by_comment_id[$comment_id] = ($count_by_comment_id[$comment_id] ?? 0) + 1;
        }

        // 最も多い comment_id を取得
        $max_count = max($count_by_comment_id);
        // 1件しかない場合は初期表示なしとする
        if ($max_count > 1) {
            $most_frequent_comment_ids = array_keys($count_by_comment_id, $max_count, true);
            // タイムスタンプが同数の場合も考えられるが先勝ちとする
            if (count($most_frequent_comment_ids) > 0) {
                // is_display を更新
                foreach ($ts_items as &$item) {
                    $item['is_display'] = ($item['comment_id'] === $most_frequent_comment_ids[0]);
                }
            }
        }
    }
}

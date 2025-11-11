<?php

namespace App\Services;

use App\Models\Archive;
use App\Models\ChangeList;
use App\Models\Channel;
use App\Models\TsItem;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RefreshArchiveService
{
    protected $youtubeService;

    public function __construct(YouTubeService $youtubeService)
    {
        $this->youtubeService = $youtubeService;
    }

    public function cliLogin(string $userId): void
    {
        // ログインを偽装
        $user = User::where('id', '=', $userId)->firstOrFail();
        Auth::login($user);
    }

    public function refreshArchives(Channel $channel): int
    {
        // 外部API呼び出しを全てトランザクション外で事前に実行
        // 1. archivesとts_itemsの取得および整形
        try {
            $rtn_archives = $this->youtubeService
                ->getArchivesAndTsItems($channel->channel_id);
        } catch (Exception $e) {
            error_log($e->getMessage());
            throw new Exception('youtubeとの接続でエラーが発生しました');
        }

        // そのままDBに取り込めるように、ts_itemsは別のリストにまとめて、archivesからは削除する
        $rtn_ts_items = [];
        foreach ($rtn_archives as &$archive) {
            foreach ($archive['ts_items'] as $ts_item) {
                $rtn_ts_items[] = $ts_item;
            }
            unset($archive['description']);
            unset($archive['ts_items']);
        }

        // コメントから取得が必要なvideo_idを事前に特定し、API呼び出しを実行
        $comment_ts_items_map = [];
        $results = DB::select("
            SELECT t1.video_id
            FROM archives t1
            WHERE
                NOT EXISTS (
                    SELECT 1
                    FROM ts_items t2
                    WHERE t2.video_id = t1.video_id
                    AND t2.type = '2'
                )
                AND EXISTS (
                    SELECT 1
                    FROM change_list t3
                    WHERE t3.video_id = t1.video_id
                    AND t3.comment_id IS NOT NULL
                    AND t3.comment_id <> t3.video_id
                )
                AND t1.channel_id = ?
        ", [$channel->channel_id]);

        foreach ($results as $result) {
            $video_id = $result->video_id;
            try {
                // API呼び出しのみ実行し、結果を保存
                $comment_ts_items_map[$video_id] = $this->youtubeService->getTimeStampsFromComments($video_id);
            } catch (Exception $e) {
                error_log($e->getMessage());
                throw new Exception('youtubeとの接続でエラーが発生しました');
            }
        }

        // 全てのDB操作を1つのトランザクションで実行（原子性を保証）
        DB::transaction(function () use ($channel, $rtn_archives, $rtn_ts_items, $comment_ts_items_map) {
            // 2.一度関連情報を削除（cascadeでTsItemsも消える）
            Archive::where('channel_id', $channel->channel_id)->delete();

            // 3.一旦DBに登録する
            if ($rtn_archives) {
                // 一気にやるとヤバなので100件くらいずつ登録
                $chunked = array_chunk($rtn_archives, 100);
                foreach ($chunked as $chunk) {
                    DB::table('archives')->insert($chunk);
                }
            }
            if ($rtn_ts_items) {
                $chunked = array_chunk($rtn_ts_items, 100);
                foreach ($chunked as $chunk) {
                    DB::table('ts_items')->insert($chunk);
                }
            }

            // 4.1.2.コメントから取得したts_itemsを登録
            foreach ($comment_ts_items_map as $video_id => $ts_items) {
                TsItem::where('video_id', $video_id)
                    ->where('type', '2')
                    ->delete();
                if ($ts_items) {
                    DB::table('ts_items')->insert($ts_items);
                }
            }

            // 4.2.履歴情報から、タイムスタンプの表示非表示を反映させる
            // change_list.is_displayが登録されている値と異なる場合、ts_itemsに反映
            $this->applyChangeListToTsItems($channel->channel_id);

            // 4.3.履歴情報から、動画の表示非表示を反映させる
            // change_list.is_displayが登録されている値と異なる場合、archivesに反映
            $this->applyChangeListToArchives($channel->channel_id);

            // 4.4.不要な履歴は削除する
            // archivesとts_itemsを外部結合したときに、結合先が存在しないchange_listを削除、条件は以下
            // a. タイムスタンプ(コメント<>null)でts_itemsに紐づかないレコード
            // b. アーカイブ(コメント==null)でarvhivesに紐づかないレコード
            // c. ts_itemsにもarchivesにも紐づかないレコード
            $this->deleteObsoleteChangeLists($channel->channel_id);
        });

        return count($rtn_archives);
    }

    /**
     * コメントを取得して、現在登録されているコメントを削除して再登録
     *
     * @param  mixed  $videoId
     * @return void
     *
     * @throws \Exception
     */
    public function refreshTimeStampsFromComments($videoId)
    {
        try {
            $ts_items = $this->youtubeService->getTimeStampsFromComments($videoId);
        } catch (Exception $e) {
            error_log($e->getMessage());
            throw new Exception('youtubeとの接続でエラーが発生しました');
        }
        TsItem::where('video_id', $videoId)
            ->where('type', '2')
            ->delete();
        if ($ts_items) {
            DB::table('ts_items')->insert($ts_items);
        }
    }

    public function getOldestUpdatedChannel(): Channel
    {
        $archive = Archive::orderBy('created_at', 'asc')->firstOrFail();
        $channel = Channel::where('channel_id', '=', $archive->channel_id)->firstOrFail();

        return $channel;
    }

    public function getChannelCount(): int
    {
        return Channel::count();
    }

    /**
     * change_listの情報をts_itemsに反映
     *
     * Note: This method should be called within a database transaction.
     * Uses optimized MySQL query for production, Eloquent for testing.
     */
    protected function applyChangeListToTsItems(string $channelId): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // Use optimized MySQL query for production
            DB::statement('
                UPDATE ts_items t1
                INNER JOIN change_list t2
                  ON t2.video_id = t1.video_id
                  AND t2.comment_id = t1.comment_id
                SET t1.is_display = t2.is_display
                WHERE t1.is_display <> t2.is_display
                  AND t2.channel_id = ?
            ', [$channelId]);
        } else {
            // Use Eloquent for SQLite/PostgreSQL (testability)
            ChangeList::where('channel_id', $channelId)
                ->whereNotNull('comment_id')
                ->chunk(100, function ($changeLists) {
                    foreach ($changeLists as $changeList) {
                        TsItem::where('video_id', $changeList->video_id)
                            ->where('comment_id', $changeList->comment_id)
                            ->where('is_display', '!=', $changeList->is_display)
                            ->update(['is_display' => $changeList->is_display]);
                    }
                });
        }
    }

    /**
     * change_listの情報をarchivesに反映
     *
     * Note: This method should be called within a database transaction.
     * Uses optimized MySQL query for production, Eloquent for testing.
     */
    protected function applyChangeListToArchives(string $channelId): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // Use optimized MySQL query for production
            DB::statement('
                UPDATE archives t1
                INNER JOIN change_list t2
                  ON t2.video_id = t1.video_id
                  AND t2.comment_id IS NULL
                SET t1.is_display = t2.is_display
                WHERE t1.is_display <> t2.is_display
                  AND t1.channel_id = ?
            ', [$channelId]);
        } else {
            // Use Eloquent for SQLite/PostgreSQL (testability)
            ChangeList::where('channel_id', $channelId)
                ->whereNull('comment_id')
                ->chunk(100, function ($changeLists) {
                    foreach ($changeLists as $changeList) {
                        Archive::where('video_id', $changeList->video_id)
                            ->where('is_display', '!=', $changeList->is_display)
                            ->update(['is_display' => $changeList->is_display]);
                    }
                });
        }
    }

    /**
     * 不要なchange_listレコードを削除
     * 以下の条件に該当するレコードを削除:
     * a. タイムスタンプ(comment_id IS NOT NULL)でts_itemsに紐づかないレコード
     * b. アーカイブ(comment_id IS NULL)でarchivesに紐づかないレコード
     * c. ts_itemsにもarchivesにも紐づかないレコード
     *
     * Note: This method should be called within a database transaction.
     * Uses optimized MySQL query for production, Eloquent for testing.
     */
    protected function deleteObsoleteChangeLists(string $channelId): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // Use optimized MySQL query for production
            DB::statement('
                DELETE t1 FROM change_list t1
                LEFT JOIN ts_items t2 ON t2.video_id = t1.video_id AND t2.comment_id = t1.comment_id
                LEFT JOIN archives t3 ON t3.video_id = t1.video_id AND t1.comment_id IS NULL
                WHERE t1.channel_id = ?
                    AND
                    (
                        (
                            t2.id IS NULL AND t1.comment_id IS NOT NULL
                        )
                        OR (
                            t3.id IS NULL AND t1.comment_id IS NULL
                        )
                        OR (
                            t2.id IS NULL AND t3.id IS NULL
                        )
                    )
            ', [$channelId]);
        } else {
            // Use Eloquent for SQLite/PostgreSQL (testability)
            $idsToDelete = [];

            ChangeList::where('channel_id', $channelId)
                ->chunk(100, function ($changeLists) use (&$idsToDelete) {
                    foreach ($changeLists as $changeList) {
                        if ($changeList->comment_id !== null) {
                            // タイムスタンプの場合: ts_itemsに紐づくかチェック
                            $tsItemExists = TsItem::where('video_id', $changeList->video_id)
                                ->where('comment_id', $changeList->comment_id)
                                ->exists();

                            if (! $tsItemExists) {
                                $idsToDelete[] = $changeList->id;
                            }
                        } else {
                            // アーカイブの場合: archivesに紐づくかチェック
                            $archiveExists = Archive::where('video_id', $changeList->video_id)
                                ->exists();

                            if (! $archiveExists) {
                                $idsToDelete[] = $changeList->id;
                            }
                        }
                    }
                });

            // Bulk delete for better performance
            if (! empty($idsToDelete)) {
                ChangeList::whereIn('id', $idsToDelete)->delete();
            }
        }
    }
}

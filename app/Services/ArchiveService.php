<?php
namespace App\Services;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\TsItem;
use App\Models\User;
use App\Services\YouTubeService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ArchiveService
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

    public function refreshArchives(Channel $channel): void
    {
        DB::transaction(function () use ($channel) {
            // 1.archivesとts_itemsの取得および整形
            try {
                $rtn_archives = $this->youtubeService
                    ->getArchivesAndTsItems($channel->channel_id);
            } catch (Exception $e) {
                error_log($e->getMessage());
                throw new Exception("youtubeとの接続でエラーが発生しました");
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
            // 以下でSQLを実行
            // 4.表示非表示の履歴情報を反映させつつ不要な情報を削除していく
            // 4.1.履歴でコメントを取得しているが現時点ではコメントがない場合、コメントを取得
            // 4.1.1.ts_itemsにcommentがない、かつchangeListにcommentが存在するvideo_idを取得
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

            // 4.1.2.取得したvideo_id（コメントを取得する必要のあるアーカイブ）について、コメントを洗替え
            // cascadeで消えてるはずなので登録だけでいいような気もするが、ほか処理でも使うので冗長さは目を瞑る
            foreach ($results as $result) {
                $video_id = $result->video_id;
                try {
                    $this->refreshTimeStampsFromComments($video_id);
                } catch (Exception $e) {
                    error_log($e->getMessage());
                    throw new Exception("youtubeとの接続でエラーが発生しました");
                }
            }

            // 4.2.履歴情報から、タイムスタンプの表示非表示を反映させる
            // change_list.is_displayが登録されている値と異なる場合、ts_itemsに反映
            DB::statement("
                UPDATE ts_items t1
                INNER JOIN change_list t2
                  ON t2.video_id = t1.video_id
                  AND t2.comment_id = t1.comment_id
                SET t1.is_display = t2.is_display
                WHERE t1.is_display <> t2.is_display
                  AND t2.channel_id = ?
            ", [$channel->channel_id]);

            // 4.3.履歴情報から、動画の表示非表示を反映させる
            // change_list.is_displayが登録されている値と異なる場合、archivesに反映
            DB::statement("
                UPDATE archives t1
                INNER JOIN change_list t2
                  ON t2.video_id = t1.video_id
                  AND t2.comment_id IS NULL
                SET t1.is_display = t2.is_display
                WHERE t1.is_display <> t2.is_display
                  AND t1.channel_id = ?
            ", [$channel->channel_id]);

            // 4.4.不要な履歴は削除する
            // archivesとts_itemsを外部結合したときに、結合先が存在しないchange_listを削除、条件は以下
            // a. タイムスタンプ(コメント<>null)でts_itemsに紐づかないレコード
            // b. アーカイブ(コメント==null)でarvhivesに紐づかないレコード
            // c. ts_itemsにもarchivesにも紐づかないレコード
            DB::statement("
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
            ", [$channel->channel_id]);
        });

        return;
    }

    /**
     * コメントを取得して、現在登録されているコメントを削除して再登録
     * @param mixed $videoId
     * @throws \Exception
     * @return void
     */
    public function refreshTimeStampsFromComments($videoId)
    {
        try {
            $ts_items = $this->youtubeService->getTimeStampsFromComments($videoId);
        } catch (Exception $e) {
            error_log($e->getMessage());
            throw new Exception("youtubeとの接続でエラーが発生しました");
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
}

<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use App\Models\Archive;
use App\Models\Channel;
use App\Models\TsItem;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RefreshArchiveService
{
    protected YouTubeService $youtubeService;

    protected ChangeListService $changeListService;

    protected ChannelQueryService $channelQueryService;

    public function __construct(
        YouTubeService $youtubeService,
        ChangeListService $changeListService,
        ChannelQueryService $channelQueryService
    ) {
        $this->youtubeService = $youtubeService;
        $this->changeListService = $changeListService;
        $this->channelQueryService = $channelQueryService;
    }

    public function cliLogin(string $userId): void
    {
        // ログインを偽装
        $user = User::where('id', '=', $userId)->firstOrFail();

        if (! $user->api_key) {
            throw new Exception("User ID {$userId} does not have an API key. Please register an API key in your profile first.");
        }

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

        // 既にgetArchivesAndTsItems()でコメント取得済みのvideo_idを特定
        $alreadyFetchedVideoIds = collect($rtn_ts_items)
            ->where('type', '2')
            ->pluck('video_id')
            ->unique()
            ->toArray();

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
            // 既にコメント取得済みの動画はスキップ
            if (in_array($video_id, $alreadyFetchedVideoIds)) {
                continue;
            }
            try {
                // API呼び出しのみ実行し、結果を保存
                $comment_ts_items_map[$video_id] = $this->youtubeService->getTimeStampsFromComments($video_id);
            } catch (Exception $e) {
                error_log($e->getMessage());
                throw new Exception('youtubeとの接続でエラーが発生しました');
            }
        }

        // DB::table()->insert()はEloquentのtimestampsが動作しないため、手動で追加
        $now = now();
        foreach ($rtn_archives as &$archive) {
            $archive['created_at'] = $now;
            $archive['updated_at'] = $now;
        }
        unset($archive);
        foreach ($rtn_ts_items as &$ts_item) {
            $ts_item['created_at'] = $now;
            $ts_item['updated_at'] = $now;
        }
        unset($ts_item);
        foreach ($comment_ts_items_map as &$ts_items) {
            foreach ($ts_items as &$ts_item) {
                $ts_item['created_at'] = $now;
                $ts_item['updated_at'] = $now;
            }
            unset($ts_item);
        }
        unset($ts_items);

        // カバー曲（歌ってみた）のts_itemsを生成
        $cover_ts_items = $this->extractCoverSongTsItems($rtn_archives);

        // 全てのDB操作を1つのトランザクションで実行（原子性を保証）
        DB::transaction(function () use ($channel, $rtn_archives, $rtn_ts_items, $comment_ts_items_map, $cover_ts_items) {
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

            // 4.1.1.カバー曲のts_itemsを登録
            if ($cover_ts_items) {
                $chunked = array_chunk($cover_ts_items, 100);
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
            $this->changeListService->applyChangeListToTsItems($channel->channel_id);

            // 4.3.履歴情報から、動画の表示非表示を反映させる
            $this->changeListService->applyChangeListToArchives($channel->channel_id);

            // 4.4.不要な履歴は削除する
            $this->changeListService->deleteObsoleteChangeLists($channel->channel_id);
        });

        return count($rtn_archives);
    }

    /**
     * コメントを取得して、現在登録されているコメントを削除して再登録
     */
    public function refreshTimeStampsFromComments(string $videoId): void
    {
        try {
            $ts_items = $this->youtubeService->getTimeStampsFromComments($videoId);
        } catch (Exception $e) {
            error_log($e->getMessage());
            throw new Exception('youtubeとの接続でエラーが発生しました');
        }

        // DB::table()->insert()はEloquentのtimestampsが動作しないため、手動で追加
        $now = now();
        foreach ($ts_items as &$ts_item) {
            $ts_item['created_at'] = $now;
            $ts_item['updated_at'] = $now;
        }
        unset($ts_item);

        TsItem::where('video_id', $videoId)
            ->where('type', '2')
            ->delete();
        if ($ts_items) {
            DB::table('ts_items')->insert($ts_items);
        }
    }

    public function getOldestUpdatedChannel(): Channel
    {
        return $this->channelQueryService->getOldestUpdatedChannel();
    }

    public function getChannelCount(): int
    {
        return $this->channelQueryService->getChannelCount();
    }

    public function getOldestUpdatedChannelForUser(int $userId): ?Channel
    {
        return $this->channelQueryService->getOldestUpdatedChannelForUser($userId);
    }

    public function getChannelCountForUser(int $userId): int
    {
        return $this->channelQueryService->getChannelCountForUser($userId);
    }

    /**
     * カバー曲（歌ってみた）動画かどうかを判定
     *
     * タイトルに「歌ってみた」「cover」「カバー」が含まれる場合はカバー曲と判定
     */
    public function isCoverSong(string $title): bool
    {
        $lowerTitle = mb_strtolower($title);

        // 検出キーワード
        $keywords = ['歌ってみた', 'cover', 'カバー'];

        foreach ($keywords as $keyword) {
            if (mb_strpos($lowerTitle, mb_strtolower($keyword)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * カバー曲用のts_itemを作成
     *
     * @param  array  $archive  アーカイブ情報（video_id, title が必要）
     * @return array ts_item配列
     */
    public function createCoverSongTsItem(array $archive): array
    {
        $title = $archive['title'];

        return [
            'id' => Str::ulid(),
            'comment_id' => $archive['video_id'], // 概要欄と同様にvideo_idを設定
            'video_id' => $archive['video_id'],
            'type' => '3', // カバー曲
            'ts_text' => '0:00',
            'ts_num' => 0,
            'text' => $title,
            'normalized_text' => TextNormalizer::normalize($title),
            'is_display' => true,
        ];
    }

    /**
     * アーカイブ配列からカバー曲のts_itemsを生成
     *
     * @param  array  $archives  アーカイブ配列
     * @return array カバー曲のts_items配列
     */
    public function extractCoverSongTsItems(array $archives): array
    {
        $coverTsItems = [];
        $now = now();

        foreach ($archives as $archive) {
            if ($this->isCoverSong($archive['title'])) {
                $tsItem = $this->createCoverSongTsItem($archive);
                $tsItem['created_at'] = $now;
                $tsItem['updated_at'] = $now;
                $coverTsItems[] = $tsItem;
            }
        }

        return $coverTsItems;
    }
}

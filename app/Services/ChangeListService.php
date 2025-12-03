<?php

namespace App\Services;

use App\Models\Archive;
use App\Models\ChangeList;
use App\Models\TsItem;
use Illuminate\Support\Facades\DB;

class ChangeListService
{
    /**
     * change_listの情報をts_itemsに反映
     *
     * Note: This method should be called within a database transaction.
     * Uses optimized MySQL query for production, Eloquent for testing.
     */
    public function applyChangeListToTsItems(string $channelId): void
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
            // N+1問題を回避するため、チャンクごとにバルク更新
            ChangeList::where('channel_id', $channelId)
                ->whereNotNull('comment_id')
                ->chunk(100, function ($changeLists) {
                    // is_displayの値でグループ化
                    $displayGroups = $changeLists->groupBy('is_display');

                    foreach ($displayGroups as $isDisplay => $groupedChangeLists) {
                        // video_id + comment_id のペアを収集
                        $conditions = $groupedChangeLists->map(function ($cl) {
                            return ['video_id' => $cl->video_id, 'comment_id' => $cl->comment_id];
                        })->toArray();

                        // 各ペアに対してOR条件でマッチするレコードを一括更新
                        TsItem::where(function ($query) use ($conditions) {
                            foreach ($conditions as $condition) {
                                $query->orWhere(function ($q) use ($condition) {
                                    $q->where('video_id', $condition['video_id'])
                                        ->where('comment_id', $condition['comment_id']);
                                });
                            }
                        })
                            ->where('is_display', '!=', $isDisplay)
                            ->update(['is_display' => $isDisplay]);
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
    public function applyChangeListToArchives(string $channelId): void
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
            // N+1問題を回避するため、チャンクごとにバルク更新
            ChangeList::where('channel_id', $channelId)
                ->whereNull('comment_id')
                ->chunk(100, function ($changeLists) {
                    // is_displayの値でグループ化
                    $displayGroups = $changeLists->groupBy('is_display');

                    foreach ($displayGroups as $isDisplay => $groupedChangeLists) {
                        $videoIds = $groupedChangeLists->pluck('video_id')->toArray();

                        // video_idリストに対して一括更新
                        Archive::whereIn('video_id', $videoIds)
                            ->where('is_display', '!=', $isDisplay)
                            ->update(['is_display' => $isDisplay]);
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
    public function deleteObsoleteChangeLists(string $channelId): void
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
            // N+1問題を回避するため、チャンクごとに一括チェック
            $idsToDelete = [];

            ChangeList::where('channel_id', $channelId)
                ->chunk(100, function ($changeLists) use (&$idsToDelete) {
                    // タイムスタンプ用のchange_list（comment_id IS NOT NULL）
                    $timestampChangeLists = $changeLists->whereNotNull('comment_id');
                    // アーカイブ用のchange_list（comment_id IS NULL）
                    $archiveChangeLists = $changeLists->whereNull('comment_id');

                    // タイムスタンプの存在確認を一括で行う
                    if ($timestampChangeLists->isNotEmpty()) {
                        $videoIds = $timestampChangeLists->pluck('video_id')->unique()->toArray();
                        $commentIds = $timestampChangeLists->pluck('comment_id')->unique()->toArray();

                        // 存在するts_itemsのvideo_id + comment_idペアを取得
                        $existingTsItems = TsItem::whereIn('video_id', $videoIds)
                            ->whereIn('comment_id', $commentIds)
                            ->select('video_id', 'comment_id')
                            ->get()
                            ->map(fn ($item) => $item->video_id.'|'.$item->comment_id)
                            ->toArray();

                        foreach ($timestampChangeLists as $changeList) {
                            $key = $changeList->video_id.'|'.$changeList->comment_id;
                            if (! in_array($key, $existingTsItems)) {
                                $idsToDelete[] = $changeList->id;
                            }
                        }
                    }

                    // アーカイブの存在確認を一括で行う
                    if ($archiveChangeLists->isNotEmpty()) {
                        $videoIds = $archiveChangeLists->pluck('video_id')->unique()->toArray();

                        // 存在するarchivesのvideo_idを取得
                        $existingArchiveVideoIds = Archive::whereIn('video_id', $videoIds)
                            ->pluck('video_id')
                            ->toArray();

                        foreach ($archiveChangeLists as $changeList) {
                            if (! in_array($changeList->video_id, $existingArchiveVideoIds)) {
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

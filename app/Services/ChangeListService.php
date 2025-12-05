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
     * 優先度: タイムスタンプ単位 > コメント単位
     *
     * Note: This method should be called within a database transaction.
     * Uses optimized MySQL query for production, Eloquent for testing.
     */
    public function applyChangeListToTsItems(string $channelId): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // Step 1: タイムスタンプ単位（ts_item_id IS NOT NULL）を適用
            DB::statement('
                UPDATE ts_items t1
                INNER JOIN change_list t2
                  ON t2.ts_item_id = t1.id
                SET t1.is_display = t2.is_display
                WHERE t1.is_display <> t2.is_display
                  AND t2.channel_id = ?
            ', [$channelId]);

            // Step 2: コメント単位（ts_item_id IS NULL AND comment_id IS NOT NULL）を適用
            // タイムスタンプ単位の設定がないts_itemsにのみ適用
            DB::statement('
                UPDATE ts_items t1
                INNER JOIN change_list t2
                  ON t2.video_id = t1.video_id
                  AND t2.comment_id = t1.comment_id
                  AND t2.ts_item_id IS NULL
                LEFT JOIN change_list t3
                  ON t3.ts_item_id = t1.id
                SET t1.is_display = t2.is_display
                WHERE t3.id IS NULL
                  AND t1.is_display <> t2.is_display
                  AND t2.channel_id = ?
            ', [$channelId]);
        } else {
            // Use Eloquent for SQLite/PostgreSQL (testability)

            // Step 1: タイムスタンプ単位（ts_item_id IS NOT NULL）の適用
            $tsItemChangeLists = ChangeList::where('channel_id', $channelId)
                ->whereNotNull('ts_item_id')
                ->get();

            $tsItemIdsWithOverride = $tsItemChangeLists->pluck('ts_item_id')->toArray();

            $displayGroups = $tsItemChangeLists->groupBy('is_display');
            foreach ($displayGroups as $isDisplay => $groupedChangeLists) {
                $tsItemIds = $groupedChangeLists->pluck('ts_item_id')->toArray();
                TsItem::whereIn('id', $tsItemIds)
                    ->where('is_display', '!=', $isDisplay)
                    ->update(['is_display' => $isDisplay]);
            }

            // Step 2: コメント単位（ts_item_id IS NULL）の適用
            // タイムスタンプ単位で設定されていないts_itemsにのみ適用
            ChangeList::where('channel_id', $channelId)
                ->whereNotNull('comment_id')
                ->whereNull('ts_item_id')
                ->chunk(100, function ($changeLists) use ($tsItemIdsWithOverride) {
                    $displayGroups = $changeLists->groupBy('is_display');

                    foreach ($displayGroups as $isDisplay => $groupedChangeLists) {
                        $conditions = $groupedChangeLists->map(function ($cl) {
                            return ['video_id' => $cl->video_id, 'comment_id' => $cl->comment_id];
                        })->toArray();

                        TsItem::where(function ($query) use ($conditions) {
                            foreach ($conditions as $condition) {
                                $query->orWhere(function ($q) use ($condition) {
                                    $q->where('video_id', $condition['video_id'])
                                        ->where('comment_id', $condition['comment_id']);
                                });
                            }
                        })
                            ->whereNotIn('id', $tsItemIdsWithOverride)
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
     * a. タイムスタンプ単位(ts_item_id IS NOT NULL)でts_itemsに紐づかないレコード
     * b. コメント単位(ts_item_id IS NULL AND comment_id IS NOT NULL)でts_itemsに紐づかないレコード
     * c. アーカイブ単位(comment_id IS NULL)でarchivesに紐づかないレコード
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
                LEFT JOIN ts_items t2_ts ON t2_ts.id = t1.ts_item_id
                LEFT JOIN ts_items t2_comment ON t2_comment.video_id = t1.video_id
                    AND t2_comment.comment_id = t1.comment_id
                    AND t1.ts_item_id IS NULL
                LEFT JOIN archives t3 ON t3.video_id = t1.video_id AND t1.comment_id IS NULL
                WHERE t1.channel_id = ?
                    AND
                    (
                        (t1.ts_item_id IS NOT NULL AND t2_ts.id IS NULL)
                        OR (t1.ts_item_id IS NULL AND t1.comment_id IS NOT NULL AND t2_comment.id IS NULL)
                        OR (t1.comment_id IS NULL AND t3.id IS NULL)
                    )
            ', [$channelId]);
        } else {
            // Use Eloquent for SQLite/PostgreSQL (testability)
            $idsToDelete = [];

            ChangeList::where('channel_id', $channelId)
                ->chunk(100, function ($changeLists) use (&$idsToDelete) {
                    // タイムスタンプ単位のchange_list（ts_item_id IS NOT NULL）
                    $tsItemChangeLists = $changeLists->whereNotNull('ts_item_id');
                    // コメント単位のchange_list（ts_item_id IS NULL AND comment_id IS NOT NULL）
                    $commentChangeLists = $changeLists->whereNull('ts_item_id')->whereNotNull('comment_id');
                    // アーカイブ単位のchange_list（comment_id IS NULL）
                    $archiveChangeLists = $changeLists->whereNull('comment_id');

                    // タイムスタンプ単位の存在確認
                    if ($tsItemChangeLists->isNotEmpty()) {
                        $tsItemIds = $tsItemChangeLists->pluck('ts_item_id')->unique()->toArray();
                        $existingTsItemIds = TsItem::whereIn('id', $tsItemIds)
                            ->pluck('id')
                            ->toArray();

                        foreach ($tsItemChangeLists as $changeList) {
                            if (! in_array($changeList->ts_item_id, $existingTsItemIds)) {
                                $idsToDelete[] = $changeList->id;
                            }
                        }
                    }

                    // コメント単位の存在確認
                    if ($commentChangeLists->isNotEmpty()) {
                        $videoIds = $commentChangeLists->pluck('video_id')->unique()->toArray();
                        $commentIds = $commentChangeLists->pluck('comment_id')->unique()->toArray();

                        $existingTsItems = TsItem::whereIn('video_id', $videoIds)
                            ->whereIn('comment_id', $commentIds)
                            ->select('video_id', 'comment_id')
                            ->get()
                            ->map(fn ($item) => $item->video_id.'|'.$item->comment_id)
                            ->toArray();

                        foreach ($commentChangeLists as $changeList) {
                            $key = $changeList->video_id.'|'.$changeList->comment_id;
                            if (! in_array($key, $existingTsItems)) {
                                $idsToDelete[] = $changeList->id;
                            }
                        }
                    }

                    // アーカイブ単位の存在確認
                    if ($archiveChangeLists->isNotEmpty()) {
                        $videoIds = $archiveChangeLists->pluck('video_id')->unique()->toArray();
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

            if (! empty($idsToDelete)) {
                ChangeList::whereIn('id', $idsToDelete)->delete();
            }
        }
    }
}

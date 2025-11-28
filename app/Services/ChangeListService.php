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

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DuplicateCommentDetectionService
{
    private const DEFAULT_THRESHOLD = 5;

    /**
     * 指定動画の重複コメントタイムスタンプペアを検知
     *
     * 同一動画内で異なるコメントから取得されたタイムスタンプのうち、
     * ts_numの差が閾値以内のペアを返す。
     */
    public function detect(string $videoId, int $threshold = self::DEFAULT_THRESHOLD): array
    {
        $cast = DB::getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        return DB::select("
            SELECT
                a.id AS id_a, b.id AS id_b,
                a.video_id,
                a.ts_num AS ts_num_a, b.ts_num AS ts_num_b,
                a.ts_text AS ts_text_a, b.ts_text AS ts_text_b,
                a.text AS text_a, b.text AS text_b,
                a.comment_id AS comment_id_a, b.comment_id AS comment_id_b
            FROM ts_items a
            INNER JOIN ts_items b
                ON  a.video_id = b.video_id
                AND a.id < b.id
                AND a.type = '2' AND b.type = '2'
                AND a.comment_id != b.comment_id
                AND ABS(CAST(a.ts_num AS {$cast}) - CAST(b.ts_num AS {$cast})) <= ?
            WHERE a.video_id = ?
              AND a.is_display = 1 AND b.is_display = 1
            ORDER BY a.ts_num ASC
        ", [$threshold, $videoId]);
    }

    /**
     * 複数動画の重複ペア数を一括取得（N+1回避）
     */
    public function countByVideoIds(array $videoIds, int $threshold = self::DEFAULT_THRESHOLD): array
    {
        if (empty($videoIds)) {
            return [];
        }

        $cast = DB::getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';
        $placeholders = implode(',', array_fill(0, count($videoIds), '?'));

        $rows = DB::select("
            SELECT a.video_id, COUNT(*) as pair_count
            FROM ts_items a
            INNER JOIN ts_items b
                ON  a.video_id = b.video_id
                AND a.id < b.id
                AND a.type = '2' AND b.type = '2'
                AND a.comment_id != b.comment_id
                AND ABS(CAST(a.ts_num AS {$cast}) - CAST(b.ts_num AS {$cast})) <= ?
            WHERE a.video_id IN ({$placeholders})
              AND a.is_display = 1 AND b.is_display = 1
            GROUP BY a.video_id
        ", array_merge([$threshold], $videoIds));

        $result = [];
        foreach ($rows as $row) {
            $result[$row->video_id] = (int) $row->pair_count;
        }

        return $result;
    }
}

<?php

namespace App\Services;

use App\Models\Song;
use App\Models\TsItem;

class TimestampStatsService
{
    /**
     * @return array{unlinked: int, linked: int, not_song: int, linked_rate: float, recent_count: int, songs_count: int}
     */
    public function getSummary(): array
    {
        $base = TsItem::query()
            ->where('ts_items.is_display', 1)
            ->where('ts_items.type', '!=', '3')
            ->whereNotNull('ts_items.normalized_text');

        $counts = (clone $base)
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->selectRaw('COUNT(DISTINCT ts_items.normalized_text) as total')
            ->selectRaw('COUNT(DISTINCT CASE WHEN timestamp_song_mappings.song_id IS NOT NULL AND timestamp_song_mappings.is_not_song = 0 THEN ts_items.normalized_text END) as linked')
            ->selectRaw('COUNT(DISTINCT CASE WHEN timestamp_song_mappings.is_not_song = 1 THEN ts_items.normalized_text END) as not_song')
            ->first();

        $total = (int) $counts->total;
        $linked = (int) $counts->linked;
        $notSong = (int) $counts->not_song;
        $unlinked = $total - $linked - $notSong;

        $recentCount = (clone $base)
            ->join('archives', 'ts_items.video_id', '=', 'archives.video_id')
            ->where('archives.published_at', '>=', now()->subDays(7)->toDateString())
            ->distinct()
            ->count('ts_items.normalized_text');

        $linkedRate = $total > 0
            ? round(($linked + $notSong) / $total * 100, 1)
            : 0;

        return [
            'unlinked' => $unlinked,
            'linked' => $linked,
            'not_song' => $notSong,
            'linked_rate' => $linkedRate,
            'recent_count' => $recentCount,
            'songs_count' => Song::count(),
        ];
    }
}

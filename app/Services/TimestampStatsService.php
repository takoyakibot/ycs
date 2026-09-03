<?php

namespace App\Services;

use App\Models\TimestampSongMapping;
use App\Models\TsItem;

class TimestampStatsService
{
    /**
     * @return array{unlinked: int, linked: int, not_song: int, linked_rate: float, recent_count: int}
     */
    public function getSummary(): array
    {
        $totalUniqueTexts = TsItem::where('is_display', 1)
            ->where('type', '!=', '3')
            ->whereNotNull('normalized_text')
            ->distinct('normalized_text')
            ->count('normalized_text');

        $linked = TimestampSongMapping::whereNotNull('song_id')
            ->where('is_not_song', false)
            ->count();

        $notSong = TimestampSongMapping::where('is_not_song', true)
            ->count();

        $unlinked = (int) TsItem::selectRaw('COUNT(DISTINCT ts_items.normalized_text) as cnt')
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->where('ts_items.is_display', 1)
            ->where('ts_items.type', '!=', '3')
            ->whereNotNull('ts_items.normalized_text')
            ->whereNull('timestamp_song_mappings.id')
            ->first()->cnt;

        $recentCount = TsItem::where('is_display', 1)
            ->where('type', '!=', '3')
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $linkedRate = $totalUniqueTexts > 0
            ? round(($linked + $notSong) / $totalUniqueTexts * 100, 1)
            : 0;

        return [
            'unlinked' => $unlinked,
            'linked' => $linked,
            'not_song' => $notSong,
            'linked_rate' => $linkedRate,
            'recent_count' => $recentCount,
        ];
    }
}

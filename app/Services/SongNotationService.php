<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;

class SongNotationService
{
    private const MAX_NOTATIONS = 30;

    public function getNotationCandidates(string $songId): array
    {
        $song = Song::findOrFail($songId);

        // 通常マッピング（is_manual=true のレビュー済みのみ）
        $normalizedTexts = TimestampSongMapping::where('song_id', $songId)
            ->where('status', TimestampSongMapping::STATUS_LINKED)
            ->where('is_manual', true)
            ->where('is_not_song', false)
            ->pluck('normalized_text');

        // 両経路（通常マッピング + 個別マッピング ts_items.song_id）から集計
        $query = TsItem::query()
            ->join('archives', 'ts_items.video_id', '=', 'archives.video_id')
            ->where('ts_items.is_display', 1)
            ->where('archives.is_display', 1)
            ->where('ts_items.type', '!=', '3')
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->where(function ($q) use ($normalizedTexts, $songId) {
                $q->whereIn('ts_items.normalized_text', $normalizedTexts)
                    ->orWhere('ts_items.song_id', $songId);
            })
            ->selectRaw('ts_items.text, COUNT(*) as frequency')
            ->groupBy('ts_items.text')
            ->orderByDesc('frequency');

        $allCandidates = $query->get();
        $totalTimestamps = $allCandidates->sum('frequency');

        // アーティスト名なし（区切り文字なし）を除外
        $filtered = $allCandidates->filter(function ($item) {
            return TextNormalizer::hasSeparators($item->text);
        });

        $excludedCount = $totalTimestamps - $filtered->sum('frequency');

        $notations = $filtered->take(self::MAX_NOTATIONS)
            ->map(fn ($item) => [
                'text' => $item->text,
                'frequency' => (int) $item->frequency,
            ])
            ->values()
            ->toArray();

        return [
            'song' => [
                'id' => $song->id,
                'title' => $song->title,
                'artist' => $song->artist,
            ],
            'notations' => $notations,
            'total_timestamps' => $totalTimestamps,
            'excluded_count' => $excludedCount,
        ];
    }
}

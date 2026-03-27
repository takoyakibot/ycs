<?php

namespace App\Services;

use App\Models\NormalizationLog;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SongMergeService
{
    /**
     * 重複楽曲のグループを検出する
     *
     * normalized_title が同じ楽曲をグループ化して返す。
     * 各グループには楽曲情報とマッピング数を含む。
     *
     * @param  string  $search  検索フィルタ（タイトルで絞り込み）
     * @return array 重複グループの配列
     */
    public function findDuplicates(string $search = ''): array
    {
        // normalized_titleでグループ化し、2件以上のグループを取得
        $query = Song::selectRaw('normalized_title, normalized_artist, COUNT(*) as count')
            ->groupBy('normalized_title', 'normalized_artist')
            ->having('count', '>', 1)
            ->orderBy('count', 'desc');

        if ($search !== '') {
            $query->where('title', 'LIKE', "%{$search}%");
        }

        $groups = $query->limit(50)->get();

        // 各グループの詳細情報を取得
        return $groups->map(function ($group) {
            $songs = Song::where('normalized_title', $group->normalized_title)
                ->where('normalized_artist', $group->normalized_artist)
                ->withCount('mappings')
                ->get()
                ->map(function ($song) {
                    $tsItemCount = TsItem::where('song_id', $song->id)->count();

                    return [
                        'id' => $song->id,
                        'title' => $song->title,
                        'artist' => $song->artist,
                        'spotify_track_id' => $song->spotify_track_id,
                        'mappings_count' => $song->mappings_count,
                        'ts_items_count' => $tsItemCount,
                    ];
                });

            return [
                'normalized_title' => $group->normalized_title,
                'normalized_artist' => $group->normalized_artist,
                'songs' => $songs,
            ];
        })->toArray();
    }

    /**
     * 2つの楽曲をマージする
     *
     * source の楽曲に紐付くマッピングと個別ts_itemをすべて target に付け替え、
     * source を削除する。
     *
     * @param  string  $sourceSongId  マージ元（削除される）楽曲ID
     * @param  string  $targetSongId  マージ先（残る）楽曲ID
     * @param  int|null  $userId  操作者ID
     * @return array{affected_mappings: int, affected_ts_items: int}
     */
    public function merge(string $sourceSongId, string $targetSongId, ?int $userId = null): array
    {
        $userId = $userId ?? Auth::id();

        $sourceSong = Song::findOrFail($sourceSongId);
        $targetSong = Song::findOrFail($targetSongId);

        return DB::transaction(function () use ($sourceSong, $targetSong, $userId) {
            // マッピングを付け替え
            $affectedMappings = TimestampSongMapping::where('song_id', $sourceSong->id)
                ->update(['song_id' => $targetSong->id]);

            // 個別ts_item.song_idを付け替え
            $affectedTsItems = TsItem::where('song_id', $sourceSong->id)
                ->update(['song_id' => $targetSong->id]);

            // ログ記録
            if ($userId) {
                NormalizationLog::log(
                    $userId,
                    NormalizationLog::ACTION_MERGE_SONG,
                    NormalizationLog::TARGET_SONG,
                    $targetSong->id,
                    [
                        'source_song_id' => $sourceSong->id,
                        'source_title' => $sourceSong->title,
                        'source_artist' => $sourceSong->artist,
                        'target_title' => $targetSong->title,
                        'target_artist' => $targetSong->artist,
                        'affected_mappings' => $affectedMappings,
                        'affected_ts_items' => $affectedTsItems,
                    ]
                );
            }

            // マージ元を削除
            $sourceSong->delete();

            return [
                'affected_mappings' => $affectedMappings,
                'affected_ts_items' => $affectedTsItems,
            ];
        });
    }
}

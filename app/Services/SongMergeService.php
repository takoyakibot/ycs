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

        // 全グループの楽曲を一括取得（N+1クエリ対策）
        $groupKeys = $groups->map(fn ($g) => [$g->normalized_title, $g->normalized_artist]);

        $allSongs = Song::where(function ($q) use ($groupKeys) {
            foreach ($groupKeys as $key) {
                $q->orWhere(function ($q2) use ($key) {
                    $q2->where('normalized_title', $key[0])
                        ->where('normalized_artist', $key[1]);
                });
            }
        })
            ->withCount('mappings')
            ->get();

        // ts_items.song_idのカウントを一括取得
        $songIds = $allSongs->pluck('id')->toArray();
        $tsItemCounts = TsItem::selectRaw('song_id, COUNT(*) as count')
            ->whereIn('song_id', $songIds)
            ->groupBy('song_id')
            ->pluck('count', 'song_id');

        return $groups->map(function ($group) use ($allSongs, $tsItemCounts) {
            $songs = $allSongs->filter(fn ($s) => $s->normalized_title === $group->normalized_title
                    && $s->normalized_artist === $group->normalized_artist)
                ->map(fn ($song) => [
                    'id' => $song->id,
                    'title' => $song->title,
                    'artist' => $song->artist,
                    'spotify_track_id' => $song->spotify_track_id,
                    'mappings_count' => $song->mappings_count,
                    'ts_items_count' => $tsItemCounts->get($song->id, 0),
                ])->values();

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
        $userId = $userId ?? Auth::id() ?? throw new \RuntimeException('認証されていないユーザーによるマージは許可されていません');

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

            // マージ元を削除
            $sourceSong->delete();

            return [
                'affected_mappings' => $affectedMappings,
                'affected_ts_items' => $affectedTsItems,
            ];
        });
    }
}

<?php

namespace App\Services;

use App\Models\NormalizationLog;
use App\Models\Song;
use App\Models\SongTag;
use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SongMergeService
{
    /**
     * 2つの楽曲をマージする
     *
     * source の楽曲に紐付くマッピング・個別ts_item・分解をすべて target に付け替え、
     * source を削除する。
     *
     * @param  string  $sourceSongId  マージ元（削除される）楽曲ID
     * @param  string  $targetSongId  マージ先（残る）楽曲ID
     * @param  int|null  $userId  操作者ID
     * @return array{affected_mappings: int, affected_ts_items: int, affected_decompositions: int, migrated_tags: int}
     */
    public function merge(string $sourceSongId, string $targetSongId, ?int $userId = null): array
    {
        $userId = $userId ?? Auth::id() ?? throw new \RuntimeException('認証されていないユーザーによるマージは許可されていません');

        $sourceSong = Song::findOrFail($sourceSongId);
        $targetSong = Song::findOrFail($targetSongId);

        return DB::transaction(function () use ($sourceSong, $targetSong, $userId) {
            // targetに既存のマッピングのnormalized_textを取得
            $targetNormalizedTexts = TimestampSongMapping::where('song_id', $targetSong->id)
                ->pluck('normalized_text')
                ->toArray();

            // sourceのマッピングのうち、targetと重複するものは削除（unique制約違反を回避）
            $deletedDuplicates = 0;
            if (! empty($targetNormalizedTexts)) {
                $deletedDuplicates = TimestampSongMapping::where('song_id', $sourceSong->id)
                    ->whereIn('normalized_text', $targetNormalizedTexts)
                    ->delete();
            }

            // 残りのマッピングを付け替え
            $affectedMappings = TimestampSongMapping::where('song_id', $sourceSong->id)
                ->update(['song_id' => $targetSong->id]);

            // 個別ts_item.song_idを付け替え
            $affectedTsItems = TsItem::where('song_id', $sourceSong->id)
                ->update(['song_id' => $targetSong->id]);

            // timestamp_decompositions.song_idを付け替え
            $affectedDecompositions = TimestampDecomposition::where('song_id', $sourceSong->id)
                ->update(['song_id' => $targetSong->id]);

            // タグを移行（ターゲットに既存の値と重複するものはスキップ）
            $targetTagValues = SongTag::where('song_id', $targetSong->id)
                ->pluck('value')
                ->toArray();

            $sourceTags = SongTag::where('song_id', $sourceSong->id)->get();
            $migratedTags = 0;
            foreach ($sourceTags as $tag) {
                if (! in_array($tag->value, $targetTagValues, true)) {
                    SongTag::create([
                        'song_id' => $targetSong->id,
                        'value' => $tag->value,
                    ]);
                    $migratedTags++;
                }
            }

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
                    'deleted_duplicate_mappings' => $deletedDuplicates,
                    'affected_ts_items' => $affectedTsItems,
                    'affected_decompositions' => $affectedDecompositions,
                    'migrated_tags' => $migratedTags,
                ]
            );

            // マージ元を削除
            $sourceSong->delete();

            return [
                'affected_mappings' => $affectedMappings,
                'affected_ts_items' => $affectedTsItems,
                'affected_decompositions' => $affectedDecompositions,
                'migrated_tags' => $migratedTags,
            ];
        });
    }
}

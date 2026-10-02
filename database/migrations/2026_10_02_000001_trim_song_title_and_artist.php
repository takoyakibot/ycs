<?php

use App\Helpers\TextNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->upSqlite();
        } else {
            $this->upMysql();
        }
    }

    private function upMysql(): void
    {
        // 衝突する楽曲（trimしたら既存と重複する）を先に処理
        $collisions = DB::select('
            SELECT s.id AS source_id, s2.id AS target_id
            FROM songs s
            INNER JOIN songs s2
              ON s2.title = TRIM(s.title)
              AND s2.artist = TRIM(s.artist)
              AND s2.id != s.id
              AND s2.title = TRIM(s2.title)
              AND s2.artist = TRIM(s2.artist)
            WHERE s.title != TRIM(s.title) OR s.artist != TRIM(s.artist)
        ');

        foreach ($collisions as $row) {
            $this->mergeSong($row->source_id, $row->target_id);
        }

        // 衝突しない楽曲は一括UPDATE
        $remaining = DB::select('
            SELECT id, TRIM(title) AS trimmed_title, TRIM(artist) AS trimmed_artist
            FROM songs
            WHERE title != TRIM(title) OR artist != TRIM(artist)
        ');

        foreach ($remaining as $row) {
            $normalizedTitle = TextNormalizer::normalize($row->trimmed_title);
            $normalizedArtist = TextNormalizer::normalize($row->trimmed_artist);
            DB::table('songs')->where('id', $row->id)->update([
                'title' => $row->trimmed_title,
                'artist' => $row->trimmed_artist,
                'normalized_title' => $normalizedTitle,
                'normalized_artist' => $normalizedArtist,
                'title_comparison_key' => TextNormalizer::toComparisonKey($normalizedTitle) ?: null,
                'artist_comparison_key' => TextNormalizer::toComparisonKey($normalizedArtist) ?: null,
            ]);
        }
    }

    private function upSqlite(): void
    {
        $remaining = DB::select('
            SELECT id, TRIM(title) AS trimmed_title, TRIM(artist) AS trimmed_artist
            FROM songs
            WHERE title != TRIM(title) OR artist != TRIM(artist)
        ');

        foreach ($remaining as $row) {
            $normalizedTitle = TextNormalizer::normalize($row->trimmed_title);
            $normalizedArtist = TextNormalizer::normalize($row->trimmed_artist);
            DB::table('songs')->where('id', $row->id)->update([
                'title' => $row->trimmed_title,
                'artist' => $row->trimmed_artist,
                'normalized_title' => $normalizedTitle,
                'normalized_artist' => $normalizedArtist,
                'title_comparison_key' => TextNormalizer::toComparisonKey($normalizedTitle) ?: null,
                'artist_comparison_key' => TextNormalizer::toComparisonKey($normalizedArtist) ?: null,
            ]);
        }
    }

    private function mergeSong(string $sourceId, string $targetId): void
    {
        DB::transaction(function () use ($sourceId, $targetId) {
            // targetに既存のマッピングのnormalized_textを取得
            $targetTexts = DB::table('timestamp_song_mappings')
                ->where('song_id', $targetId)
                ->pluck('normalized_text')
                ->toArray();

            // 重複するマッピングを削除
            if (! empty($targetTexts)) {
                DB::table('timestamp_song_mappings')
                    ->where('song_id', $sourceId)
                    ->whereIn('normalized_text', $targetTexts)
                    ->delete();
            }

            // 残りのマッピングを付け替え
            DB::table('timestamp_song_mappings')
                ->where('song_id', $sourceId)
                ->update(['song_id' => $targetId]);

            // ts_items.song_idを付け替え
            DB::table('ts_items')
                ->where('song_id', $sourceId)
                ->update(['song_id' => $targetId]);

            // timestamp_decompositions.song_idを付け替え
            DB::table('timestamp_decompositions')
                ->where('song_id', $sourceId)
                ->update(['song_id' => $targetId]);

            // タグを移行
            $targetTags = DB::table('song_tags')
                ->where('song_id', $targetId)
                ->pluck('value')
                ->toArray();

            $sourceTags = DB::table('song_tags')
                ->where('song_id', $sourceId)
                ->get();

            foreach ($sourceTags as $tag) {
                if (! in_array($tag->value, $targetTags, true)) {
                    DB::table('song_tags')->insert([
                        'id' => (string) \Illuminate\Support\Str::ulid(),
                        'song_id' => $targetId,
                        'value' => $tag->value,
                        'normalized_value' => $tag->normalized_value ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // マージ元のタグと楽曲を削除
            DB::table('song_tags')->where('song_id', $sourceId)->delete();
            DB::table('songs')->where('id', $sourceId)->delete();
        });
    }

    public function down(): void
    {
        // データクレンジングのため元に戻すことはできない
    }
};

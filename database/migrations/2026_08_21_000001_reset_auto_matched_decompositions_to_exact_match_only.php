<?php

use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * TS分解の自動判定を「マスタとの完全一致」のみに変更したため、
     * 旧ヒューリスティック（文字種比率での推測、cascadeArtistSelectionによる
     * アーティスト名の転記）で auto_matched になっていたレコードを全て
     * 手動選別（pending）に戻す。
     *
     * 合わせて、その旧ロジックが linkToSong() 経由で作ってしまった
     * アーティスト名が空の songs マスタと、それに紐づく
     * timestamp_song_mappings を削除する。空アーティストの songs は、
     * このマイグレーションで参照を外した後も他の行から参照され続けている
     * 場合は削除しない（他の行が正当に同じマスタを指している可能性を
     * 壊さないため）。アーティスト名が入っている songs（cascadeArtistSelection
     * が結果的に正しく推測できていたケースなど）は、未参照になっても
     * データとしては正しい可能性があるため削除しない。
     */
    public function up(): void
    {
        TimestampDecomposition::where('status', TimestampDecomposition::STATUS_AUTO_MATCHED)
            ->chunkById(200, function ($decompositions) {
                foreach ($decompositions as $decomposition) {
                    $songId = $decomposition->song_id;

                    // 紐付けられていたマッピングを削除
                    TimestampSongMapping::where('normalized_text', $decomposition->normalized_text)->delete();

                    $decomposition->update([
                        'title_part_index' => null,
                        'artist_part_index' => null,
                        'derived_title' => null,
                        'derived_artist' => null,
                        'status' => TimestampDecomposition::STATUS_PENDING,
                        'song_id' => null,
                        'confidence' => null,
                    ]);

                    if ($songId === null) {
                        continue;
                    }

                    $song = Song::find($songId);

                    if ($song === null || ! $this->isBlank($song->artist)) {
                        continue;
                    }

                    // 他に参照するマッピング・分解レコードが無くなった場合のみ削除
                    $stillReferenced = TimestampSongMapping::where('song_id', $songId)->exists()
                        || TimestampDecomposition::where('song_id', $songId)->exists();

                    if (! $stillReferenced) {
                        $song->delete();
                    }
                }
            });
    }

    private function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    /**
     * Reverse the migrations.
     *
     * 誤判定の確定を取り消し、それによって作られたデータを削除するための
     * データ移行のため、元に戻す処理は行わない
     */
    public function down(): void
    {
        // 削除したデータ・取り消した確定を復元する必要はないため、何もしない
    }
};

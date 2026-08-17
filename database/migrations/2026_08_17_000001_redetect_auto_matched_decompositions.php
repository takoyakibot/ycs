<?php

use App\Helpers\TextNormalizer;
use App\Models\TimestampDecomposition;
use App\Services\TimestampDecompositionService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 無視キーワードの判定が部分一致だったため、キーワードを語の一部に含む
     * アーティスト名（例: "Official髭男dism"）が無視対象と判定され、
     * アーティスト名なしで自動判定されたレコードが存在する。
     * まだ楽曲マスタに紐付いていないものを再判定し、
     * 自動判定できなくなったものは手動選別（pending）に戻す。
     */
    public function up(): void
    {
        TimestampDecomposition::where('status', TimestampDecomposition::STATUS_AUTO_MATCHED)
            ->whereNull('song_id')
            ->whereNull('artist_part_index')
            ->chunkById(200, function ($decompositions) {
                foreach ($decompositions as $decomposition) {
                    $parts = $decomposition->parts ?? [];

                    if (count($parts) < 2) {
                        continue;
                    }

                    $detection = TextNormalizer::detectTitleArtistPattern($parts);

                    // 再判定でも自動選択できる場合は判定結果を反映して維持
                    if ($detection['confidence'] >= TimestampDecompositionService::AUTO_SELECT_THRESHOLD) {
                        $titleIndex = $detection['title_index'];
                        $artistIndex = $detection['artist_index'];

                        $decomposition->update([
                            'title_part_index' => $titleIndex,
                            'artist_part_index' => $artistIndex,
                            'derived_title' => $titleIndex !== null ? ($parts[$titleIndex] ?? null) : null,
                            'derived_artist' => $artistIndex !== null ? ($parts[$artistIndex] ?? null) : null,
                            'confidence' => $detection['confidence'],
                        ]);

                        continue;
                    }

                    // 自動判定できなくなったものは手動選別に戻す
                    $decomposition->update([
                        'title_part_index' => null,
                        'artist_part_index' => null,
                        'derived_title' => null,
                        'derived_artist' => null,
                        'status' => TimestampDecomposition::STATUS_PENDING,
                        'confidence' => $detection['confidence'],
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * 誤判定を修正するデータ移行のため、元に戻す処理は行わない
     */
    public function down(): void
    {
        // 元の誤判定結果に戻す必要はないため、何もしない
    }
};

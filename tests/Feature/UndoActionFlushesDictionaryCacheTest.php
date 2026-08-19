<?php

namespace Tests\Feature;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use App\Models\User;
use App\Services\MappingDictionaryService;
use App\Services\TimestampDecompositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * undo で解除した紐付けが照合辞書に残らないこと
 *
 * undoAction() のマッピング解除はクエリビルダの一括UPDATEなのでモデルイベントが
 * 発火せず、TimestampSongMapping::saved のフックによる辞書キャッシュの破棄が走らない。
 * 明示的に破棄していないと、取り消した紐付けが最大 index_cache_ttl 秒のあいだ
 * 照合の根拠として使われ続ける。
 */
class UndoActionFlushesDictionaryCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_undo_removes_the_entry_from_the_matching_dictionary(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        $normalized = TextNormalizer::normalize('♪ロキ / みきとP');

        $decomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => $normalized,
            'original_text' => '♪ロキ / みきとP',
            'parts' => ['ロキ', 'みきとP'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_AUTO_MATCHED,
            'title_part_index' => 0,
            'derived_title' => 'ロキ',
            'artist_part_index' => 1,
            'derived_artist' => 'みきとP',
            'song_id' => $song->id,
            'confidence' => 0.9,
        ]);

        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => $normalized,
            'song_id' => $song->id,
            'is_not_song' => false,
            'is_manual' => true,
            'status' => 'linked',
        ]);

        // 辞書をキャッシュに載せる（別リクエスト相当のインスタンスで確認する）。
        // 装飾を除くと辞書エントリと同一キーになる表記を使う。
        // 「(cover)」のような語を足すとキー長差が MAX_LENGTH_DIFFERENCE を超えて
        // 類似度判定の手前で弾かれ、キャッシュの有無を観測できない。
        $variant = TextNormalizer::normalize('★ロキ / みきとＰ★');
        $warm = new MappingDictionaryService(app(\App\Services\SimilarityService::class));
        $this->assertNotEmpty($warm->findCandidates($variant));

        app(TimestampDecompositionService::class)->undoAction($decomposition->id);

        // 解除後は、新しいインスタンス（= 次のリクエスト相当）から見て候補が消えていること。
        // flushIndex() は呼ばない。呼ぶとキャッシュ破棄の有無を検証できなくなる。
        $fresh = new MappingDictionaryService(app(\App\Services\SimilarityService::class));
        $this->assertEmpty($fresh->findCandidates($variant));
    }
}

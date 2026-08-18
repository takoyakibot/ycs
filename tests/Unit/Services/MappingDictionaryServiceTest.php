<?php

namespace Tests\Unit\Services;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Services\MappingDictionaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MappingDictionaryServiceTest extends TestCase
{
    use RefreshDatabase;

    private MappingDictionaryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MappingDictionaryService::class);
    }

    private function createMapping(string $text, Song $song, bool $isManual = true, array $overrides = []): TimestampSongMapping
    {
        return TimestampSongMapping::create(array_merge([
            'normalized_text' => TextNormalizer::normalize($text),
            'song_id' => $song->id,
            'is_not_song' => false,
            'status' => TimestampSongMapping::STATUS_LINKED,
            'is_manual' => $isManual,
            'confidence' => 1.0,
        ], $overrides));
    }

    /**
     * @return array{song_id: string, title: string, artist: string, confidence: float, source_text: string, similarity: float}|null
     */
    private function findBestMatch(string $normalizedText): ?array
    {
        return $this->service->findCandidates($normalizedText, 1)[0] ?? null;
    }

    #[Test]
    public function 装飾を除いたキーが一致する既存表記から楽曲を特定できる(): void
    {
        $song = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        $this->createMapping('ロキ（みきとP）', $song);

        // 装飾が異なるため normalized_text は一致しないが、照合キーは同一になる
        $match = $this->findBestMatch(TextNormalizer::normalize('♪ロキ (みきとP)'));

        $this->assertNotNull($match);
        $this->assertSame($song->id, $match['song_id']);
        $this->assertSame(MappingDictionaryService::CONFIDENCE_KEY_MATCH, $match['confidence']);
    }

    #[Test]
    public function 表記が僅かに異なる既存表記を類似度で拾える(): void
    {
        $song = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        $this->createMapping('ロキ / みきとピー', $song);

        $match = $this->findBestMatch(TextNormalizer::normalize('ロキ / みきとピ'));

        $this->assertNotNull($match);
        $this->assertSame($song->id, $match['song_id']);
        $this->assertGreaterThan(0.0, $match['similarity']);
    }

    /**
     * 自動紐付けの誤りを根拠に次の紐付けが行われると、誤りが連鎖して広がる。
     */
    #[Test]
    public function 自動紐付けのマッピングは辞書に含まれない(): void
    {
        $song = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        $this->createMapping('ロキ（みきとP）', $song, isManual: false);

        $match = $this->findBestMatch(TextNormalizer::normalize('♪ロキ (みきとP)'));

        $this->assertNull($match);
    }

    #[Test]
    public function 楽曲ではないとマークされたマッピングは辞書に含まれない(): void
    {
        $song = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        $this->createMapping('ロキ（みきとP）', $song, overrides: ['is_not_song' => true]);

        $match = $this->findBestMatch(TextNormalizer::normalize('♪ロキ (みきとP)'));

        $this->assertNull($match);
    }

    #[Test]
    public function 保留状態のマッピングは辞書に含まれない(): void
    {
        $song = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        $this->createMapping('ロキ（みきとP）', $song, overrides: [
            'status' => TimestampSongMapping::STATUS_PENDING,
        ]);

        $match = $this->findBestMatch(TextNormalizer::normalize('♪ロキ (みきとP)'));

        $this->assertNull($match);
    }

    #[Test]
    public function 全く似ていない表記は候補にならない(): void
    {
        $song = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        $this->createMapping('ロキ / みきとP', $song);

        $this->assertNull($this->findBestMatch('まったく関係のない文字列'));
    }

    #[Test]
    public function 文字数が大きく異なる表記は候補にならない(): void
    {
        $song = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        $this->createMapping('ロキ', $song);

        // 先頭は一致するが文字数の差が大きい
        $match = $this->findBestMatch('ロキシーミュージックの長い曲名です');

        $this->assertNull($match);
    }

    #[Test]
    public function 照合できない短いテキストは候補を返さない(): void
    {
        $song = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        $this->createMapping('ロキ', $song);

        $this->assertEmpty($this->service->findCandidates('あ'));
        $this->assertEmpty($this->service->findCandidates('♪'));
        $this->assertEmpty($this->service->findCandidates(''));
    }

    /**
     * 先勝ちで1件に絞ると衝突が候補から見えなくなり、
     * 「同信頼度の候補が並んだら自動紐付けしない」判定が機能しなくなる。
     */
    #[Test]
    public function 同じキーが異なる楽曲を指す場合は両方が候補になる(): void
    {
        $songA = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        $songB = Song::factory()->create(['title' => 'ロキ(cover)', 'artist' => 'だれか']);

        // 照合キーはどちらも「ロキみきとp」になる
        $this->createMapping('ロキ / みきとP', $songA);
        $this->createMapping('ロキ・みきとP', $songB);

        $candidates = $this->service->findCandidates(TextNormalizer::normalize('♪ロキ／みきとP'));

        $this->assertCount(2, $candidates);
        $this->assertEqualsCanonicalizing(
            [$songA->id, $songB->id],
            array_column($candidates, 'song_id')
        );
        // 両方とも同じ信頼度（呼び出し側で曖昧と判定される）
        $this->assertSame($candidates[0]['confidence'], $candidates[1]['confidence']);
    }

    #[Test]
    public function キーが衝突する場合は自動紐付けされない(): void
    {
        $songA = Song::factory()->create(['title' => 'Song A', 'artist' => 'Artist A']);
        $songB = Song::factory()->create(['title' => 'Song B', 'artist' => 'Artist B']);

        $this->createMapping('カタルシス / うた', $songA);
        $this->createMapping('カタルシス・うた', $songB);

        $matchingService = app(\App\Services\SongMatchingService::class);
        $match = $matchingService->findBestMatch(TextNormalizer::normalize('♪カタルシス うた'));

        $this->assertNull($match, 'キーが衝突している場合は候補提示に留めるべき');
    }

    #[Test]
    public function 同じ楽曲は候補内で重複しない(): void
    {
        $song = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        $this->createMapping('ロキ（みきとP）', $song);
        $this->createMapping('ロキ〔みきとP〕', $song);

        $candidates = $this->service->findCandidates(TextNormalizer::normalize('♪ロキ (みきとP)'));

        $this->assertCount(1, $candidates);
    }

    #[Test]
    public function マッピング追加後はインデックスを破棄して再照合できる(): void
    {
        $song = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);

        $this->assertNull($this->findBestMatch(TextNormalizer::normalize('♪ロキ (みきとP)')));

        $this->createMapping('ロキ（みきとP）', $song);
        $this->service->flushIndex();

        $this->assertNotNull($this->findBestMatch(TextNormalizer::normalize('♪ロキ (みきとP)')));
    }
}

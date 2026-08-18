<?php

namespace Tests\Unit\Services;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Services\TimestampDecompositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoMatchedListTest extends TestCase
{
    use RefreshDatabase;

    private TimestampDecompositionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TimestampDecompositionService;
    }

    private function createDecomposition(string $text, array $attributes = []): TimestampDecomposition
    {
        return TimestampDecomposition::create(array_merge([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize($text).'-'.Str::random(6),
            'original_text' => $text,
            'parts' => ['曲名', 'アーティスト'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_AUTO_MATCHED,
            'title_part_index' => 0,
            'derived_title' => '曲名',
            'artist_part_index' => 1,
            'derived_artist' => 'アーティスト',
            'confidence' => 0.8,
        ], $attributes));
    }

    /**
     * auto_matched 以外のステータスは一覧に含まれないこと
     */
    public function test_returns_only_auto_matched(): void
    {
        $target = $this->createDecomposition('対象 / アーティスト');
        $this->createDecomposition('待ち / アーティスト', ['status' => TimestampDecomposition::STATUS_PENDING]);
        $this->createDecomposition('済み / アーティスト', ['status' => TimestampDecomposition::STATUS_SELECTED]);
        $this->createDecomposition('スキップ / アーティスト', ['status' => TimestampDecomposition::STATUS_SKIPPED]);

        $result = $this->service->getAutoMatchedList();

        $this->assertCount(1, $result);
        $this->assertEquals($target->id, $result->first()->id);
    }

    /**
     * 紐付け済み・未紐付けの両方が含まれること
     */
    public function test_includes_both_linked_and_unlinked(): void
    {
        $song = Song::factory()->create();
        $this->createDecomposition('紐付け済み / アーティスト', ['song_id' => $song->id]);
        $this->createDecomposition('未紐付け / アーティスト');

        $this->assertCount(2, $this->service->getAutoMatchedList());
    }

    /**
     * filter=linked で紐付け済みだけに絞られること
     */
    public function test_filter_linked(): void
    {
        $song = Song::factory()->create();
        $linked = $this->createDecomposition('紐付け済み / アーティスト', ['song_id' => $song->id]);
        $this->createDecomposition('未紐付け / アーティスト');

        $result = $this->service->getAutoMatchedList('linked');

        $this->assertCount(1, $result);
        $this->assertEquals($linked->id, $result->first()->id);
    }

    /**
     * filter=unlinked で未紐付けだけに絞られること
     */
    public function test_filter_unlinked(): void
    {
        $song = Song::factory()->create();
        $this->createDecomposition('紐付け済み / アーティスト', ['song_id' => $song->id]);
        $unlinked = $this->createDecomposition('未紐付け / アーティスト');

        $result = $this->service->getAutoMatchedList('unlinked');

        $this->assertCount(1, $result);
        $this->assertEquals($unlinked->id, $result->first()->id);
    }

    /**
     * filter=empty_artist は、紐付け済みなら songs.artist、未紐付けなら derived_artist を見ること
     */
    public function test_filter_empty_artist_checks_both_sources(): void
    {
        $emptyArtistSong = Song::factory()->create(['artist' => '']);
        $filledArtistSong = Song::factory()->create(['artist' => 'アーティスト']);

        $linkedEmpty = $this->createDecomposition('紐付け済み・空 / x', ['song_id' => $emptyArtistSong->id]);
        $unlinkedEmpty = $this->createDecomposition('未紐付け・空', [
            'parts' => ['曲名'],
            'separator_count' => 0,
            'artist_part_index' => null,
            'derived_artist' => null,
        ]);
        $this->createDecomposition('紐付け済み・あり / x', ['song_id' => $filledArtistSong->id]);
        $this->createDecomposition('未紐付け・あり / アーティスト');

        $result = $this->service->getAutoMatchedList('empty_artist');

        $this->assertCount(2, $result);
        $ids = $result->pluck('id')->all();
        $this->assertContains($linkedEmpty->id, $ids);
        $this->assertContains($unlinkedEmpty->id, $ids);
    }

    /**
     * 不正な filter 値は「すべて」として扱われること
     */
    public function test_invalid_filter_returns_all(): void
    {
        $song = Song::factory()->create();
        $this->createDecomposition('紐付け済み / アーティスト', ['song_id' => $song->id]);
        $this->createDecomposition('未紐付け / アーティスト');

        $this->assertCount(2, $this->service->getAutoMatchedList('nonsense'));
    }

    /**
     * updated_at の降順で並ぶこと
     */
    public function test_orders_by_updated_at_desc(): void
    {
        $old = $this->createDecomposition('古い / アーティスト');
        $new = $this->createDecomposition('新しい / アーティスト');

        $old->timestamps = false;
        $old->updated_at = now()->subDay();
        $old->save();

        $result = $this->service->getAutoMatchedList();

        $this->assertEquals($new->id, $result->first()->id);
    }

    /**
     * updated_at が同値でも、id を第2キーにしてページ間で重複・欠落が起きないこと
     *
     * スキャンや一括紐付けは同一リクエスト内で大量の行を作成・更新するため、
     * updated_at が秒精度で同値になるのが普通。ORDER BY updated_at のみだと
     * MySQL は同値キーの LIMIT/OFFSET の順序を保証しないため、id を降順の
     * 第2キーに加えて決定的な並びにする。
     */
    public function test_orders_deterministically_when_updated_at_ties(): void
    {
        $items = [];
        for ($i = 0; $i < 6; $i++) {
            $items[] = $this->createDecomposition("同時刻{$i} / アーティスト");
        }

        // 全レコードの updated_at を同一時刻に揃える（スキャン・一括紐付けで実際に起こる状況を再現）
        $sameTime = now();
        foreach ($items as $item) {
            $item->timestamps = false;
            $item->updated_at = $sameTime;
            $item->save();
        }

        \Illuminate\Pagination\Paginator::currentPageResolver(fn () => 1);
        $page1 = $this->service->getAutoMatchedList(null, 3);

        \Illuminate\Pagination\Paginator::currentPageResolver(fn () => 2);
        $page2 = $this->service->getAutoMatchedList(null, 3);

        $page1Ids = $page1->pluck('id')->all();
        $page2Ids = $page2->pluck('id')->all();

        // ページ間で重複がないこと
        $this->assertEmpty(array_intersect($page1Ids, $page2Ids));

        // 全件が過不足なく id の降順でページに分配されること
        $expectedIds = collect($items)->pluck('id')->sortDesc()->values()->all();
        $this->assertEquals($expectedIds, array_merge($page1Ids, $page2Ids));
    }

    /**
     * ページングされること
     */
    public function test_paginates(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createDecomposition("曲{$i} / アーティスト");
        }

        $result = $this->service->getAutoMatchedList(null, 2);

        $this->assertCount(2, $result);
        $this->assertEquals(3, $result->total());
        $this->assertEquals(2, $result->lastPage());
    }

    /**
     * song リレーションが eager load されていること（N+1 回避）
     */
    public function test_eager_loads_song(): void
    {
        $song = Song::factory()->create();
        $this->createDecomposition('紐付け済み / アーティスト', ['song_id' => $song->id]);

        $result = $this->service->getAutoMatchedList();

        $this->assertTrue($result->first()->relationLoaded('song'));
    }
}

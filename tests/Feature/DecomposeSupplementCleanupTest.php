<?php

namespace Tests\Feature;

use App\Helpers\SupplementStripper;
use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TS分解画面の補足除去候補（] キー）まわり
 */
class DecomposeSupplementCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        SupplementStripper::flushKeywordCache();

        parent::tearDown();
    }

    private function createDecomposition(string $text, array $parts, array $attributes = []): TimestampDecomposition
    {
        return TimestampDecomposition::create(array_merge([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize($text),
            'original_text' => $text,
            'parts' => $parts,
            'separator_count' => count($parts) - 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ], $attributes));
    }

    /**
     * next が補足を除去した候補を同じ並び・同じ要素数で返すこと
     */
    public function test_next_returns_cleaned_parts(): void
    {
        $this->createDecomposition(
            '気まぐれロマンティック / いきものがかり (エコーかけ忘れ)',
            ['気まぐれロマンティック', 'いきものがかり (エコーかけ忘れ)']
        );

        $response = $this->getJson('/api/songs/decompose/next');

        $response->assertOk();
        $response->assertJsonPath('item.parts', ['気まぐれロマンティック', 'いきものがかり (エコーかけ忘れ)']);
        $response->assertJsonPath('item.cleaned_parts', ['気まぐれロマンティック', 'いきものがかり']);
    }

    /**
     * 括弧なしの補足・全角括弧・装飾記号もパーツ単位で除去されること
     */
    public function test_next_cleans_various_supplement_styles(): void
    {
        $this->createDecomposition(
            '♫気まぐれロマンティック / いきものがかり 　エコーかけ忘れ',
            ['♫気まぐれロマンティック', 'いきものがかり 　エコーかけ忘れ']
        );

        $this->getJson('/api/songs/decompose/next')
            ->assertOk()
            ->assertJsonPath('item.cleaned_parts', ['気まぐれロマンティック', 'いきものがかり']);
    }

    /**
     * 区切りが無いテキスト（パーツが1つ）では区切り以降ルールを適用しないこと
     *
     * 「YOASOBI　アンコール」の後半が補足なのか曲名の一部なのか判別できないため、
     * 候補として曲名を削ってしまわないようにする
     */
    public function test_next_keeps_single_part_without_separator(): void
    {
        $this->createDecomposition(
            'YOASOBI　アンコール',
            ['YOASOBI　アンコール']
        );

        $this->getJson('/api/songs/decompose/next')
            ->assertOk()
            ->assertJsonPath('item.cleaned_parts', ['YOASOBI　アンコール']);
    }

    /**
     * 曲名の一部としての括弧は候補でも残ること
     */
    public function test_next_keeps_meaningful_brackets(): void
    {
        $this->createDecomposition(
            'Story (Digital Edition) / 平井大',
            ['Story (Digital Edition)', '平井大']
        );

        $this->getJson('/api/songs/decompose/next')
            ->assertOk()
            ->assertJsonPath('item.cleaned_parts', ['Story (Digital Edition)', '平井大']);
    }

    /**
     * 候補をそのまま確定できること
     */
    public function test_select_accepts_cleaned_values(): void
    {
        $decomposition = $this->createDecomposition(
            '気まぐれロマンティック / いきものがかり (エコーかけ忘れ)',
            ['気まぐれロマンティック', 'いきものがかり (エコーかけ忘れ)']
        );

        $this->postJson('/api/songs/decompose/select', [
            'id' => $decomposition->id,
            'title_indices' => [0],
            'artist_indices' => [1],
            'title' => '気まぐれロマンティック',
            'artist' => 'いきものがかり',
            'link_to_song' => true,
        ])->assertOk()
            ->assertJsonPath('decomposition.derived_artist', 'いきものがかり');

        $this->assertDatabaseHas('songs', [
            'title' => '気まぐれロマンティック',
            'artist' => 'いきものがかり',
        ]);
    }

    /**
     * 微調整した内容がそのまま保存されること
     */
    public function test_select_accepts_manually_adjusted_values(): void
    {
        $decomposition = $this->createDecomposition(
            '気まぐれロマンティック / いきものがかり (エコーかけ忘れ)',
            ['気まぐれロマンティック', 'いきものがかり (エコーかけ忘れ)']
        );

        $this->postJson('/api/songs/decompose/select', [
            'id' => $decomposition->id,
            'title_indices' => [0],
            'artist_indices' => [1],
            'title' => '気まぐれロマンティック',
            'artist' => 'いきものがかり（手入力）',
        ])->assertOk();

        $decomposition->refresh();

        $this->assertEquals('いきものがかり（手入力）', $decomposition->derived_artist);
    }

    /**
     * 前後の空白は落として保存すること
     */
    public function test_select_trims_overrides(): void
    {
        $decomposition = $this->createDecomposition('曲名 / アーティスト', ['曲名', 'アーティスト']);

        $this->postJson('/api/songs/decompose/select', [
            'id' => $decomposition->id,
            'title_indices' => [0],
            'artist_indices' => [1],
            'title' => '  曲名  ',
            'artist' => '  アーティスト  ',
        ])->assertOk();

        $decomposition->refresh();

        $this->assertEquals('曲名', $decomposition->derived_title);
        $this->assertEquals('アーティスト', $decomposition->derived_artist);
    }

    /**
     * title / artist を送らなければ従来どおりパーツ連結で確定すること
     */
    public function test_select_without_overrides_keeps_part_based_behaviour(): void
    {
        $decomposition = $this->createDecomposition(
            '気まぐれロマンティック / いきものがかり (エコーかけ忘れ)',
            ['気まぐれロマンティック', 'いきものがかり (エコーかけ忘れ)']
        );

        $this->postJson('/api/songs/decompose/select', [
            'id' => $decomposition->id,
            'title_indices' => [0],
            'artist_indices' => [1],
        ])->assertOk();

        $decomposition->refresh();

        $this->assertEquals('いきものがかり (エコーかけ忘れ)', $decomposition->derived_artist);
    }

    /**
     * アーティストを空にして確定できること
     */
    public function test_select_allows_clearing_artist(): void
    {
        $decomposition = $this->createDecomposition('曲名 / アーティスト', ['曲名', 'アーティスト']);

        $this->postJson('/api/songs/decompose/select', [
            'id' => $decomposition->id,
            'title_indices' => [0],
            'artist_indices' => [1],
            'title' => '曲名',
            'artist' => '',
        ])->assertOk();

        $decomposition->refresh();

        $this->assertNull($decomposition->derived_artist);
    }

    /**
     * 長すぎる値は弾くこと
     */
    public function test_select_rejects_too_long_override(): void
    {
        $decomposition = $this->createDecomposition('曲名 / アーティスト', ['曲名', 'アーティスト']);

        $this->postJson('/api/songs/decompose/select', [
            'id' => $decomposition->id,
            'title_indices' => [0],
            'title' => str_repeat('あ', 256),
        ])->assertStatus(422);
    }

    /**
     * 補足付きの表記で既にマスタができている場合でも、綺麗な方に寄せられること
     */
    public function test_cleaned_values_link_to_existing_song(): void
    {
        $song = Song::factory()->withoutSpotify()->create([
            'title' => '気まぐれロマンティック',
            'artist' => 'いきものがかり',
        ]);

        $decomposition = $this->createDecomposition(
            '気まぐれロマンティック / いきものがかり (エコーかけ忘れ)',
            ['気まぐれロマンティック', 'いきものがかり (エコーかけ忘れ)']
        );

        $this->postJson('/api/songs/decompose/select', [
            'id' => $decomposition->id,
            'title_indices' => [0],
            'artist_indices' => [1],
            'title' => '気まぐれロマンティック',
            'artist' => 'いきものがかり',
            'link_to_song' => true,
        ])->assertOk()
            ->assertJsonPath('song.id', $song->id);

        $this->assertEquals(1, Song::count());
    }
}

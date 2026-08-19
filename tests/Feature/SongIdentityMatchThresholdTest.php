<?php

namespace Tests\Feature;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 新規登録時の同一性判定が別楽曲を拾わないこと
 *
 * SongSearchService::findExactMatch() は TimestampSongMapping::fuzzySearch() を
 * 使うが、その既定値 0.7 は距離をバイト単位で数えていた頃に調整された値で、
 * 文字単位に変えた後は同一性の判定には緩すぎる。閾値を明示的に渡している。
 */
class SongIdentityMatchThresholdTest extends TestCase
{
    use RefreshDatabase;

    private function linkedMapping(string $text, Song $song): void
    {
        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize($text),
            'song_id' => $song->id,
            'is_not_song' => false,
            'is_manual' => true,
            'status' => 'linked',
        ]);
    }

    /**
     * 曲名が同じでアーティストが違う楽曲を「既に登録済み」と誤認しないこと
     *
     * 実測: この2つの類似度は 0.75。fuzzySearch の既定値 0.7 では一致してしまい、
     * 別アーティストの楽曲マスタが exact_match として返って新規登録が阻止される。
     */
    public function test_does_not_treat_different_artist_as_already_registered(): void
    {
        $this->actingAs(User::factory()->create());

        $existing = Song::factory()->create([
            'title' => 'あの夏が飽和するまでのカウントダウン',
            'artist' => 'あるふぁきゅん',
        ]);
        $this->linkedMapping('あの夏が飽和するまでのカウントダウン / あるふぁきゅん', $existing);

        $response = $this->postJson('/api/songs', [
            'title' => 'あの夏が飽和するまでのカウントダウン',
            'artist' => 'ぴのぴ',
        ]);

        // 阻止されずに新規登録される（201）のが正しい挙動
        $response->assertSuccessful();
        $this->assertNotSame('exact_match', $response->json('status'));
        $this->assertNotSame($existing->id, $response->json('song.id'));
        $this->assertDatabaseHas('songs', [
            'title' => 'あの夏が飽和するまでのカウントダウン',
            'artist' => 'ぴのぴ',
        ]);
    }

    /**
     * 正規化すると同一になる表記は「既に登録済み」と判定されること
     *
     * これは fuzzySearch() の完全一致パス（閾値の手前）で拾われるので、
     * 閾値をいくら上げても通る。閾値そのものの検証は下のテストで行う。
     */
    public function test_detects_normalization_equivalent_as_already_registered(): void
    {
        $this->actingAs(User::factory()->create());

        $existing = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        $this->linkedMapping('ロキ / みきとP', $existing);

        $response = $this->postJson('/api/songs', [
            'title' => 'ロキ',
            'artist' => 'みきとＰ',
        ]);

        $response->assertOk();
        $this->assertSame('exact_match', $response->json('status'));
        $this->assertSame($existing->id, $response->json('song.id'));
    }

    /**
     * 閾値を上げすぎてあいまいパスが死んでいないこと
     *
     * 正規化しても別文字列だが実質同一の表記を、あいまい一致で拾える必要がある。
     * 実測: この2つの類似度は 0.9615（正規化後も別文字列）。
     * 閾値を信頼度の上限より上（1.0 超）にすると、このパスが完全に死ぬ。
     */
    public function test_detects_near_identical_text_through_fuzzy_path(): void
    {
        $this->actingAs(User::factory()->create());

        $existing = Song::factory()->create([
            'title' => 'グッバイ宣言',
            'artist' => 'Chinozo feat.フロクロ',
        ]);
        $this->linkedMapping('グッバイ宣言 / Chinozo feat.フロクロ', $existing);

        // 区切りが「.」から半角スペースに変わっただけの表記
        $response = $this->postJson('/api/songs', [
            'title' => 'グッバイ宣言',
            'artist' => 'Chinozo feat フロクロ',
        ]);

        $response->assertOk();
        $this->assertSame('exact_match', $response->json('status'));
        $this->assertSame($existing->id, $response->json('song.id'));
    }
}

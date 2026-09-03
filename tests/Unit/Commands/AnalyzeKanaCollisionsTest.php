<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\AnalyzeKanaCollisions;
use App\Helpers\TextNormalizer;
use App\Models\Archive;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyzeKanaCollisionsTest extends TestCase
{
    use RefreshDatabase;

    private function mapping(string $normalizedText, array $attributes = []): TimestampSongMapping
    {
        return TimestampSongMapping::create(array_merge([
            'id' => (string) Str::ulid(),
            'normalized_text' => $normalizedText,
            'is_not_song' => false,
        ], $attributes));
    }

    private function decomposition(string $normalizedText, array $attributes = []): TimestampDecomposition
    {
        return TimestampDecomposition::create(array_merge([
            'id' => (string) Str::ulid(),
            'normalized_text' => $normalizedText,
            'original_text' => $normalizedText,
            'parts' => [$normalizedText],
            'separator_count' => 0,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ], $attributes));
    }

    /**
     * 変換の前提: 保存済みの値に KV を足したものが正規形になること
     */
    public function test_converts_half_width_kana_to_full_width(): void
    {
        $this->assertSame('イエスタデイ', AnalyzeKanaCollisions::toFullWidthKana('ｲｴｽﾀﾃﾞｲ'));
        $this->assertSame('イエスタデイ', AnalyzeKanaCollisions::toFullWidthKana('イエスタデイ'));
        $this->assertSame('ガグ', AnalyzeKanaCollisions::toFullWidthKana('ｶﾞｸﾞ'));

        // 濁点が独立した文字として残らないこと（V なしだと「テ゛」になる）
        $this->assertStringNotContainsString('゛', AnalyzeKanaCollisions::toFullWidthKana('ｲｴｽﾀﾃﾞｲ'));
    }

    /**
     * 半角カナが無ければ衝突ゼロと報告すること
     */
    public function test_reports_no_collision_when_all_full_width(): void
    {
        $this->mapping('イエスタデイ');
        $this->mapping('あいことば');

        $this->artisan('normalized-text:analyze-kana-collisions')
            ->expectsOutputToContain('衝突はありません')
            ->assertSuccessful();
    }

    /**
     * 半角と全角が共存していれば衝突として数えること
     */
    public function test_detects_collision_between_half_and_full_width(): void
    {
        $song = Song::factory()->create();
        $this->mapping('ｲｴｽﾀﾃﾞｲ', ['song_id' => $song->id]);
        $this->mapping('イエスタデイ', ['song_id' => $song->id]);

        // 同じ song_id を指しているので「片方を消せる」側に分類される
        $this->artisan('normalized-text:analyze-kana-collisions')
            ->expectsOutputToContain('衝突はすべて内容が一致しています')
            ->assertSuccessful();
    }

    /**
     * 衝突する行が別の楽曲を指していれば判断が必要と報告すること
     */
    public function test_flags_collision_when_song_ids_differ(): void
    {
        $a = Song::factory()->create();
        $b = Song::factory()->create();
        $this->mapping('ｲｴｽﾀﾃﾞｲ', ['song_id' => $a->id]);
        $this->mapping('イエスタデイ', ['song_id' => $b->id]);

        $this->artisan('normalized-text:analyze-kana-collisions')
            ->expectsOutputToContain('内容が不一致の衝突が 1 グループあります')
            ->assertSuccessful();
    }

    /**
     * timestamp_decompositions の衝突も見ること
     */
    public function test_detects_collision_in_decompositions(): void
    {
        $this->decomposition('ｲｴｽﾀﾃﾞｲ', ['derived_title' => 'A']);
        $this->decomposition('イエスタデイ', ['derived_title' => 'B']);

        $this->artisan('normalized-text:analyze-kana-collisions')
            ->expectsOutputToContain('内容が不一致の衝突が 1 グループあります')
            ->assertSuccessful();
    }

    /**
     * 半角カナのts_itemsは正規化時に全角化されるため、
     * 分析コマンドでは「値が変わる」件数が0になること
     */
    public function test_counts_newly_linkable_ts_items(): void
    {
        $this->mapping(TextNormalizer::normalize('イエスタデイ'));

        $archive = Archive::factory()->create();
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'ｲｴｽﾀﾃﾞｲ',
            'is_display' => 1,
        ]);

        $this->artisan('normalized-text:analyze-kana-collisions')
            ->expectsOutputToContain('変換で新たにマッピングを引けるようになる ts_items: 0件')
            ->assertSuccessful();
    }

    /**
     * データを変更しないこと（読み取り専用）
     */
    public function test_does_not_change_data(): void
    {
        $mapping = $this->mapping('ｲｴｽﾀﾃﾞｲ');
        $decomposition = $this->decomposition('ｱﾝｺｰﾙ');

        $this->artisan('normalized-text:analyze-kana-collisions')->assertSuccessful();

        $this->assertSame('ｲｴｽﾀﾃﾞｲ', $mapping->fresh()->normalized_text);
        $this->assertSame('ｱﾝｺｰﾙ', $decomposition->fresh()->normalized_text);
    }

    /**
     * CSV に衝突の詳細を出力できること
     */
    public function test_exports_csv(): void
    {
        $a = Song::factory()->create();
        $b = Song::factory()->create();
        $this->mapping('ｲｴｽﾀﾃﾞｲ', ['song_id' => $a->id]);
        $this->mapping('イエスタデイ', ['song_id' => $b->id]);

        $path = storage_path('app/kana-collisions-test.csv');
        @unlink($path);

        $this->artisan("normalized-text:analyze-kana-collisions --csv={$path}")->assertSuccessful();

        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertStringContainsString('timestamp_song_mappings', $contents);
        $this->assertStringContainsString('different', $contents);

        @unlink($path);
    }
}

<?php

namespace Tests\Unit\Commands;

use App\Helpers\SupplementStripper;
use App\Models\TimestampDecomposition;
use App\Services\TimestampDecompositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CleanDecompositionSupplementsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SupplementStripper::flushKeywordCache();

        parent::tearDown();
    }

    /**
     * 実際の分解処理を通して1件作る
     */
    private function createDecomposition(string $text, array $attributes = []): TimestampDecomposition
    {
        $decomposed = app(TimestampDecompositionService::class)->decompose($text);

        return TimestampDecomposition::create(array_merge([
            'id' => (string) Str::ulid(),
            'normalized_text' => Str::random(20),
            'original_text' => $text,
            'parts' => $decomposed['parts'],
            'separator_count' => $decomposed['separator_count'],
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ], $attributes));
    }

    public function test_dry_run_does_not_change_data(): void
    {
        $decomposition = $this->createDecomposition('気まぐれロマンティック / いきものがかり (エコーかけ忘れ)');

        $this->artisan('ts-decompositions:clean-supplements')
            ->expectsOutputToContain('ドライラン')
            ->assertSuccessful();

        $decomposition->refresh();

        $this->assertEquals(
            ['気まぐれロマンティック', 'いきものがかり (エコーかけ忘れ)'],
            $decomposition->parts
        );
    }

    public function test_apply_cleans_parts(): void
    {
        $decomposition = $this->createDecomposition('気まぐれロマンティック / いきものがかり (エコーかけ忘れ)');

        $this->artisan('ts-decompositions:clean-supplements --apply')
            ->expectsOutputToContain('更新しました: 1件')
            ->assertSuccessful();

        $decomposition->refresh();

        $this->assertEquals(
            ['気まぐれロマンティック', 'いきものがかり'],
            $decomposition->parts
        );
    }

    /**
     * 括弧なしの補足（全角スペース区切り）もパーツ単位なら除去できること
     */
    public function test_apply_cleans_trailing_supplement_inside_part(): void
    {
        $decomposition = $this->createDecomposition('気まぐれロマンティック / いきものがかり 　エコーかけ忘れ');

        $this->artisan('ts-decompositions:clean-supplements --apply')->assertSuccessful();

        $decomposition->refresh();

        $this->assertEquals(
            ['気まぐれロマンティック', 'いきものがかり'],
            $decomposition->parts
        );
    }

    /**
     * 区切りが無いテキスト（パーツが1つ）は区切り以降ルールの対象にしないこと
     *
     * 「YOASOBI　アンコール」の後半が補足なのか曲名の一部なのか判別できないため、
     * 一括掃除で曲名を削ってしまわないようにする
     */
    public function test_apply_keeps_single_part_without_separator(): void
    {
        $decomposition = $this->createDecomposition('YOASOBI　アンコール');

        $this->artisan('ts-decompositions:clean-supplements --apply')->assertSuccessful();

        $decomposition->refresh();

        $this->assertEquals(['YOASOBI　アンコール'], $decomposition->parts);
    }

    /**
     * 掃除では updated_at / updated_by を書き換えないこと
     *
     * undoAction() が「同じ updated_by かつ updated_at が近いもの」を
     * カスケード操作のまとまりとみなすため、掃除で書き換えると
     * 無関係なレコードが巻き込まれて戻されてしまう
     */
    public function test_apply_does_not_touch_update_metadata(): void
    {
        $decomposition = $this->createDecomposition('気まぐれロマンティック / いきものがかり (エコーかけ忘れ)');

        $updatedAt = $decomposition->updated_at;

        $this->artisan('ts-decompositions:clean-supplements --apply')->assertSuccessful();

        $decomposition->refresh();

        $this->assertEquals(['気まぐれロマンティック', 'いきものがかり'], $decomposition->parts);
        $this->assertEquals($updatedAt->toDateTimeString(), $decomposition->updated_at->toDateTimeString());
    }

    public function test_apply_cleans_bracket_and_symbol_in_parts(): void
    {
        $paren = $this->createDecomposition('気まぐれロマンティック（アンコール） / いきものがかり');
        $symbol = $this->createDecomposition('♫気まぐれロマンティック / いきものがかり');

        $this->artisan('ts-decompositions:clean-supplements --apply')->assertSuccessful();

        $this->assertEquals(['気まぐれロマンティック', 'いきものがかり'], $paren->refresh()->parts);
        $this->assertEquals(['気まぐれロマンティック', 'いきものがかり'], $symbol->refresh()->parts);
    }

    /**
     * パーツ数を変えないこと（title_part_index / artist_part_index がズレないため）
     */
    public function test_part_count_is_preserved(): void
    {
        $decomposition = $this->createDecomposition('曲名 / アーティスト / (エコー)', [
            'title_part_index' => 0,
            'artist_part_index' => 1,
        ]);

        $before = count($decomposition->parts);

        $this->artisan('ts-decompositions:clean-supplements --apply')->assertSuccessful();

        $decomposition->refresh();

        $this->assertCount($before, $decomposition->parts);
        $this->assertEquals(0, $decomposition->title_part_index);
        $this->assertEquals(1, $decomposition->artist_part_index);
    }

    public function test_apply_cleans_derived_title_and_artist(): void
    {
        $decomposition = $this->createDecomposition('気まぐれロマンティック / いきものがかり (エコーかけ忘れ)', [
            'status' => TimestampDecomposition::STATUS_SELECTED,
            'title_part_index' => 0,
            'artist_part_index' => 1,
            'derived_title' => '気まぐれロマンティック',
            'derived_artist' => 'いきものがかり (エコーかけ忘れ)',
        ]);

        $this->artisan('ts-decompositions:clean-supplements --apply')->assertSuccessful();

        $decomposition->refresh();

        $this->assertEquals('気まぐれロマンティック', $decomposition->derived_title);
        $this->assertEquals('いきものがかり', $decomposition->derived_artist);
    }

    /**
     * 補足を含まないレコードは変化しないこと
     */
    public function test_clean_records_are_untouched(): void
    {
        $decomposition = $this->createDecomposition('Story (Digital Edition) / 平井大');

        $this->artisan('ts-decompositions:clean-supplements --apply')
            ->expectsOutputToContain('更新しました: 0件')
            ->assertSuccessful();

        $this->assertEquals(
            ['Story (Digital Edition)', '平井大'],
            $decomposition->refresh()->parts
        );
    }

    /**
     * クリーニングで既存のアーティスト表記に揃うものを報告すること
     */
    public function test_reports_artist_merges(): void
    {
        $this->createDecomposition('ブルーバード / いきものがかり', [
            'status' => TimestampDecomposition::STATUS_SELECTED,
            'derived_title' => 'ブルーバード',
            'derived_artist' => 'いきものがかり',
        ]);

        $this->createDecomposition('気まぐれロマンティック / いきものがかり (エコーかけ忘れ)', [
            'status' => TimestampDecomposition::STATUS_SELECTED,
            'derived_title' => '気まぐれロマンティック',
            'derived_artist' => 'いきものがかり (エコーかけ忘れ)',
        ]);

        $this->artisan('ts-decompositions:clean-supplements')
            ->expectsOutputToContain('既存のアーティスト表記に揃うもの')
            ->assertSuccessful();
    }

    public function test_status_filter(): void
    {
        $this->createDecomposition('曲名A / アーティスト (エコー)', [
            'status' => TimestampDecomposition::STATUS_SKIPPED,
        ]);
        $this->createDecomposition('曲名B / アーティスト (エコー)', [
            'status' => TimestampDecomposition::STATUS_PENDING,
        ]);

        $this->artisan('ts-decompositions:clean-supplements --status=pending')
            ->expectsOutputToContain('走査対象: 1件')
            ->assertSuccessful();
    }

    public function test_rules_can_be_limited(): void
    {
        $decomposition = $this->createDecomposition('♫曲名 / アーティスト (エコー)');

        $this->artisan('ts-decompositions:clean-supplements --apply --rules=symbol')->assertSuccessful();

        $this->assertEquals(['曲名', 'アーティスト (エコー)'], $decomposition->refresh()->parts);
    }

    public function test_invalid_rule_fails(): void
    {
        $this->artisan('ts-decompositions:clean-supplements --rules=bogus')
            ->expectsOutputToContain('不明なルール')
            ->assertFailed();
    }

    public function test_no_target_returns_success(): void
    {
        $this->artisan('ts-decompositions:clean-supplements')
            ->expectsOutputToContain('対象のTS分解レコードがありませんでした')
            ->assertSuccessful();
    }

    public function test_csv_export(): void
    {
        $this->createDecomposition('曲名 / アーティスト (エコーかけ忘れ)');

        $path = storage_path('app/'.Str::random(8).'.csv');

        $this->artisan('ts-decompositions:clean-supplements --csv='.$path)->assertSuccessful();

        $this->assertFileExists($path);

        $csv = file_get_contents($path);
        $this->assertStringContainsString('アーティスト (エコーかけ忘れ)', $csv);
        $this->assertStringContainsString('bracket:エコー', $csv);

        unlink($path);
    }

    /**
     * 辞書を差し替えれば挙動が変わること（調整可能であることの確認）
     */
    public function test_keywords_are_adjustable_via_config(): void
    {
        $decomposition = $this->createDecomposition('曲名 / アーティスト (謎ワード)');

        config()->set('supplement_strip.keywords', ['謎ワード']);
        SupplementStripper::flushKeywordCache();

        $this->artisan('ts-decompositions:clean-supplements --apply')->assertSuccessful();

        $this->assertEquals(['曲名', 'アーティスト'], $decomposition->refresh()->parts);
    }
}

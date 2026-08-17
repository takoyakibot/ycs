<?php

namespace Tests\Unit\Helpers;

use App\Helpers\SupplementStripper;
use App\Helpers\TextNormalizer;
use Tests\TestCase;

class SupplementStripperTest extends TestCase
{
    protected function tearDown(): void
    {
        SupplementStripper::flushKeywordCache();

        parent::tearDown();
    }

    /**
     * 正規化まで通したキーを返すヘルパ（実運用と同じ経路）
     */
    private function key(string $text): string
    {
        return TextNormalizer::normalize(SupplementStripper::strip($text));
    }

    /**
     * 補足の書き方が違う4パターンが同じキーに畳まれること
     */
    public function test_supplement_variants_collapse_into_one_key(): void
    {
        $expected = '気まぐれロマンティック / いきものがかり';

        $this->assertEquals($expected, $this->key('気まぐれロマンティック / いきものがかり (エコーかけ忘れ)'));
        $this->assertEquals($expected, $this->key('気まぐれロマンティック / いきものがかり 　エコーかけ忘れ'));
        $this->assertEquals($expected, $this->key('気まぐれロマンティック（アンコール） / いきものがかり '));
        $this->assertEquals($expected, $this->key('♫気まぐれロマンティック / いきものがかり'));
    }

    /**
     * 補足キーワードを含まない括弧は曲名の一部として残ること
     */
    public function test_keeps_brackets_without_supplement_keyword(): void
    {
        $this->assertEquals(
            'Story (Digital Edition) / 平井大',
            SupplementStripper::strip('Story (Digital Edition) / 平井大')
        );

        $this->assertEquals(
            'ロキ / みきとP (feat. 鏡音リン)',
            SupplementStripper::strip('ロキ / みきとP (feat. 鏡音リン)')
        );

        $this->assertEquals(
            '宿命 / Official髭男dism (Live)',
            SupplementStripper::strip('宿命 / Official髭男dism (Live)')
        );
    }

    /**
     * 括弧内にキーワードがあれば括弧ごと除去されること（全角・角括弧も同様）
     */
    public function test_removes_bracket_containing_keyword(): void
    {
        $this->assertEquals('曲名 / アーティスト', trim(SupplementStripper::strip('曲名 / アーティスト (エコーかけ忘れ)')));
        $this->assertEquals('曲名 / アーティスト', trim(SupplementStripper::strip('曲名 / アーティスト【アンコール】')));
        $this->assertEquals('曲名 / アーティスト', trim(SupplementStripper::strip('曲名 / アーティスト［音量注意］')));
    }

    /**
     * 区切りが無いテキストは trailing ルールを適用しないこと
     *
     * 「YOASOBI　アンコール」のように、曲名そのものが補足キーワードと
     * 同じ語であるケースを壊さないためのガード
     */
    public function test_trailing_rule_requires_structure_separator(): void
    {
        $this->assertEquals('YOASOBI　アンコール', SupplementStripper::strip('YOASOBI　アンコール'));
        $this->assertEquals('アンコール / YOASOBI', SupplementStripper::strip('アンコール / YOASOBI'));
    }

    /**
     * 連続する補足ブロックはまとめて除去されること
     */
    public function test_removes_consecutive_trailing_supplements(): void
    {
        $this->assertEquals(
            '曲名 / アーティスト',
            SupplementStripper::strip('曲名 / アーティスト　エコーかけ忘れ　音量注意')
        );
    }

    /**
     * 補足でないセグメントは巻き込まないこと
     */
    public function test_stops_at_non_supplement_segment(): void
    {
        $this->assertEquals(
            '曲名 / アーティスト　feat. ゲスト',
            SupplementStripper::strip('曲名 / アーティスト　feat. ゲスト　エコーかけ忘れ')
        );
    }

    /**
     * 先頭・末尾の装飾記号のみが除去され、曲名内部の記号は残ること
     */
    public function test_removes_decorative_symbols_at_edges_only(): void
    {
        $this->assertEquals('曲名 / アーティスト', SupplementStripper::strip('♪♫ 曲名 / アーティスト ★'));
        $this->assertEquals('曲名★の話 / アーティスト', SupplementStripper::strip('曲名★の話 / アーティスト'));
    }

    /**
     * TS分解のパーツは区切りを含まないため、区切りガード無しで補足を落とせること
     */
    public function test_strip_part_removes_trailing_supplement_without_separator(): void
    {
        // パーツ単体（区切り無し）
        $this->assertEquals('いきものがかり', SupplementStripper::stripPart('いきものがかり 　エコーかけ忘れ'));

        // 全体テキストとして渡した場合は区切りガードが効いて変化しない
        $this->assertEquals(
            'いきものがかり 　エコーかけ忘れ',
            SupplementStripper::strip('いきものがかり 　エコーかけ忘れ')
        );
    }

    /**
     * パーツでも括弧・記号ルールは同じように効くこと
     */
    public function test_strip_part_applies_bracket_and_symbol_rules(): void
    {
        $this->assertEquals('気まぐれロマンティック', SupplementStripper::stripPart('気まぐれロマンティック（アンコール）'));
        $this->assertEquals('気まぐれロマンティック', SupplementStripper::stripPart('♫気まぐれロマンティック'));
        $this->assertEquals('いきものがかり (Live)', SupplementStripper::stripPart('いきものがかり (Live)'));
    }

    /**
     * ルールを個別に指定できること
     */
    public function test_rules_can_be_applied_selectively(): void
    {
        $text = '♫曲名 / アーティスト (エコーかけ忘れ)';

        $this->assertEquals(
            '曲名 / アーティスト (エコーかけ忘れ)',
            SupplementStripper::strip($text, [SupplementStripper::RULE_SYMBOL])
        );

        $this->assertEquals(
            '♫曲名 / アーティスト',
            SupplementStripper::strip($text, [SupplementStripper::RULE_BRACKET])
        );
    }

    /**
     * 除去結果が空になる場合は元テキストを返すこと（安全側）
     */
    public function test_returns_original_when_everything_would_be_removed(): void
    {
        $this->assertEquals('(エコー)', SupplementStripper::strip('(エコー)'));
        $this->assertEquals('♪♪♪', SupplementStripper::strip('♪♪♪'));
    }

    /**
     * 空文字・null を安全に扱えること
     */
    public function test_handles_empty_input(): void
    {
        $this->assertEquals('', SupplementStripper::strip(null));
        $this->assertEquals('', SupplementStripper::strip(''));
        $this->assertEquals('', SupplementStripper::strip('   '));
    }

    /**
     * どのルール・キーワードが効いたかを返すこと
     */
    public function test_analyze_reports_hits(): void
    {
        $result = SupplementStripper::analyze('♫曲名 / アーティスト (エコーかけ忘れ)');

        $rules = array_column($result['hits'], 'rule');

        $this->assertContains(SupplementStripper::RULE_BRACKET, $rules);
        $this->assertContains(SupplementStripper::RULE_SYMBOL, $rules);
        $this->assertContains('エコー', array_column($result['hits'], 'keyword'));
    }

    /**
     * 辞書を差し替えられること（設定で調整可能であることの確認）
     */
    public function test_keywords_come_from_config(): void
    {
        config()->set('supplement_strip.keywords', ['独自ワード']);
        SupplementStripper::flushKeywordCache();

        $this->assertEquals('曲名 / アーティスト', trim(SupplementStripper::strip('曲名 / アーティスト (独自ワード)')));
        $this->assertEquals('曲名 / アーティスト (エコーかけ忘れ)', SupplementStripper::strip('曲名 / アーティスト (エコーかけ忘れ)'));
    }

    /**
     * ルール名の検証
     */
    public function test_validate_rules(): void
    {
        $result = SupplementStripper::validateRules(['symbol', 'bracket', 'unknown', '']);

        $this->assertEquals(['symbol', 'bracket'], $result['rules']);
        $this->assertEquals(['unknown'], $result['invalid']);
    }
}

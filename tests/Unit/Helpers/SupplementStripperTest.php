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
    public function test_strip_parts_removes_trailing_supplement_without_separator(): void
    {
        // 分解済みのパーツ（区切り無し）
        $this->assertEquals(
            ['気まぐれロマンティック', 'いきものがかり'],
            SupplementStripper::stripParts(['気まぐれロマンティック', 'いきものがかり 　エコーかけ忘れ'])
        );

        // 全体テキストとして渡した場合は区切りガードが効いて変化しない
        $this->assertEquals(
            'いきものがかり 　エコーかけ忘れ',
            SupplementStripper::strip('いきものがかり 　エコーかけ忘れ')
        );
    }

    /**
     * パーツでも括弧・記号ルールは同じように効くこと
     */
    public function test_strip_parts_applies_bracket_and_symbol_rules(): void
    {
        $this->assertEquals(
            ['気まぐれロマンティック', 'いきものがかり (Live)'],
            SupplementStripper::stripParts(['気まぐれロマンティック（アンコール）', 'いきものがかり (Live)'])
        );

        $this->assertEquals(
            ['気まぐれロマンティック', '平井大'],
            SupplementStripper::stripParts(['♫気まぐれロマンティック', '平井大'])
        );
    }

    /**
     * パーツが1つだけのときは区切り以降ルールを適用しないこと
     *
     * パーツが1つ = 元テキストが区切り文字で分解されていないので、
     * 「YOASOBI　アンコール」の後半が補足なのか曲名の一部なのか判別できない。
     * パーツ数を見ずに区切りガードを外すと曲名を壊してしまう。
     */
    public function test_strip_parts_keeps_single_part_without_separator(): void
    {
        $this->assertEquals(
            ['YOASOBI　アンコール'],
            SupplementStripper::stripParts(['YOASOBI　アンコール'])
        );

        $this->assertEquals(
            ['気まぐれロマンティック　アンコール'],
            SupplementStripper::stripParts(['気まぐれロマンティック　アンコール'])
        );
    }

    /**
     * パーツが1つだけでも括弧・記号ルールは効くこと
     */
    public function test_strip_parts_applies_other_rules_to_single_part(): void
    {
        $this->assertEquals(
            ['気まぐれロマンティック'],
            SupplementStripper::stripParts(['気まぐれロマンティック（アンコール）'])
        );

        $this->assertEquals(
            ['気まぐれロマンティック'],
            SupplementStripper::stripParts(['♫気まぐれロマンティック'])
        );
    }

    /**
     * パーツの並び・要素数を変えないこと（title/artist の index がズレるため）
     */
    public function test_strip_parts_preserves_length_and_order(): void
    {
        $parts = ['曲名', 'アーティスト　エコーかけ忘れ', '音量注意'];

        $cleaned = SupplementStripper::stripParts($parts);

        $this->assertCount(count($parts), $cleaned);
        $this->assertEquals(['曲名', 'アーティスト', '音量注意'], $cleaned);
    }

    /**
     * 異体字セレクタ付きの装飾記号も除去されること
     *
     * YouTubeのタイムスタンプでは ▶️ や ❤️ のように「基底文字 + U+FE0F」の
     * 2コードポイントで書かれることが多い。基底文字だけを見ていると、
     * 先頭ではセレクタが孤児として残り、末尾では除去が一切効かない。
     */
    public function test_symbol_rule_removes_variation_selector_forms(): void
    {
        $rules = [SupplementStripper::RULE_SYMBOL];

        // 先頭: セレクタが残らないこと
        $this->assertEquals('君の名は希望', SupplementStripper::strip("▶\u{FE0F}君の名は希望", $rules));
        $this->assertEquals('君の名は希望', SupplementStripper::strip("❤\u{FE0F}君の名は希望", $rules));
        $this->assertEquals('君の名は希望', SupplementStripper::strip("🎙\u{FE0F}君の名は希望", $rules));

        // 末尾: 除去が効くこと
        $this->assertEquals('君の名は希望', SupplementStripper::strip("君の名は希望❤\u{FE0F}", $rules));

        // セレクタ無しの形も従来どおり
        $this->assertEquals('君の名は希望', SupplementStripper::strip('❤君の名は希望', $rules));
    }

    /**
     * 辞書に無い絵文字から異体字セレクタだけを剥がさないこと
     *
     * 装飾記号の文字クラスに U+FE0F を直接足すと、辞書に無い絵文字
     * （🏳️ など）のセレクタだけが落ちて別の文字に化ける。
     */
    public function test_symbol_rule_keeps_variation_selector_of_unlisted_emoji(): void
    {
        $text = "テスト🏳\u{FE0F}";

        $this->assertEquals($text, SupplementStripper::strip($text, [SupplementStripper::RULE_SYMBOL]));
    }

    /**
     * 除去で生まれた連続スペースが1つに詰まること
     *
     * 括弧の除去はスペース1つへの置き換えなので、括弧が中間にあると
     * 2連スペースが残る。この値は画面にもDBにもそのまま流れる。
     */
    public function test_consecutive_spaces_are_collapsed_after_removal(): void
    {
        $this->assertEquals(
            '気まぐれロマンティック / いきものがかり',
            SupplementStripper::strip('気まぐれロマンティック（アンコール） / いきものがかり')
        );

        $this->assertEquals(
            ['曲名', '曲名 Ver'],
            SupplementStripper::stripParts(['曲名', '曲名（アンコール） Ver'])
        );
    }

    /**
     * 何も除去していないテキストの連続スペースは触らないこと
     *
     * 触ると、元から2連スペースがあるだけのレコードが
     * 一括掃除コマンドの変更対象に入ってしまう。
     */
    public function test_consecutive_spaces_are_kept_when_nothing_removed(): void
    {
        $text = '曲名  Ver / アーティスト';

        $this->assertEquals($text, SupplementStripper::strip($text));
    }

    /**
     * 括弧補足のあとに区切り以降ルールが効くこと
     *
     * 連続スペースの圧縮を区切り以降ルールより前に置くと、括弧の除去で
     * 生まれたギャップ境界が消えて後続の補足が取り残される。
     */
    public function test_trailing_rule_still_applies_after_bracket_removal(): void
    {
        $this->assertEquals(
            '曲名 / アーティスト',
            SupplementStripper::strip('曲名 / アーティスト（アンコール） 雑談')
        );
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

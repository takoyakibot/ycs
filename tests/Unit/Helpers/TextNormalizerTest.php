<?php

namespace Tests\Unit\Helpers;

use App\Helpers\TextNormalizer;
use PHPUnit\Framework\TestCase;

class TextNormalizerTest extends TestCase
{
    /**
     * チルダ系文字が半角チルダに統一されることをテスト
     */
    public function test_tilde_normalization(): void
    {
        // 半角チルダ
        $this->assertEquals('test~value', TextNormalizer::normalize('test~value'));

        // 全角チルダ（U+FF5E）
        $this->assertEquals('test~value', TextNormalizer::normalize('test～value'));

        // 波ダッシュ（U+301C）
        $this->assertEquals('test~value', TextNormalizer::normalize('test〜value'));

        // 複数のチルダが混在する場合
        $this->assertEquals('a~b~c~d', TextNormalizer::normalize('a~b～c〜d'));
    }

    /**
     * 全角半角変換のテスト
     */
    public function test_fullwidth_to_halfwidth(): void
    {
        $this->assertEquals('abc123', TextNormalizer::normalize('ＡＢＣ１２３'));
    }

    /**
     * 区切り文字の統一テスト
     */
    public function test_separator_normalization(): void
    {
        $this->assertEquals('artist/title', TextNormalizer::normalize('artist-title'));
        $this->assertEquals('artist/title', TextNormalizer::normalize('artist／title'));
        $this->assertEquals('artist/title', TextNormalizer::normalize('artist:title'));
    }

    /**
     * 空白文字の統一テスト
     */
    public function test_whitespace_normalization(): void
    {
        $this->assertEquals('hello world', TextNormalizer::normalize('hello　world'));
        $this->assertEquals('hello world', TextNormalizer::normalize('hello  world'));
    }

    /**
     * 小文字統一のテスト
     */
    public function test_lowercase_conversion(): void
    {
        $this->assertEquals('hello world', TextNormalizer::normalize('HELLO WORLD'));
        $this->assertEquals('hello world', TextNormalizer::normalize('Hello World'));
    }

    /**
     * 複合的な正規化のテスト
     */
    public function test_combined_normalization(): void
    {
        // 実際のタイムスタンプを想定した複合テスト
        // 長音記号は前後にスペースがない場合は区切り文字として扱わない
        $input = 'ＹＯＡＳＯＢＩー夜に駆ける～Live Ver.～';
        $expected = 'yoasobiー夜に駆ける~live ver.~';
        $this->assertEquals($expected, TextNormalizer::normalize($input));

        // 前後にスペースがある長音記号は区切り文字として扱う
        $input2 = 'ＹＯＡＳＯＢＩ ー 夜に駆ける～Live Ver.～';
        $expected2 = 'yoasobi / 夜に駆ける~live ver.~';
        $this->assertEquals($expected2, TextNormalizer::normalize($input2));
    }

    /**
     * equals メソッドのテスト
     */
    public function test_equals(): void
    {
        $this->assertTrue(TextNormalizer::equals('test~value', 'test～value'));
        $this->assertTrue(TextNormalizer::equals('test〜value', 'test～value'));
        $this->assertFalse(TextNormalizer::equals('test1', 'test2'));
    }

    /**
     * trimFullwidthSpace メソッドのテスト
     */
    public function test_trim_fullwidth_space(): void
    {
        $this->assertEquals('test', TextNormalizer::trimFullwidthSpace('　test'));
        $this->assertEquals('test', TextNormalizer::trimFullwidthSpace('  test'));
        $this->assertEquals('test　', TextNormalizer::trimFullwidthSpace('　test　'));
        $this->assertEquals('', TextNormalizer::trimFullwidthSpace(''));
        $this->assertEquals('', TextNormalizer::trimFullwidthSpace(null));
    }

    /**
     * extractSongInfo メソッドのテスト
     */
    public function test_extract_song_info(): void
    {
        // アーティスト / 楽曲名 パターン
        $result = TextNormalizer::extractSongInfo('YOASOBI / 夜に駆ける');
        $this->assertEquals('yoasobi', $result['artist']);
        $this->assertEquals('夜に駆ける', $result['title']);

        // 区切り文字なし
        $result = TextNormalizer::extractSongInfo('夜に駆ける');
        $this->assertEquals('夜に駆ける', $result['title']);
        $this->assertNull($result['artist']);

        // チルダを含むケース（チルダは区切り文字ではないので全体がtitleになる）
        $result = TextNormalizer::extractSongInfo('YOASOBI～夜に駆ける～Live Ver.～');
        $this->assertEquals('yoasobi~夜に駆ける~live ver.~', $result['title']);
        $this->assertNull($result['artist']);
    }

    /**
     * ゼロ幅スペースなどの不可視文字が除去されることをテスト
     */
    public function test_zero_width_character_removal(): void
    {
        // U+200B: Zero Width Space
        $this->assertEquals('testvalue', TextNormalizer::normalize("test\u{200B}value"));

        // U+200C: Zero Width Non-Joiner
        $this->assertEquals('testvalue', TextNormalizer::normalize("test\u{200C}value"));

        // U+200D: Zero Width Joiner
        $this->assertEquals('testvalue', TextNormalizer::normalize("test\u{200D}value"));

        // U+FEFF: BOM
        $this->assertEquals('testvalue', TextNormalizer::normalize("\u{FEFF}testvalue"));

        // 複数の不可視文字が混在
        $this->assertEquals('abc def', TextNormalizer::normalize("a\u{200B}bc\u{200C} \u{200D}def"));

        // 先頭にゼロ幅スペースがある実際のケース
        $this->assertEquals('天野由梨 魂の扉', TextNormalizer::normalize("\u{200B} 天野由梨 魂の扉"));
    }

    /**
     * trimFullwidthSpace で不可視文字が除去されることをテスト
     */
    public function test_trim_fullwidth_space_removes_zero_width_characters(): void
    {
        // 先頭のゼロ幅スペース
        $this->assertEquals('test', TextNormalizer::trimFullwidthSpace("\u{200B}test"));

        // 全角スペースとゼロ幅スペースの組み合わせ
        $this->assertEquals('test', TextNormalizer::trimFullwidthSpace("　\u{200B}test"));

        // 中間にあるゼロ幅スペース
        $this->assertEquals('test value', TextNormalizer::trimFullwidthSpace("\u{200B}test\u{200B} value"));

        // BOM
        $this->assertEquals('test', TextNormalizer::trimFullwidthSpace("\u{FEFF}test"));
    }

    /**
     * 追加のチルダ系文字が統一されることをテスト
     */
    public function test_additional_tilde_normalization(): void
    {
        // U+223C (TILDE OPERATOR)
        $this->assertEquals('a~b', TextNormalizer::normalize('a∼b'));

        // U+02DC (SMALL TILDE)
        $this->assertEquals('a~b', TextNormalizer::normalize('a˜b'));

        // 混在ケース
        $this->assertEquals('完璧ぐ~のね', TextNormalizer::normalize('完璧ぐ〜のね'));
        $this->assertEquals('完璧ぐ~のね', TextNormalizer::normalize('完璧ぐ~のね'));
        $this->assertEquals('完璧ぐ~のね', TextNormalizer::normalize('完璧ぐ～のね'));

        // equalsでの比較
        $this->assertTrue(TextNormalizer::equals('完璧ぐ〜のね', '完璧ぐ～のね'));
        $this->assertTrue(TextNormalizer::equals('完璧ぐ~のね', '完璧ぐ〜のね'));
    }

    /**
     * 引用符の統一テスト
     */
    public function test_quote_normalization(): void
    {
        // ダブルクォート系
        $this->assertEquals('"test"', TextNormalizer::normalize('"test"'));
        $this->assertEquals('"test"', TextNormalizer::normalize('″test″'));
        $this->assertEquals('"test"', TextNormalizer::normalize('〝test〞'));
        $this->assertEquals('"test"', TextNormalizer::normalize('＂test＂'));

        // シングルクォート系
        $this->assertEquals("'test'", TextNormalizer::normalize("'test'"));
        $this->assertEquals("'test'", TextNormalizer::normalize('′test′'));
        $this->assertEquals("'test'", TextNormalizer::normalize('`test´'));
        $this->assertEquals("'test'", TextNormalizer::normalize('＇test＇'));

        // 鉤括弧は変換されない（日本語独自の括弧として保持）
        $this->assertEquals('「test」', TextNormalizer::normalize('「test」'));
    }

    /**
     * カーリークォート（スマートクォート）の正規化テスト
     *
     * Unicode escape sequence を使用して、ソースコードの文字化けに依存しないテストを実現
     */
    public function test_curly_quote_normalization(): void
    {
        // LEFT SINGLE QUOTATION MARK (U+2018) → '
        $this->assertEquals("'test'", TextNormalizer::normalize("\u{2018}test\u{2018}"));

        // RIGHT SINGLE QUOTATION MARK (U+2019) → '
        $this->assertEquals("'test'", TextNormalizer::normalize("\u{2019}test\u{2019}"));

        // LEFT DOUBLE QUOTATION MARK (U+201C) → "
        $this->assertEquals('"test"', TextNormalizer::normalize("\u{201C}test\u{201C}"));

        // RIGHT DOUBLE QUOTATION MARK (U+201D) → "
        $this->assertEquals('"test"', TextNormalizer::normalize("\u{201D}test\u{201D}"));

        // SINGLE LOW-9 QUOTATION MARK (U+201A) → '
        $this->assertEquals("'test'", TextNormalizer::normalize("\u{201A}test\u{201A}"));

        // DOUBLE LOW-9 QUOTATION MARK (U+201E) → "
        $this->assertEquals('"test"', TextNormalizer::normalize("\u{201E}test\u{201E}"));

        // 実際のユースケース: "Don't say "lazy""のバリエーション
        $straight = "don't say \"lazy\"";
        $this->assertEquals($straight, TextNormalizer::normalize("Don\u{2019}t say \u{201C}lazy\u{201D}"));
        $this->assertEquals($straight, TextNormalizer::normalize("Don't say \"lazy\""));

        // 両方のバリエーションが同一の正規化結果になること
        $this->assertTrue(TextNormalizer::equals(
            "Don\u{2019}t say \u{201C}lazy\u{201D}",
            "Don't say \"lazy\""
        ));
    }

    /**
     * 括弧の統一テスト
     */
    public function test_bracket_normalization(): void
    {
        // 全角丸括弧
        $this->assertEquals('(test)', TextNormalizer::normalize('（test）'));

        // 角括弧系
        $this->assertEquals('[test]', TextNormalizer::normalize('［test］'));
        $this->assertEquals('[test]', TextNormalizer::normalize('【test】'));
        $this->assertEquals('[test]', TextNormalizer::normalize('〔test〕'));
        $this->assertEquals('[test]', TextNormalizer::normalize('〈test〉'));
        $this->assertEquals('[test]', TextNormalizer::normalize('《test》'));
    }

    /**
     * 中点の統一テスト
     */
    public function test_middle_dot_normalization(): void
    {
        $this->assertEquals('a・b', TextNormalizer::normalize('a•b'));
        $this->assertEquals('a・b', TextNormalizer::normalize('a·b'));
        $this->assertEquals('a・b', TextNormalizer::normalize('a‧b'));
        $this->assertEquals('a・b', TextNormalizer::normalize('a･b'));
    }

    /**
     * 乗算記号の統一テスト
     */
    public function test_multiplication_sign_normalization(): void
    {
        $this->assertEquals('axb', TextNormalizer::normalize('a×b'));
        $this->assertEquals('axb', TextNormalizer::normalize('a✕b'));
        $this->assertEquals('axb', TextNormalizer::normalize('aＸb'));
    }

    /**
     * getIgnoreKeywords メソッドのテスト
     */
    public function test_get_ignore_keywords(): void
    {
        $keywords = TextNormalizer::getIgnoreKeywords();

        // 配列が返されることを確認
        $this->assertIsArray($keywords);

        // 正規化されたキーワードが含まれていることを確認
        $this->assertContains('cover', $keywords);
        $this->assertContains('mv', $keywords);
        $this->assertContains('vtuber', $keywords);
        $this->assertContains('オリジナル', $keywords);

        // 複数のキーワードが含まれていることを確認
        $this->assertGreaterThanOrEqual(10, count($keywords));
    }

    /**
     * エッジケースのテスト
     */
    public function test_edge_cases(): void
    {
        // null入力
        $this->assertEquals('', TextNormalizer::normalize(null));

        // 空文字列
        $this->assertEquals('', TextNormalizer::normalize(''));

        // 連続するチルダ
        $this->assertEquals('a~~~b', TextNormalizer::normalize('a～〜~b'));

        // チルダのみ（チルダは区切り文字ではない）
        $this->assertEquals('artist~title~version', TextNormalizer::normalize('artist～title〜version'));

        // ハイフンのみ（区切り文字として認識され、trimで除去されるため空文字になる）
        $this->assertEquals('', TextNormalizer::normalize('-'));
        // 長音記号は前後にスペースがない場合は区切り文字として扱わない
        $this->assertEquals('ー', TextNormalizer::normalize('ー'));
        $this->assertEquals('', TextNormalizer::normalize('－'));
    }

    /**
     * sanitizeUtf8メソッドのテスト
     */
    public function test_sanitize_utf8(): void
    {
        // 正常なUTF-8は変更されない
        $this->assertEquals('こんにちは', TextNormalizer::sanitizeUtf8('こんにちは'));
        $this->assertEquals('Hello World', TextNormalizer::sanitizeUtf8('Hello World'));
        $this->assertEquals('テスト123', TextNormalizer::sanitizeUtf8('テスト123'));

        // 絵文字も正常に保持
        $this->assertEquals('Hello 👋', TextNormalizer::sanitizeUtf8('Hello 👋'));

        // null入力
        $this->assertEquals('', TextNormalizer::sanitizeUtf8(null));

        // 空文字列
        $this->assertEquals('', TextNormalizer::sanitizeUtf8(''));

        // 不正なUTF-8バイト列を含む文字列（途中で切れたマルチバイト）
        // 「切り抜き」の「き」(\xE3\x81\x8D)の最初の2バイトだけ
        $invalidUtf8 = "切り抜\xE3\x81";
        $sanitized = TextNormalizer::sanitizeUtf8($invalidUtf8);
        // 不正なバイトが除去されて正常なUTF-8になる
        $this->assertTrue(mb_check_encoding($sanitized, 'UTF-8'));
    }

    /**
     * sanitizeUtf8が不正なバイト列を適切に処理することをテスト
     */
    public function test_sanitize_utf8_removes_invalid_bytes(): void
    {
        // 不正なシングルバイト
        $this->assertTrue(mb_check_encoding(TextNormalizer::sanitizeUtf8("test\x80value"), 'UTF-8'));

        // 途中で切れた2バイト文字
        $this->assertTrue(mb_check_encoding(TextNormalizer::sanitizeUtf8("test\xC2value"), 'UTF-8'));

        // 途中で切れた3バイト文字
        $this->assertTrue(mb_check_encoding(TextNormalizer::sanitizeUtf8("test\xE3\x81value"), 'UTF-8'));

        // 途中で切れた4バイト文字
        $this->assertTrue(mb_check_encoding(TextNormalizer::sanitizeUtf8("test\xF0\x9Fvalue"), 'UTF-8'));
    }

    /**
     * 長音記号（ー）が区切り文字として誤検出されないことをテスト
     */
    public function test_prolonged_sound_mark_not_treated_as_separator(): void
    {
        // 長音記号を含む単語は区切り文字ありと判定されない
        $this->assertFalse(TextNormalizer::hasSeparators('コーヒー'));
        $this->assertFalse(TextNormalizer::hasSeparators('ゲーム'));
        $this->assertFalse(TextNormalizer::hasSeparators('アーティスト'));
        $this->assertFalse(TextNormalizer::hasSeparators('ラーメン'));

        // 長音記号を含む単語は分割されない
        $result = TextNormalizer::splitBySeparators('コーヒー');
        $this->assertEquals(['コーヒー'], $result['parts']);
        $this->assertEquals(0, $result['separator_count']);
        $this->assertFalse($result['has_separators']);

        // 実際の区切り文字（/）がある場合は検出される
        $this->assertTrue(TextNormalizer::hasSeparators('アーティスト / 曲名'));
        $result = TextNormalizer::splitBySeparators('アーティスト / 曲名');
        $this->assertEquals(['アーティスト', '曲名'], $result['parts']);
        $this->assertEquals(1, $result['separator_count']);
        $this->assertTrue($result['has_separators']);

        // ハイフン（-）は区切り文字として検出される
        $this->assertTrue(TextNormalizer::hasSeparators('アーティスト - 曲名'));
        $result = TextNormalizer::splitBySeparators('アーティスト - 曲名');
        $this->assertEquals(['アーティスト', '曲名'], $result['parts']);

        // 長音記号と実際の区切り文字が混在するケース
        $this->assertTrue(TextNormalizer::hasSeparators('コーヒー / カフェラテ'));
        $result = TextNormalizer::splitBySeparators('コーヒー / カフェラテ');
        $this->assertEquals(['コーヒー', 'カフェラテ'], $result['parts']);
    }
}

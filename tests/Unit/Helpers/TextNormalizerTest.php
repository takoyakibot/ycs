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

        // U+200D: Zero Width Joiner（絵文字シーケンスで使用されるため保持）
        $this->assertEquals("test\u{200D}value", TextNormalizer::normalize("test\u{200D}value"));

        // U+FEFF: BOM
        $this->assertEquals('testvalue', TextNormalizer::normalize("\u{FEFF}testvalue"));

        // 複数の不可視文字が混在（U+200Dは保持される）
        $this->assertEquals("abc \u{200D}def", TextNormalizer::normalize("a\u{200B}bc\u{200C} \u{200D}def"));

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
     * isIgnorablePart: 無視キーワードと記号（と数字）だけのパーツが無視対象になることをテスト
     */
    public function test_is_ignorable_part_detects_noise_parts(): void
    {
        $this->assertTrue(TextNormalizer::isIgnorablePart('cover'));
        $this->assertTrue(TextNormalizer::isIgnorablePart('COVER'));
        $this->assertTrue(TextNormalizer::isIgnorablePart('【cover】'));
        $this->assertTrue(TextNormalizer::isIgnorablePart('Official MV'));
        $this->assertTrue(TextNormalizer::isIgnorablePart('ＭＶ'));
        $this->assertTrue(TextNormalizer::isIgnorablePart('歌ってみた'));
        $this->assertTrue(TextNormalizer::isIgnorablePart('shorts'));
        $this->assertTrue(TextNormalizer::isIgnorablePart('カバー'));

        // 「キーワード＋別の語」の形のノイズ表記も無視対象にする
        $this->assertTrue(TextNormalizer::isIgnorablePart('Official Video'));
        $this->assertTrue(TextNormalizer::isIgnorablePart('(Full ver.)'));
        $this->assertTrue(TextNormalizer::isIgnorablePart('Short ver.'));

        // キーワードに連番を添えただけの表記ゆれ（数字の残留は許容する）
        $this->assertTrue(TextNormalizer::isIgnorablePart('cover2'));
        $this->assertTrue(TextNormalizer::isIgnorablePart('ver.2'));
        $this->assertTrue(TextNormalizer::isIgnorablePart('MV2'));
    }

    /**
     * isIgnorablePart: キーワードを語の一部に含むアーティスト名が無視対象にならないことをテスト
     *
     * "Official髭男dism" が無視対象になると、アーティスト名が空の楽曲マスタが作られてしまう
     */
    public function test_is_ignorable_part_keeps_names_containing_keywords(): void
    {
        $this->assertFalse(TextNormalizer::isIgnorablePart('Official髭男dism'));
        $this->assertFalse(TextNormalizer::isIgnorablePart('OFFICIAL HIGE DANDISM'));
        $this->assertFalse(TextNormalizer::isIgnorablePart('ORANGE RANGE'));
        $this->assertFalse(TextNormalizer::isIgnorablePart('オリジナル曲'));
        $this->assertFalse(TextNormalizer::isIgnorablePart('Covers'));

        // 短いキーワード（ver）を語の一部に含む名前も残ること
        $this->assertFalse(TextNormalizer::isIgnorablePart('Silver'));
        $this->assertFalse(TextNormalizer::isIgnorablePart('Forever'));
        $this->assertFalse(TextNormalizer::isIgnorablePart('Over The Rainbow'));

        // 数字の残留は許容するが、文字が残るなら候補として残すこと。
        // 数字入りのアーティスト名・曲名を捨ててしまわないための境界
        $this->assertFalse(TextNormalizer::isIgnorablePart('AKB48'));
        $this->assertFalse(TextNormalizer::isIgnorablePart('乃木坂46'));
        $this->assertFalse(TextNormalizer::isIgnorablePart('175R'));
        $this->assertFalse(TextNormalizer::isIgnorablePart('2 covers'));
        $this->assertFalse(TextNormalizer::isIgnorablePart('MV 4K'));
        $this->assertFalse(TextNormalizer::isIgnorablePart('original 1st'));

        // 単体で曲名になりうる語は辞書に入れていないこと。
        // 入れると候補が1つに減って自動確定し、アーティストが空の楽曲マスタが作られる
        $this->assertFalse(TextNormalizer::isIgnorablePart('Soundtrack'));
        $this->assertFalse(TextNormalizer::isIgnorablePart('ORIGINAL SOUNDTRACK'));

        // キーワードを含まないパーツ（記号・絵文字のみ）も無視対象にしない
        $this->assertFalse(TextNormalizer::isIgnorablePart('🎵'));
        $this->assertFalse(TextNormalizer::isIgnorablePart('★'));
    }

    /**
     * detectTitleArtistPattern: アーティスト名が無視されて自動確定されないことをテスト
     */
    public function test_detect_title_artist_pattern_does_not_drop_artist(): void
    {
        $detection = TextNormalizer::detectTitleArtistPattern(['ミックスナッツ', 'Official髭男dism']);

        // アーティスト側が無視対象にならないこと
        $this->assertSame([], $detection['ignore_indices']);
        $this->assertNotNull($detection['artist_index']);

        // 2候補の判定は確信度が低いため、自動確定されない（手動選別に回る）
        $this->assertLessThan(0.8, $detection['confidence']);
    }

    /**
     * detectTitleArtistPattern: ノイズパーツは従来どおり無視されることをテスト
     */
    public function test_detect_title_artist_pattern_ignores_noise_parts(): void
    {
        $detection = TextNormalizer::detectTitleArtistPattern(['曲名', 'cover']);

        $this->assertSame([1], $detection['ignore_indices']);
        $this->assertSame(0, $detection['title_index']);
        $this->assertNull($detection['artist_index']);
    }

    /**
     * 文字列 "0" が正しく正規化されることをテスト
     */
    public function test_normalize_string_zero(): void
    {
        $this->assertEquals('0', TextNormalizer::normalize('0'));
    }

    /**
     * null入力が空文字を返すことをテスト
     */
    public function test_normalize_null_returns_empty(): void
    {
        $this->assertEquals('', TextNormalizer::normalize(null));
    }

    /**
     * 空文字列が空文字を返すことをテスト
     */
    public function test_normalize_empty_string_returns_empty(): void
    {
        $this->assertEquals('', TextNormalizer::normalize(''));
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

    /**
     * normalizeメソッドが絵文字を保持することをテスト
     */
    public function test_normalize_preserves_emoji(): void
    {
        // 絵文字のみ
        $this->assertEquals('🎵', TextNormalizer::normalize('🎵'));

        // 絵文字を含むタイムスタンプテキスト
        $this->assertEquals('🎵 アーティスト / 曲名 🎶', TextNormalizer::normalize('🎵 アーティスト / 曲名 🎶'));

        // 複合絵文字（肌色修飾子付き等）
        $this->assertEquals('hello 👋🏻 world', TextNormalizer::normalize('Hello 👋🏻 World'));

        // 各種絵文字が混在
        $this->assertEquals('🔥 fire 🔥', TextNormalizer::normalize('🔥 FIRE 🔥'));

        // ZWJ結合絵文字（🏳️‍🌈 = 🏳 + VS16 + ZWJ + 🌈）
        $zwjEmoji = "\u{1F3F3}\u{FE0F}\u{200D}\u{1F308}";
        $this->assertEquals($zwjEmoji, TextNormalizer::normalize($zwjEmoji));
    }

    /**
     * trimFullwidthSpaceが絵文字を保持することをテスト
     */
    public function test_trim_fullwidth_space_preserves_emoji(): void
    {
        $this->assertEquals('🎵 テスト', TextNormalizer::trimFullwidthSpace('　🎵 テスト'));
        $this->assertEquals('🎶 曲名', TextNormalizer::trimFullwidthSpace('🎶 曲名'));
    }

    public function test_split_for_chips_splits_by_brackets_and_spaces(): void
    {
        $result = TextNormalizer::splitForChips(
            '【生歌ワンコーラス】2022年秋アニメ『恋愛フロップス』オープニング / 鈴木このみさん「Love? Reason why!!」'
        );
        $this->assertEquals([
            '生歌ワンコーラス',
            '2022年秋アニメ',
            '恋愛フロップス',
            'オープニング',
            '鈴木このみ',
            'Love?',
            'Reason',
            'why!!',
        ], $result);
    }

    public function test_split_for_chips_handles_mixed_brackets(): void
    {
        $result = TextNormalizer::splitForChips('(cover) アーティスト / 曲名');
        $this->assertEquals(['cover', 'アーティスト', '曲名'], $result);
    }

    public function test_split_for_chips_handles_fullwidth_brackets(): void
    {
        $result = TextNormalizer::splitForChips('（歌枠）曲名　アーティスト');
        $this->assertEquals(['歌枠', '曲名', 'アーティスト'], $result);
    }

    public function test_split_for_chips_returns_empty_for_null_and_empty(): void
    {
        $this->assertEquals([], TextNormalizer::splitForChips(null));
        $this->assertEquals([], TextNormalizer::splitForChips(''));
    }

    public function test_split_for_chips_does_not_break_long_dash(): void
    {
        $result = TextNormalizer::splitForChips('コーヒー / カフェラテ');
        $this->assertEquals(['コーヒー', 'カフェラテ'], $result);
    }

    public function test_split_for_chips_existing_separators_still_work(): void
    {
        $result = TextNormalizer::splitForChips('アーティスト | 曲名:サブタイトル');
        $this->assertEquals(['アーティスト', '曲名', 'サブタイトル'], $result);
    }

    public function test_split_for_chips_strips_honorifics(): void
    {
        $this->assertEquals(['Aimer'], TextNormalizer::splitForChips('Aimerさん'));
        $this->assertEquals(['YOASOBI'], TextNormalizer::splitForChips('YOASOBIくん'));
        $this->assertEquals(['Ado'], TextNormalizer::splitForChips('Adoちゃん'));
        $this->assertEquals(['米津玄師'], TextNormalizer::splitForChips('米津玄師様'));
        $this->assertEquals(['米津玄師'], TextNormalizer::splitForChips('米津玄師さま'));
        $this->assertEquals(['Aimer'], TextNormalizer::splitForChips('Aimer先生'));
    }

    public function test_split_for_chips_keeps_honorific_only_chip(): void
    {
        $this->assertEquals(['さん'], TextNormalizer::splitForChips('さん'));
        $this->assertEquals(['くん'], TextNormalizer::splitForChips('くん'));
    }

    public function test_split_by_separators_unchanged_for_brackets(): void
    {
        $result = TextNormalizer::splitBySeparators('【生歌】曲名');
        $this->assertEquals(['【生歌】曲名'], $result['parts']);
        $this->assertEquals(0, $result['separator_count']);
    }

    public function test_strip_decorations_removes_music_notes(): void
    {
        $this->assertEquals('アイドル', TextNormalizer::stripDecorations('♪ アイドル ♫'));
    }

    public function test_strip_decorations_removes_stars_and_sparkles(): void
    {
        $this->assertEquals('アイドル', TextNormalizer::stripDecorations('✦ アイドル ✨'));
        $this->assertEquals('YOASOBI', TextNormalizer::stripDecorations('⭐YOASOBI🌟'));
    }

    public function test_strip_decorations_removes_hearts(): void
    {
        $this->assertEquals('曲名', TextNormalizer::stripDecorations('❤ 曲名 💜'));
    }

    public function test_strip_decorations_preserves_normal_text(): void
    {
        $this->assertEquals('アイドル / YOASOBI', TextNormalizer::stripDecorations('アイドル / YOASOBI'));
    }

    public function test_strip_decorations_handles_null_and_empty(): void
    {
        $this->assertEquals('', TextNormalizer::stripDecorations(null));
        $this->assertEquals('', TextNormalizer::stripDecorations(''));
    }

    public function test_strip_decorations_collapses_spaces(): void
    {
        $this->assertEquals('A B', TextNormalizer::stripDecorations('A ✦ B'));
    }
}

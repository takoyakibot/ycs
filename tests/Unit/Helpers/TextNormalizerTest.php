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
        $input = 'ＹＯＡＳＯＢＩー夜に駆ける～Live Ver.～';
        $expected = 'yoasobi/夜に駆ける~live ver.~';
        $this->assertEquals($expected, TextNormalizer::normalize($input));
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
    }
}

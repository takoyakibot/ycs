<?php

namespace Tests\Unit\Helpers;

use App\Helpers\QueryHelper;
use PHPUnit\Framework\TestCase;

class QueryHelperTest extends TestCase
{
    /**
     * 特殊文字のエスケープテスト
     */
    public function test_escape_like_string(): void
    {
        // パーセント記号
        $this->assertEquals('\%', QueryHelper::escapeLikeString('%'));

        // アンダースコア
        $this->assertEquals('\_', QueryHelper::escapeLikeString('_'));

        // バックスラッシュ
        $this->assertEquals('\\\\', QueryHelper::escapeLikeString('\\'));

        // 複合ケース
        $this->assertEquals('test\%value\_with\\\\slash', QueryHelper::escapeLikeString('test%value_with\\slash'));
    }

    /**
     * 通常の文字列はそのまま返すことのテスト
     */
    public function test_normal_string_unchanged(): void
    {
        $this->assertEquals('hello world', QueryHelper::escapeLikeString('hello world'));
        $this->assertEquals('日本語テスト', QueryHelper::escapeLikeString('日本語テスト'));
        $this->assertEquals('test123', QueryHelper::escapeLikeString('test123'));
    }

    /**
     * 空文字のテスト
     */
    public function test_empty_string(): void
    {
        $this->assertEquals('', QueryHelper::escapeLikeString(''));
    }

    /**
     * スペース区切りでキーワード分割のテスト
     */
    public function test_split_search_keywords(): void
    {
        // 半角スペース区切り
        $this->assertEquals(['hello', 'world'], QueryHelper::splitSearchKeywords('hello world'));

        // 全角スペース区切り
        $this->assertEquals(['hello', 'world'], QueryHelper::splitSearchKeywords('hello　world'));

        // 複数スペース
        $this->assertEquals(['a', 'b', 'c'], QueryHelper::splitSearchKeywords('a  b   c'));

        // 前後のスペースは無視
        $this->assertEquals(['test'], QueryHelper::splitSearchKeywords('  test  '));

        // 混合スペース
        $this->assertEquals(['日本語', 'テスト'], QueryHelper::splitSearchKeywords('日本語　テスト'));
    }

    /**
     * 空文字・スペースのみの場合は空配列を返す
     */
    public function test_split_search_keywords_empty(): void
    {
        $this->assertEquals([], QueryHelper::splitSearchKeywords(''));
        $this->assertEquals([], QueryHelper::splitSearchKeywords('   '));
        $this->assertEquals([], QueryHelper::splitSearchKeywords('　　'));
    }

    /**
     * 単一キーワードの場合
     */
    public function test_split_search_keywords_single(): void
    {
        $this->assertEquals(['keyword'], QueryHelper::splitSearchKeywords('keyword'));
        $this->assertEquals(['キーワード'], QueryHelper::splitSearchKeywords('キーワード'));
    }

    /**
     * あいまい検索: 区切り文字で分割される
     */
    public function test_split_fuzzy_keywords_splits_by_separators(): void
    {
        // スラッシュ
        $this->assertEquals(['ロキ', 'みきとp'], QueryHelper::splitFuzzyKeywords('ロキ / みきとP'));

        // 全角スラッシュ・全角英字（正規化される）
        $this->assertEquals(['ロキ', 'みきとp'], QueryHelper::splitFuzzyKeywords('ロキ／みきとＰ'));

        // ハイフン
        $this->assertEquals(['lemon', '米津玄師'], QueryHelper::splitFuzzyKeywords('Lemon - 米津玄師'));

        // コロン
        $this->assertEquals(['曲名', 'アーティスト'], QueryHelper::splitFuzzyKeywords('曲名 : アーティスト'));

        // 区切り文字前後にスペースがない場合
        $this->assertEquals(['a', 'b'], QueryHelper::splitFuzzyKeywords('A/B'));
    }

    /**
     * あいまい検索: 括弧・記号・絵文字はノイズとして除去される
     */
    public function test_split_fuzzy_keywords_removes_symbols(): void
    {
        $this->assertEquals(['曲名', 'アーティスト'], QueryHelper::splitFuzzyKeywords('曲名（アーティスト）'));
        $this->assertEquals(['ロキ', 'みきとp'], QueryHelper::splitFuzzyKeywords('🎵 ロキ / みきとP 🎤'));
        $this->assertEquals(['夜に駆ける', 'yoasobi'], QueryHelper::splitFuzzyKeywords('1. 夜に駆ける / YOASOBI'));
    }

    /**
     * あいまい検索: 先頭の曲番号は除去される
     */
    public function test_split_fuzzy_keywords_removes_leading_track_number(): void
    {
        $this->assertEquals(['ロキ', 'みきとp'], QueryHelper::splitFuzzyKeywords('01. ロキ / みきとP'));
        $this->assertEquals(['ロキ', 'みきとp'], QueryHelper::splitFuzzyKeywords('①ロキ / みきとP'));

        // 曲番号以外にキーワードがない場合は除去しない
        $this->assertEquals(['45510'], QueryHelper::splitFuzzyKeywords('45510'));

        // 先頭以外の数字は除去しない
        $this->assertEquals(['テスト', '2000', 'artist'], QueryHelper::splitFuzzyKeywords('テスト 2000 / Artist'));
    }

    /**
     * あいまい検索: 長音記号・繰り返し記号は保持される
     */
    public function test_split_fuzzy_keywords_keeps_japanese_word_characters(): void
    {
        $this->assertEquals(['コーヒー', '人々'], QueryHelper::splitFuzzyKeywords('コーヒー / 人々'));
    }

    /**
     * あいまい検索: ノイズワード（cover等）は除去される
     */
    public function test_split_fuzzy_keywords_removes_noise_words(): void
    {
        $this->assertEquals(
            ['夜に駆ける', 'yoasobi'],
            QueryHelper::splitFuzzyKeywords('夜に駆ける / YOASOBI (cover)')
        );

        // 全てノイズワードの場合は除去しない（検索条件がなくなるのを防ぐ）
        $this->assertEquals(['cover'], QueryHelper::splitFuzzyKeywords('cover'));
    }

    /**
     * あいまい検索: 重複キーワードは1つにまとめられる
     */
    public function test_split_fuzzy_keywords_deduplicates(): void
    {
        $this->assertEquals(['song'], QueryHelper::splitFuzzyKeywords('Song / SONG'));
    }

    /**
     * あいまい検索: 空文字・記号のみの場合は空配列を返す
     */
    public function test_split_fuzzy_keywords_empty(): void
    {
        $this->assertEquals([], QueryHelper::splitFuzzyKeywords(''));
        $this->assertEquals([], QueryHelper::splitFuzzyKeywords('   '));
        $this->assertEquals([], QueryHelper::splitFuzzyKeywords('/ - /'));
    }
}

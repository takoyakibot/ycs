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
}

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
}

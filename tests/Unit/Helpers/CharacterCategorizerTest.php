<?php

namespace Tests\Unit\Helpers;

use App\Helpers\CharacterCategorizer;
use PHPUnit\Framework\TestCase;

class CharacterCategorizerTest extends TestCase
{
    /**
     * アルファベットのカテゴリ分類テスト
     */
    public function test_alphabet_categories(): void
    {
        // ABCDE
        $this->assertEquals('ABCDE', CharacterCategorizer::getCategory('Apple'));
        $this->assertEquals('ABCDE', CharacterCategorizer::getCategory('banana'));
        $this->assertEquals('ABCDE', CharacterCategorizer::getCategory('cherry'));
        $this->assertEquals('ABCDE', CharacterCategorizer::getCategory('dog'));
        $this->assertEquals('ABCDE', CharacterCategorizer::getCategory('elephant'));

        // FGHIJ
        $this->assertEquals('FGHIJ', CharacterCategorizer::getCategory('Fish'));
        $this->assertEquals('FGHIJ', CharacterCategorizer::getCategory('grape'));
        $this->assertEquals('FGHIJ', CharacterCategorizer::getCategory('house'));
        $this->assertEquals('FGHIJ', CharacterCategorizer::getCategory('ice'));
        $this->assertEquals('FGHIJ', CharacterCategorizer::getCategory('Japan'));

        // KLMNO
        $this->assertEquals('KLMNO', CharacterCategorizer::getCategory('kiwi'));
        $this->assertEquals('KLMNO', CharacterCategorizer::getCategory('lemon'));
        $this->assertEquals('KLMNO', CharacterCategorizer::getCategory('mango'));
        $this->assertEquals('KLMNO', CharacterCategorizer::getCategory('night'));
        $this->assertEquals('KLMNO', CharacterCategorizer::getCategory('orange'));

        // PQRST
        $this->assertEquals('PQRST', CharacterCategorizer::getCategory('pear'));
        $this->assertEquals('PQRST', CharacterCategorizer::getCategory('queen'));
        $this->assertEquals('PQRST', CharacterCategorizer::getCategory('rice'));
        $this->assertEquals('PQRST', CharacterCategorizer::getCategory('sugar'));
        $this->assertEquals('PQRST', CharacterCategorizer::getCategory('tomato'));

        // UVWXYZ
        $this->assertEquals('UVWXYZ', CharacterCategorizer::getCategory('umbrella'));
        $this->assertEquals('UVWXYZ', CharacterCategorizer::getCategory('violet'));
        $this->assertEquals('UVWXYZ', CharacterCategorizer::getCategory('water'));
        $this->assertEquals('UVWXYZ', CharacterCategorizer::getCategory('xenon'));
        $this->assertEquals('UVWXYZ', CharacterCategorizer::getCategory('yellow'));
        $this->assertEquals('UVWXYZ', CharacterCategorizer::getCategory('zebra'));
    }

    /**
     * ひらがなのカテゴリ分類テスト
     */
    public function test_hiragana_categories(): void
    {
        $this->assertEquals('あ', CharacterCategorizer::getCategory('あいうえお'));
        $this->assertEquals('あ', CharacterCategorizer::getCategory('いちご'));
        $this->assertEquals('か', CharacterCategorizer::getCategory('かきくけこ'));
        $this->assertEquals('か', CharacterCategorizer::getCategory('がっこう'));
        $this->assertEquals('さ', CharacterCategorizer::getCategory('さくら'));
        $this->assertEquals('さ', CharacterCategorizer::getCategory('ざっくり'));
        $this->assertEquals('た', CharacterCategorizer::getCategory('たいよう'));
        $this->assertEquals('た', CharacterCategorizer::getCategory('だれか'));
        $this->assertEquals('な', CharacterCategorizer::getCategory('なつやすみ'));
        $this->assertEquals('は', CharacterCategorizer::getCategory('はなび'));
        $this->assertEquals('は', CharacterCategorizer::getCategory('ばいきん'));
        $this->assertEquals('は', CharacterCategorizer::getCategory('ぱんだ'));
        $this->assertEquals('ま', CharacterCategorizer::getCategory('まつり'));
        $this->assertEquals('や', CharacterCategorizer::getCategory('やまと'));
        $this->assertEquals('ら', CharacterCategorizer::getCategory('らーめん'));
        $this->assertEquals('わ', CharacterCategorizer::getCategory('わたし'));
        $this->assertEquals('わ', CharacterCategorizer::getCategory('をかし'));
        $this->assertEquals('わ', CharacterCategorizer::getCategory('んー'));
    }

    /**
     * カタカナのカテゴリ分類テスト
     */
    public function test_katakana_categories(): void
    {
        $this->assertEquals('あ', CharacterCategorizer::getCategory('アイウエオ'));
        $this->assertEquals('か', CharacterCategorizer::getCategory('カキクケコ'));
        $this->assertEquals('か', CharacterCategorizer::getCategory('ガギグゲゴ'));
        $this->assertEquals('さ', CharacterCategorizer::getCategory('サクラ'));
        $this->assertEquals('た', CharacterCategorizer::getCategory('タイヨウ'));
        $this->assertEquals('な', CharacterCategorizer::getCategory('ナツヤスミ'));
        $this->assertEquals('は', CharacterCategorizer::getCategory('ハナビ'));
        $this->assertEquals('は', CharacterCategorizer::getCategory('パンダ'));
        $this->assertEquals('ま', CharacterCategorizer::getCategory('マツリ'));
        $this->assertEquals('や', CharacterCategorizer::getCategory('ヤマト'));
        $this->assertEquals('ら', CharacterCategorizer::getCategory('ラーメン'));
        $this->assertEquals('わ', CharacterCategorizer::getCategory('ワタシ'));
    }

    /**
     * 数字のカテゴリ分類テスト
     */
    public function test_number_categories(): void
    {
        $this->assertEquals('0-9', CharacterCategorizer::getCategory('0から始まる'));
        $this->assertEquals('0-9', CharacterCategorizer::getCategory('123番目'));
        $this->assertEquals('0-9', CharacterCategorizer::getCategory('9nine'));
    }

    /**
     * その他の文字のカテゴリ分類テスト
     */
    public function test_other_categories(): void
    {
        $this->assertEquals('その他', CharacterCategorizer::getCategory('♪音楽'));
        $this->assertEquals('その他', CharacterCategorizer::getCategory('★スター'));
        $this->assertEquals('その他', CharacterCategorizer::getCategory('漢字始まり'));
    }

    /**
     * エッジケースのテスト
     */
    public function test_edge_cases(): void
    {
        // null
        $this->assertNull(CharacterCategorizer::getCategory(null));

        // 空文字
        $this->assertNull(CharacterCategorizer::getCategory(''));
    }

    /**
     * getAllCategories メソッドのテスト
     */
    public function test_get_all_categories(): void
    {
        $categories = CharacterCategorizer::getAllCategories();

        $this->assertIsArray($categories);
        $this->assertContains('ABCDE', $categories);
        $this->assertContains('FGHIJ', $categories);
        $this->assertContains('KLMNO', $categories);
        $this->assertContains('PQRST', $categories);
        $this->assertContains('UVWXYZ', $categories);
        $this->assertContains('0-9', $categories);
        $this->assertContains('あ', $categories);
        $this->assertContains('か', $categories);
        $this->assertContains('さ', $categories);
        $this->assertContains('た', $categories);
        $this->assertContains('な', $categories);
        $this->assertContains('は', $categories);
        $this->assertContains('ま', $categories);
        $this->assertContains('や', $categories);
        $this->assertContains('ら', $categories);
        $this->assertContains('わ', $categories);
        $this->assertContains('その他', $categories);
    }
}

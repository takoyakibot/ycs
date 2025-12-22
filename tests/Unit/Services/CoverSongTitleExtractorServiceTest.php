<?php

namespace Tests\Unit\Services;

use App\Services\CoverSongTitleExtractorService;
use PHPUnit\Framework\TestCase;

class CoverSongTitleExtractorServiceTest extends TestCase
{
    protected CoverSongTitleExtractorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CoverSongTitleExtractorService;
    }

    public function test_removes_bracketed_keywords(): void
    {
        // 【歌ってみた】を除去
        $result = $this->service->extractWithExcludedWords('【歌ってみた】アイドル', []);
        $this->assertEquals('アイドル', $result);

        // 【MV】を除去
        $result = $this->service->extractWithExcludedWords('【MV】夜に駆ける', []);
        $this->assertEquals('夜に駆ける', $result);

        // 複数のカッコを除去
        $result = $this->service->extractWithExcludedWords('【歌ってみた】【MV】Lemon', []);
        $this->assertEquals('Lemon', $result);
    }

    public function test_keeps_non_keyword_brackets(): void
    {
        // 楽曲名の一部であるカッコは残す
        $result = $this->service->extractWithExcludedWords('【歌ってみた】群青（YOASOBI）', []);
        $this->assertEquals('群青（YOASOBI）', $result);
    }

    public function test_removes_covered_by_pattern(): void
    {
        $result = $this->service->extractWithExcludedWords('アイドル covered by 眠り姫', []);
        $this->assertEquals('アイドル', $result);

        $result = $this->service->extractWithExcludedWords('Lemon cover by VTuber', []);
        $this->assertEquals('Lemon', $result);
    }

    public function test_removes_sang_by_pattern(): void
    {
        $result = $this->service->extractWithExcludedWords('夜に駆ける sang by Test', []);
        $this->assertEquals('夜に駆ける', $result);
    }

    public function test_removes_japanese_cover_patterns(): void
    {
        // "歌ってみた" を末尾から除去
        $result = $this->service->extractWithExcludedWords('Lemon 歌ってみた', []);
        $this->assertEquals('Lemon', $result);

        // "を歌ってみた" を除去
        $result = $this->service->extractWithExcludedWords('夜に駆けるを歌ってみた', []);
        $this->assertEquals('夜に駆ける', $result);

        // "が歌ってみた" を除去
        $result = $this->service->extractWithExcludedWords('テストが歌ってみた', []);
        $this->assertEquals('テスト', $result);

        // "カバー" を末尾から除去
        $result = $this->service->extractWithExcludedWords('群青 カバー', []);
        $this->assertEquals('群青', $result);
    }

    public function test_removes_excluded_words(): void
    {
        $excludedWords = ['眠り姫', 'TestVTuber'];

        $result = $this->service->extractWithExcludedWords('アイドル 眠り姫', $excludedWords);
        $this->assertEquals('アイドル', $result);

        $result = $this->service->extractWithExcludedWords('Lemon TestVTuber', $excludedWords);
        $this->assertEquals('Lemon', $result);
    }

    public function test_complex_title_extraction(): void
    {
        $excludedWords = ['眠り姫'];

        // 複合パターン: カッコ + covered by + 除外ワード
        $result = $this->service->extractWithExcludedWords(
            '【歌ってみた】アイドル covered by 眠り姫',
            $excludedWords
        );
        $this->assertEquals('アイドル', $result);

        // 複合パターン: カッコ + 日本語パターン + 除外ワード
        $result = $this->service->extractWithExcludedWords(
            '【MV】夜に駆ける 眠り姫',
            $excludedWords
        );
        $this->assertEquals('夜に駆ける', $result);
    }

    public function test_returns_original_if_result_is_empty(): void
    {
        // 全て除去されてしまう場合は元のタイトルを返す
        $result = $this->service->extractWithExcludedWords('歌ってみた', []);
        $this->assertEquals('歌ってみた', $result);
    }

    public function test_cleans_up_extra_whitespace(): void
    {
        $result = $this->service->extractWithExcludedWords('  アイドル   ', []);
        $this->assertEquals('アイドル', $result);

        $result = $this->service->extractWithExcludedWords('【歌ってみた】  アイドル  ', []);
        $this->assertEquals('アイドル', $result);
    }

    public function test_handles_real_world_examples(): void
    {
        $excludedWords = ['眠り姫', 'にじさんじ'];

        // ケース1: シンプルなカバー曲
        $result = $this->service->extractWithExcludedWords(
            '【歌ってみた】KING',
            $excludedWords
        );
        $this->assertEquals('KING', $result);

        // ケース2: アーティスト名付き
        $result = $this->service->extractWithExcludedWords(
            '【Cover】アイドル / YOASOBI',
            $excludedWords
        );
        $this->assertEquals('アイドル / YOASOBI', $result);

        // ケース3: VTuber名が含まれる
        $result = $this->service->extractWithExcludedWords(
            '【歌ってみた】夜に駆ける covered by 眠り姫',
            $excludedWords
        );
        $this->assertEquals('夜に駆ける', $result);

        // ケース4: 複数のカッコと装飾
        $result = $this->service->extractWithExcludedWords(
            '【歌ってみた】【Short】Lemon',
            $excludedWords
        );
        $this->assertEquals('Lemon', $result);
    }

    public function test_case_insensitive_keyword_removal(): void
    {
        // 大文字・小文字混在でも除去
        $result = $this->service->extractWithExcludedWords('【COVER】Lemon', []);
        $this->assertEquals('Lemon', $result);

        $result = $this->service->extractWithExcludedWords('アイドル COVERED BY Test', []);
        $this->assertEquals('アイドル', $result);
    }

    public function test_handles_empty_excluded_words(): void
    {
        $result = $this->service->extractWithExcludedWords('【歌ってみた】アイドル covered by Test', []);
        $this->assertEquals('アイドル', $result);
    }

    public function test_handles_special_characters_in_excluded_words(): void
    {
        $excludedWords = ['(テスト)', '[特殊]'];

        $result = $this->service->extractWithExcludedWords('アイドル (テスト)', $excludedWords);
        $this->assertEquals('アイドル', $result);
    }
}

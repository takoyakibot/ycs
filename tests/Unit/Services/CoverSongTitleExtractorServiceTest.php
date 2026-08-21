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

    /**
     * カッコ除去対象は TextNormalizer::getIgnoreKeywords() を情報源とするが、
     * カッコ内の部分一致で誤爆する語（ver / video 等）は除外している（#669）。
     * これらの語を含むだけのカッコは除去されないこと。
     */
    public function test_bracket_keyword_exclusions_keep_unrelated_brackets(): void
    {
        // 'ver' は無視キーワードだがカッコ除去には使わない（"version" 等に部分一致するため）
        $result = $this->service->extractWithExcludedWords('群青（ver.2）', []);
        $this->assertEquals('群青（ver.2）', $result);

        // 'video' も同様（"videogame" 等に部分一致するため）
        $result = $this->service->extractWithExcludedWords('曲名（videogame song）', []);
        $this->assertEquals('曲名（videogame song）', $result);

        // 'utawaku' / 'vtuber' / 'vsinger' は従来の bracketKeywords に無かった挙動を維持
        $result = $this->service->extractWithExcludedWords('曲名【utawaku】', []);
        $this->assertEquals('曲名【utawaku】', $result);
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

    public function test_excluded_words_applied_in_length_descending_order(): void
    {
        // 包含関係のある除外ワード: "/【hoge】" と "【hoge】"
        // 長い方を先に適用しないと、短い方が部分マッチして残りが生じる
        $excludedWords = ['【hoge】', '/【hoge】'];

        $result = $this->service->extractWithExcludedWords('楽曲名 /【hoge】', $excludedWords);
        $this->assertEquals('楽曲名', $result);

        // 順序を入れ替えても同じ結果になること（内部でソートされるため）
        $excludedWords = ['/【hoge】', '【hoge】'];
        $result = $this->service->extractWithExcludedWords('楽曲名 /【hoge】', $excludedWords);
        $this->assertEquals('楽曲名', $result);
    }

    public function test_handles_special_characters_in_excluded_words(): void
    {
        $excludedWords = ['(テスト)', '[特殊]'];

        $result = $this->service->extractWithExcludedWords('アイドル (テスト)', $excludedWords);
        $this->assertEquals('アイドル', $result);
    }

    public function test_preserves_wave_dash_in_cleanup(): void
    {
        // 波ダッシュ（U+301C）が cleanup() の trim で壊れないことを確認
        $excludedWords = ['/《テスト Cover》'];

        $result = $this->service->extractWithExcludedWords(
            'フクロウ〜フクロウが知らせる客が来たと〜/《テスト Cover》',
            $excludedWords
        );
        $this->assertEquals('フクロウ〜フクロウが知らせる客が来たと〜', $result);
    }

    public function test_cleanup_trims_separator_characters(): void
    {
        // 前後の区切り文字が正しく除去されること
        $result = $this->service->extractWithExcludedWords('/【Cover】楽曲名/', []);
        $this->assertEquals('楽曲名', $result);

        $result = $this->service->extractWithExcludedWords('｜【Cover】楽曲名｜', []);
        $this->assertEquals('楽曲名', $result);

        $result = $this->service->extractWithExcludedWords('―【Cover】楽曲名―', []);
        $this->assertEquals('楽曲名', $result);
    }
}

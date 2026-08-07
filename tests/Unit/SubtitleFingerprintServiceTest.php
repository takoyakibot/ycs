<?php

namespace Tests\Unit;

use App\Services\SubtitleFingerprintService;
use App\Services\SubtitleMatchingService;
use PHPUnit\Framework\TestCase;

class SubtitleFingerprintServiceTest extends TestCase
{
    // ==========================================
    // トライグラム生成のテスト
    // ==========================================

    public function test_generate_trigrams_basic(): void
    {
        $trigrams = SubtitleFingerprintService::generateTrigrams('abcdef');

        $this->assertEquals(['abc', 'bcd', 'cde', 'def'], $trigrams);
    }

    public function test_generate_trigrams_japanese(): void
    {
        $trigrams = SubtitleFingerprintService::generateTrigrams('あいうえお');

        $this->assertEquals(['あいう', 'いうえ', 'うえお'], $trigrams);
    }

    public function test_generate_trigrams_short_text(): void
    {
        $this->assertEquals([], SubtitleFingerprintService::generateTrigrams('ab'));
        $this->assertEquals([], SubtitleFingerprintService::generateTrigrams(''));
        $this->assertEquals(['abc'], SubtitleFingerprintService::generateTrigrams('abc'));
    }

    public function test_generate_trigrams_removes_duplicates(): void
    {
        // 'aaa' → ['aaa'] (重複するトライグラムはユニーク化)
        $trigrams = SubtitleFingerprintService::generateTrigrams('aaaa');

        $this->assertEquals(['aaa'], $trigrams);
    }

    // ==========================================
    // テキスト正規化のテスト
    // ==========================================

    public function test_normalize_for_fingerprint(): void
    {
        $result = SubtitleFingerprintService::normalizeForFingerprint('Hello, World! こんにちは。');

        $this->assertEquals('helloworldこんにちは', $result);
    }

    public function test_normalize_removes_punctuation_and_symbols(): void
    {
        $result = SubtitleFingerprintService::normalizeForFingerprint('テスト！？「」（）…♪');

        $this->assertEquals('テスト', $result);
    }

    public function test_normalize_removes_spaces(): void
    {
        $result = SubtitleFingerprintService::normalizeForFingerprint('a b　c　d');

        $this->assertEquals('abcd', $result);
    }

    public function test_normalize_removes_asr_annotations(): void
    {
        $this->assertEquals(
            'きみのこえ',
            SubtitleFingerprintService::normalizeForFingerprint('[音楽] きみのこえ [拍手]')
        );

        // 全角の角括弧・英語表記のアノテーションも除去する
        $this->assertEquals(
            'きみのこえ',
            SubtitleFingerprintService::normalizeForFingerprint('［音楽］きみのこえ[Applause]')
        );
    }

    public function test_normalize_music_only_window_becomes_empty(): void
    {
        // 前奏や間奏の窓は [音楽] だけになる。角括弧ごと除去しないと「音楽」が本文として
        // 残り、内容の異なる楽曲どうしが同じトライグラムを持ってしまう。
        $normalized = SubtitleFingerprintService::normalizeForFingerprint('[音楽] [音楽] [音楽] [音楽]');

        $this->assertEquals('', $normalized);
        $this->assertEquals([], SubtitleFingerprintService::generateTrigrams($normalized));
    }

    public function test_normalize_keeps_long_bracketed_text(): void
    {
        // 括弧内が長いものは効果音アノテーションとみなさず、本文として残す
        $long = str_repeat('あ', 21);

        $result = SubtitleFingerprintService::normalizeForFingerprint("[{$long}]");

        $this->assertEquals($long, $result);
    }

    // ==========================================
    // 字幕ウィンドウ抽出のテスト
    // ==========================================

    public function test_extract_subtitle_window(): void
    {
        $service = new SubtitleFingerprintService;

        $segments = [
            ['start' => 0, 'duration' => 3, 'text' => '最初のセグメント'],
            ['start' => 55, 'duration' => 3, 'text' => '対象前'],
            ['start' => 58, 'duration' => 3, 'text' => '対象開始'],
            ['start' => 61, 'duration' => 3, 'text' => '対象中'],
            ['start' => 85, 'duration' => 3, 'text' => '対象後'],
            ['start' => 100, 'duration' => 3, 'text' => '範囲外'],
        ];

        // ts_num=60, 窓=59-90秒
        $result = $service->extractSubtitleWindow($segments, 60, 30);

        // 55+3=58 > 59 → 含まれない（58 < 59）
        // 58+3=61 > 59 → 含まれる
        // 61+3=64 > 59 → 含まれる
        // 85+3=88 < 90 → 含まれる
        // 100+3=103 > 59 but 100 >= 90 → 含まれない
        $this->assertStringContainsString('対象開始', $result);
        $this->assertStringContainsString('対象中', $result);
        $this->assertStringContainsString('対象後', $result);
        $this->assertStringNotContainsString('最初', $result);
        $this->assertStringNotContainsString('範囲外', $result);
    }

    public function test_extract_subtitle_window_boundary(): void
    {
        $service = new SubtitleFingerprintService;

        $segments = [
            ['start' => 59, 'duration' => 2, 'text' => 'ちょうど境界'],
            ['start' => 90, 'duration' => 2, 'text' => '終了境界'],
        ];

        // ts_num=60, 窓=59-90秒
        $result = $service->extractSubtitleWindow($segments, 60, 30);

        $this->assertStringContainsString('ちょうど境界', $result);
        // 90 >= 90 → 含まれない
        $this->assertStringNotContainsString('終了境界', $result);
    }

    public function test_extract_subtitle_window_empty(): void
    {
        $service = new SubtitleFingerprintService;

        $result = $service->extractSubtitleWindow([], 60, 30);

        $this->assertEquals('', $result);
    }

    public function test_extract_subtitle_window_defaults_to_configured_duration(): void
    {
        $service = new SubtitleFingerprintService;

        $segments = [
            // 歌い出しの45秒後。前奏が長い楽曲ではこのあたりから歌詞が出はじめる
            ['start' => 105, 'duration' => 3, 'text' => '歌い出しの45秒後'],
        ];

        // 既定の窓（ts_num=60 から WINDOW_DURATION_SEC 秒）には含まれる
        $this->assertStringContainsString(
            '歌い出しの45秒後',
            $service->extractSubtitleWindow($segments, 60)
        );

        // 旧来の30秒窓では取りこぼしていた
        $this->assertStringNotContainsString(
            '歌い出しの45秒後',
            $service->extractSubtitleWindow($segments, 60, 30)
        );
    }

    // ==========================================
    // Jaccard類似度のテスト
    // ==========================================

    public function test_jaccard_similarity_identical(): void
    {
        $trigrams = ['abc', 'bcd', 'cde'];

        $result = SubtitleMatchingService::jaccardSimilarity($trigrams, $trigrams);

        $this->assertEquals(1.0, $result);
    }

    public function test_jaccard_similarity_disjoint(): void
    {
        $a = ['abc', 'bcd', 'cde'];
        $b = ['xyz', 'yza', 'zab'];

        $result = SubtitleMatchingService::jaccardSimilarity($a, $b);

        $this->assertEquals(0.0, $result);
    }

    public function test_jaccard_similarity_partial(): void
    {
        $a = ['abc', 'bcd', 'cde', 'def'];
        $b = ['abc', 'bcd', 'xyz', 'yza'];

        // intersection = 2 (abc, bcd), union = 6
        $result = SubtitleMatchingService::jaccardSimilarity($a, $b);

        $this->assertEqualsWithDelta(2.0 / 6.0, $result, 0.0001);
    }

    public function test_jaccard_similarity_empty(): void
    {
        $this->assertEquals(0.0, SubtitleMatchingService::jaccardSimilarity([], ['abc']));
        $this->assertEquals(0.0, SubtitleMatchingService::jaccardSimilarity(['abc'], []));
        $this->assertEquals(0.0, SubtitleMatchingService::jaccardSimilarity([], []));
    }
}

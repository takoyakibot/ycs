<?php

namespace Tests\Unit\Services;

use App\Helpers\TextNormalizer;
use App\Services\TimestampExtractorService;
use Tests\TestCase;

class TimestampExtractorServiceTest extends TestCase
{
    private TimestampExtractorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TimestampExtractorService;
    }

    /**
     * 絵文字を含むタイムスタンプテキストが正しく抽出されることをテスト
     */
    public function test_extract_timestamps_preserves_emoji(): void
    {
        $description = "0:00 🎵 オープニング\n1:30 🔥 アーティスト / 曲名 🎶\n3:00 ⭐ エンディング";
        $results = $this->service->extractTimestamps('test_video1', '1', $description, 'dummy_comment_id');

        $this->assertCount(3, $results);
        $this->assertEquals('🎵 オープニング', $results[0]['text']);
        $this->assertEquals('🔥 アーティスト / 曲名 🎶', $results[1]['text']);
        $this->assertEquals('⭐ エンディング', $results[2]['text']);
    }

    /**
     * 複合絵文字（ZWJ結合など）を含むテキストが正しく抽出されることをテスト
     */
    public function test_extract_timestamps_preserves_complex_emoji(): void
    {
        $description = "0:00 👋🏻 あいさつ\n1:00 🏳️‍🌈 テスト";
        $results = $this->service->extractTimestamps('test_video2', '1', $description, 'dummy_comment_id');

        $this->assertCount(2, $results);
        $this->assertEquals('👋🏻 あいさつ', $results[0]['text']);
        $this->assertEquals('🏳️‍🌈 テスト', $results[1]['text']);
    }

    /**
     * applyStripPatterns: 基本的な除去
     */
    public function test_apply_strip_patterns_removes_patterns(): void
    {
        $text = '🎵 テスト曲名 ♪';
        $patterns = ['🎵', '♪'];

        $result = TimestampExtractorService::applyStripPatterns($text, $patterns);

        $this->assertEquals('テスト曲名', $result);
    }

    /**
     * applyStripPatterns: 空配列の場合はそのまま返す
     */
    public function test_apply_strip_patterns_with_empty_patterns(): void
    {
        $text = '🎵 テスト曲名';
        $result = TimestampExtractorService::applyStripPatterns($text, []);

        $this->assertEquals('🎵 テスト曲名', $result);
    }

    /**
     * applyStripPatterns: 除去後に空文字になるケース
     */
    public function test_apply_strip_patterns_result_empty(): void
    {
        $text = '🎵♪';
        $patterns = ['🎵', '♪'];

        $result = TimestampExtractorService::applyStripPatterns($text, $patterns);

        $this->assertEquals('', $result);
    }

    /**
     * applyStripPatterns: 複数パターンの重複適用
     */
    public function test_apply_strip_patterns_multiple_occurrences(): void
    {
        $text = '▶ テスト ▶ 曲名 ▶';
        $patterns = ['▶'];

        $result = TimestampExtractorService::applyStripPatterns($text, $patterns);

        $this->assertEquals('テスト  曲名', $result);
    }

    /**
     * applyStripPatterns: マルチバイト文字パターン
     */
    public function test_apply_strip_patterns_multibyte(): void
    {
        $text = '【テスト曲名】';
        $patterns = ['【', '】'];

        $result = TimestampExtractorService::applyStripPatterns($text, $patterns);

        $this->assertEquals('テスト曲名', $result);
    }

    /**
     * extractTimestamps: 除去パターン付きでnormalized_textに反映される
     */
    public function test_extract_timestamps_with_strip_patterns(): void
    {
        $description = "0:00 🎵 テスト曲名 ♪\n1:30 ▶ アーティスト / 曲名";
        $stripPatterns = ['🎵', '♪', '▶'];

        $results = $this->service->extractTimestamps('test_video', '1', $description, 'dummy', $stripPatterns);

        $this->assertCount(2, $results);
        // textは元のまま保持
        $this->assertEquals('🎵 テスト曲名 ♪', $results[0]['text']);
        $this->assertEquals('▶ アーティスト / 曲名', $results[1]['text']);
        // normalized_textには除去パターンが適用された上でnormalizeされた値が入る
        $this->assertEquals(TextNormalizer::normalize('テスト曲名'), $results[0]['normalized_text']);
        $this->assertEquals(TextNormalizer::normalize('アーティスト / 曲名'), $results[1]['normalized_text']);
    }
}

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
     * 範囲表記（from〜to）: 開始時刻を採用し、終了時刻はtextに残さない（#627）
     */
    public function test_extract_timestamps_removes_range_end_time(): void
    {
        $description = "1:23:45〜1:27:30 曲名A\n2:00~3:00 曲名B\n10:00 → 12:00 曲名C\n4:00 - 5:00 曲名D";
        $results = $this->service->extractTimestamps('test_video3', '1', $description, 'dummy_comment_id');

        $this->assertCount(4, $results);
        $this->assertEquals('1:23:45', $results[0]['ts_text']);
        $this->assertEquals('曲名A', $results[0]['text']);
        $this->assertEquals('2:00', $results[1]['ts_text']);
        $this->assertEquals('曲名B', $results[1]['text']);
        $this->assertEquals('曲名C', $results[2]['text']);
        $this->assertEquals('曲名D', $results[3]['text']);
    }

    /**
     * 括弧囲みタイムスタンプ: 空の括弧をtextに残さない（#627）
     */
    public function test_extract_timestamps_removes_surrounding_brackets(): void
    {
        $description = "[1:23] 曲名A / アーティストA\n【2:34】曲名B\n（3:45）曲名C\n(4:56) 曲名D";
        $results = $this->service->extractTimestamps('test_video4', '1', $description, 'dummy_comment_id');

        $this->assertCount(4, $results);
        $this->assertEquals('1:23', $results[0]['ts_text']);
        $this->assertEquals('曲名A / アーティストA', $results[0]['text']);
        $this->assertEquals('曲名B', $results[1]['text']);
        $this->assertEquals('曲名C', $results[2]['text']);
        $this->assertEquals('曲名D', $results[3]['text']);
    }

    /**
     * 括弧囲み＋範囲表記の複合（#627）
     */
    public function test_extract_timestamps_removes_bracketed_range(): void
    {
        $description = '[1:23〜4:56] 曲名A';
        $results = $this->service->extractTimestamps('test_video5', '1', $description, 'dummy_comment_id');

        $this->assertCount(1, $results);
        $this->assertEquals('1:23', $results[0]['ts_text']);
        $this->assertEquals('曲名A', $results[0]['text']);
    }

    /**
     * 対になっていない括弧や曲名中の記号は誤除去しない（#627）
     */
    public function test_extract_timestamps_keeps_unpaired_brackets_and_title_symbols(): void
    {
        $description = "【告知】1:23 曲名A\n2:34 曲名B（cover）\n3:45 A〜B ノンストップメドレー";
        $results = $this->service->extractTimestamps('test_video6', '1', $description, 'dummy_comment_id');

        $this->assertCount(3, $results);
        $this->assertEquals('【告知】 曲名A', $results[0]['text']);
        $this->assertEquals('曲名B（cover）', $results[1]['text']);
        $this->assertEquals('A〜B ノンストップメドレー', $results[2]['text']);
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

    /**
     * applyStripPatterns: 正規表現パターンで除去
     */
    public function test_apply_strip_patterns_with_regex(): void
    {
        $text = '【MV】テスト曲名（full ver.）';
        $patterns = [
            ['pattern' => '/【.*?】/u', 'is_regex' => true],
            ['pattern' => '/（.*?）/u', 'is_regex' => true],
        ];

        $result = TimestampExtractorService::applyStripPatterns($text, $patterns);

        $this->assertEquals('テスト曲名', $result);
    }

    /**
     * applyStripPatterns: 正規表現と文字列パターンの混在
     */
    public function test_apply_strip_patterns_mixed_regex_and_string(): void
    {
        $text = '🎵 【リクエスト】テスト曲名 ♪';
        $patterns = [
            ['pattern' => '🎵', 'is_regex' => false],
            ['pattern' => '/【.*?】/u', 'is_regex' => true],
            '♪',  // 文字列としても後方互換
        ];

        $result = TimestampExtractorService::applyStripPatterns($text, $patterns);

        $this->assertEquals('テスト曲名', $result);
    }

    /**
     * applyStripPatterns: 構造化配列形式（is_regex=false）
     */
    public function test_apply_strip_patterns_with_structured_array_non_regex(): void
    {
        $text = '🎵 テスト曲名 ♪';
        $patterns = [
            ['pattern' => '🎵', 'is_regex' => false],
            ['pattern' => '♪', 'is_regex' => false],
        ];

        $result = TimestampExtractorService::applyStripPatterns($text, $patterns);

        $this->assertEquals('テスト曲名', $result);
    }

    /**
     * applyStripPatterns: 無効な正規表現はスキップされる
     */
    public function test_apply_strip_patterns_invalid_regex_is_skipped(): void
    {
        $text = 'テスト曲名';
        $patterns = [
            ['pattern' => '/[invalid/', 'is_regex' => true],
        ];

        $result = TimestampExtractorService::applyStripPatterns($text, $patterns);

        $this->assertEquals('テスト曲名', $result);
    }

    /**
     * applyStripPatterns: 絵文字を正規表現で除去
     */
    public function test_apply_strip_patterns_regex_removes_emoji_range(): void
    {
        $text = '🎵🎶 テスト曲名 ✨';
        $patterns = [
            ['pattern' => '/[\x{1F3B5}\x{1F3B6}\x{2728}]/u', 'is_regex' => true],
        ];

        $result = TimestampExtractorService::applyStripPatterns($text, $patterns);

        $this->assertEquals('テスト曲名', $result);
    }

    /**
     * extractTimestamps: 正規表現パターン付きでnormalized_textに反映される
     */
    public function test_extract_timestamps_with_regex_strip_patterns(): void
    {
        $description = "0:00 【MV】テスト曲名\n1:30 （リクエスト）アーティスト / 曲名";
        $stripPatterns = [
            ['pattern' => '/【.*?】/u', 'is_regex' => true],
            ['pattern' => '/（.*?）/u', 'is_regex' => true],
        ];

        $results = $this->service->extractTimestamps('test_video', '1', $description, 'dummy', $stripPatterns);

        $this->assertCount(2, $results);
        // textは元のまま保持
        $this->assertEquals('【MV】テスト曲名', $results[0]['text']);
        $this->assertEquals('（リクエスト）アーティスト / 曲名', $results[1]['text']);
        // normalized_textには正規表現パターンが適用された上でnormalizeされた値が入る
        $this->assertEquals(TextNormalizer::normalize('テスト曲名'), $results[0]['normalized_text']);
        $this->assertEquals(TextNormalizer::normalize('アーティスト / 曲名'), $results[1]['normalized_text']);
    }
}

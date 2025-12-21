<?php

namespace Tests\Unit\Services;

use App\Services\YouTubeApiService;
use ReflectionMethod;
use Tests\TestCase;

class YouTubeApiServiceTest extends TestCase
{
    protected YouTubeApiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new YouTubeApiService;
    }

    // ==========================================
    // extractVideoId() のテスト
    // ==========================================

    /**
     * 標準的なYouTube URLからVideo IDを抽出できる
     */
    public function test_extract_video_id_from_standard_url(): void
    {
        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $this->assertEquals('dQw4w9WgXcQ', $this->service->extractVideoId($url));
    }

    /**
     * www無しのYouTube URLからVideo IDを抽出できる
     */
    public function test_extract_video_id_without_www(): void
    {
        $url = 'https://youtube.com/watch?v=dQw4w9WgXcQ';
        $this->assertEquals('dQw4w9WgXcQ', $this->service->extractVideoId($url));
    }

    /**
     * 短縮URLからVideo IDを抽出できる
     */
    public function test_extract_video_id_from_short_url(): void
    {
        $url = 'https://youtu.be/dQw4w9WgXcQ';
        $this->assertEquals('dQw4w9WgXcQ', $this->service->extractVideoId($url));
    }

    /**
     * 埋め込みURLからVideo IDを抽出できる
     */
    public function test_extract_video_id_from_embed_url(): void
    {
        $url = 'https://www.youtube.com/embed/dQw4w9WgXcQ';
        $this->assertEquals('dQw4w9WgXcQ', $this->service->extractVideoId($url));
    }

    /**
     * v形式URLからVideo IDを抽出できる
     */
    public function test_extract_video_id_from_v_url(): void
    {
        $url = 'https://www.youtube.com/v/dQw4w9WgXcQ';
        $this->assertEquals('dQw4w9WgXcQ', $this->service->extractVideoId($url));
    }

    /**
     * パラメータ付きURLからVideo IDを抽出できる
     */
    public function test_extract_video_id_with_additional_params(): void
    {
        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PLtest&t=120';
        $this->assertEquals('dQw4w9WgXcQ', $this->service->extractVideoId($url));
    }

    /**
     * ハイフン・アンダースコアを含むVideo IDを抽出できる
     */
    public function test_extract_video_id_with_special_chars(): void
    {
        // Video IDは[a-zA-Z0-9_-]{11}の形式
        $url = 'https://www.youtube.com/watch?v=abc-_12ABC0';
        $this->assertEquals('abc-_12ABC0', $this->service->extractVideoId($url));
    }

    /**
     * 無効なURLからはnullを返す
     */
    public function test_extract_video_id_returns_null_for_invalid_url(): void
    {
        $invalidUrls = [
            'https://www.youtube.com/watch', // v パラメータなし
            'https://www.youtube.com/channel/UC1234567890', // チャンネルURL
            'https://vimeo.com/12345678', // 別サービス
            'not-a-url',
            '',
        ];

        foreach ($invalidUrls as $url) {
            $this->assertNull($this->service->extractVideoId($url), "Should return null for: {$url}");
        }
    }

    /**
     * Video IDが11文字未満の場合はnullを返す
     *
     * Note: 12文字以上の場合は最初の11文字がマッチするため、
     * 短すぎる場合のみnullを返す
     */
    public function test_extract_video_id_requires_at_least_11_chars(): void
    {
        // 10文字 - 短すぎる
        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXc';
        $this->assertNull($this->service->extractVideoId($url));

        // 12文字 - 最初の11文字がマッチする
        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQQ';
        $this->assertEquals('dQw4w9WgXcQ', $this->service->extractVideoId($url));
    }

    // ==========================================
    // parseDuration() のテスト
    // ==========================================

    /**
     * 時間・分・秒を含むISO 8601形式をパースできる
     */
    public function test_parse_duration_with_hours_minutes_seconds(): void
    {
        $method = new ReflectionMethod(YouTubeApiService::class, 'parseDuration');
        $method->setAccessible(true);

        // 1時間2分3秒 = 3723秒 = 3723000ミリ秒
        $result = $method->invoke($this->service, 'PT1H2M3S');
        $this->assertEquals(3723000, $result);
    }

    /**
     * 分・秒のみのISO 8601形式をパースできる
     */
    public function test_parse_duration_with_minutes_seconds(): void
    {
        $method = new ReflectionMethod(YouTubeApiService::class, 'parseDuration');
        $method->setAccessible(true);

        // 3分30秒 = 210秒 = 210000ミリ秒
        $result = $method->invoke($this->service, 'PT3M30S');
        $this->assertEquals(210000, $result);
    }

    /**
     * 秒のみのISO 8601形式をパースできる
     */
    public function test_parse_duration_with_seconds_only(): void
    {
        $method = new ReflectionMethod(YouTubeApiService::class, 'parseDuration');
        $method->setAccessible(true);

        // 45秒 = 45000ミリ秒
        $result = $method->invoke($this->service, 'PT45S');
        $this->assertEquals(45000, $result);
    }

    /**
     * 分のみのISO 8601形式をパースできる
     */
    public function test_parse_duration_with_minutes_only(): void
    {
        $method = new ReflectionMethod(YouTubeApiService::class, 'parseDuration');
        $method->setAccessible(true);

        // 5分 = 300秒 = 300000ミリ秒
        $result = $method->invoke($this->service, 'PT5M');
        $this->assertEquals(300000, $result);
    }

    /**
     * 時間のみのISO 8601形式をパースできる
     */
    public function test_parse_duration_with_hours_only(): void
    {
        $method = new ReflectionMethod(YouTubeApiService::class, 'parseDuration');
        $method->setAccessible(true);

        // 2時間 = 7200秒 = 7200000ミリ秒
        $result = $method->invoke($this->service, 'PT2H');
        $this->assertEquals(7200000, $result);
    }

    /**
     * 0秒をパースできる
     */
    public function test_parse_duration_zero(): void
    {
        $method = new ReflectionMethod(YouTubeApiService::class, 'parseDuration');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'PT0S');
        $this->assertEquals(0, $result);
    }

    /**
     * 日を含むISO 8601形式をパースできる
     */
    public function test_parse_duration_with_days(): void
    {
        $method = new ReflectionMethod(YouTubeApiService::class, 'parseDuration');
        $method->setAccessible(true);

        // 1日 = 86400秒 = 86400000ミリ秒
        $result = $method->invoke($this->service, 'P1D');
        $this->assertEquals(86400000, $result);
    }

    /**
     * 無効なISO 8601形式はnullを返す
     */
    public function test_parse_duration_invalid_format(): void
    {
        $method = new ReflectionMethod(YouTubeApiService::class, 'parseDuration');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'invalid');
        $this->assertNull($result);
    }

    /**
     * 空文字はnullを返す
     */
    public function test_parse_duration_empty_string(): void
    {
        $method = new ReflectionMethod(YouTubeApiService::class, 'parseDuration');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '');
        $this->assertNull($result);
    }

    // ==========================================
    // extractVideoIdの各URLパターン data provider テスト
    // ==========================================

    /**
     * @dataProvider youtubeUrlProvider
     */
    public function test_extract_video_id_various_patterns(string $url, ?string $expectedId): void
    {
        $this->assertEquals($expectedId, $this->service->extractVideoId($url));
    }

    public static function youtubeUrlProvider(): array
    {
        return [
            'standard watch url' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'short url' => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'embed url' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'v url' => ['https://www.youtube.com/v/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'without www' => ['https://youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'with timestamp' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=120', 'dQw4w9WgXcQ'],
            'with playlist' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PLtest', 'dQw4w9WgXcQ'],
            'short url with params' => ['https://youtu.be/dQw4w9WgXcQ?t=120', 'dQw4w9WgXcQ'],
            'invalid - no v param' => ['https://www.youtube.com/watch', null],
            'invalid - channel url' => ['https://www.youtube.com/channel/UC1234567890', null],
            'invalid - other service' => ['https://vimeo.com/12345', null],
        ];
    }
}

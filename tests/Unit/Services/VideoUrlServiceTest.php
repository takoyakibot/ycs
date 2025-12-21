<?php

namespace Tests\Unit\Services;

use App\Services\VideoUrlService;
use App\Services\YouTubeApiService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class VideoUrlServiceTest extends TestCase
{
    protected VideoUrlService $service;

    protected $mockYoutubeService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockYoutubeService = Mockery::mock(YouTubeApiService::class);
        $this->service = new VideoUrlService($this->mockYoutubeService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * YouTube URLを正しく検出できること
     */
    public function test_detect_platform_youtube(): void
    {
        $youtubeUrls = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
        ];

        foreach ($youtubeUrls as $url) {
            $this->assertEquals('youtube', $this->service->detectPlatform($url), "Failed for URL: {$url}");
        }
    }

    /**
     * ニコニコ動画URLを正しく検出できること
     */
    public function test_detect_platform_niconico(): void
    {
        $niconicoUrls = [
            'https://www.nicovideo.jp/watch/sm12345678',
            'https://nicovideo.jp/watch/sm12345678',
            'https://nico.ms/sm12345678',
            'https://www.nicovideo.jp/watch/so12345678',
            'https://www.nicovideo.jp/watch/nm12345678',
        ];

        foreach ($niconicoUrls as $url) {
            $this->assertEquals('niconico', $this->service->detectPlatform($url), "Failed for URL: {$url}");
        }
    }

    /**
     * 未対応URLの場合nullを返すこと
     */
    public function test_detect_platform_unknown(): void
    {
        $unknownUrls = [
            'https://vimeo.com/12345678',
            'https://example.com/video',
            'not-a-url',
            '',
        ];

        foreach ($unknownUrls as $url) {
            $this->assertNull($this->service->detectPlatform($url), "Should return null for URL: {$url}");
        }
    }

    /**
     * 未対応URLでgetVideoDurationを呼び出すとエラーを返すこと
     */
    public function test_get_video_duration_with_unsupported_url(): void
    {
        $result = $this->service->getVideoDuration('https://vimeo.com/12345678');

        $this->assertNull($result['duration_ms']);
        $this->assertNull($result['video_id']);
        $this->assertNull($result['platform']);
        $this->assertStringContainsString('対応していないURL', $result['error']);
    }

    /**
     * YouTube URLで秒数取得が成功すること
     */
    public function test_get_video_duration_youtube_success(): void
    {
        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $videoId = 'dQw4w9WgXcQ';
        $durationMs = 213000;

        $this->mockYoutubeService
            ->shouldReceive('extractVideoId')
            ->with($url)
            ->once()
            ->andReturn($videoId);

        $this->mockYoutubeService
            ->shouldReceive('getVideoDuration')
            ->with($videoId)
            ->once()
            ->andReturn($durationMs);

        $result = $this->service->getVideoDuration($url);

        $this->assertEquals($durationMs, $result['duration_ms']);
        $this->assertEquals($videoId, $result['video_id']);
        $this->assertEquals('youtube', $result['platform']);
        $this->assertNull($result['error']);
    }

    /**
     * 無効なYouTube URLでエラーを返すこと
     */
    public function test_get_video_duration_youtube_invalid_url(): void
    {
        $url = 'https://www.youtube.com/invalid';

        $this->mockYoutubeService
            ->shouldReceive('extractVideoId')
            ->with($url)
            ->once()
            ->andReturn(null);

        $result = $this->service->getVideoDuration($url);

        $this->assertNull($result['duration_ms']);
        $this->assertNull($result['video_id']);
        $this->assertEquals('youtube', $result['platform']);
        $this->assertStringContainsString('有効なYouTube URL', $result['error']);
    }

    /**
     * YouTube動画が見つからない場合エラーを返すこと
     */
    public function test_get_video_duration_youtube_video_not_found(): void
    {
        $url = 'https://www.youtube.com/watch?v=nonexistent1';
        $videoId = 'nonexistent1';

        $this->mockYoutubeService
            ->shouldReceive('extractVideoId')
            ->with($url)
            ->once()
            ->andReturn($videoId);

        $this->mockYoutubeService
            ->shouldReceive('getVideoDuration')
            ->with($videoId)
            ->once()
            ->andReturn(null);

        $result = $this->service->getVideoDuration($url);

        $this->assertNull($result['duration_ms']);
        $this->assertEquals($videoId, $result['video_id']);
        $this->assertEquals('youtube', $result['platform']);
        $this->assertStringContainsString('動画情報を取得できませんでした', $result['error']);
    }

    /**
     * ニコニコ動画URLで秒数取得が成功すること（Snapshot API）
     */
    public function test_get_video_duration_niconico_success_via_snapshot(): void
    {
        Http::fake([
            'api.search.nicovideo.jp/*' => Http::response([
                'data' => [
                    [
                        'contentId' => 'sm12345678',
                        'lengthSeconds' => 300,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->getVideoDuration('https://www.nicovideo.jp/watch/sm12345678');

        $this->assertEquals(300000, $result['duration_ms']);
        $this->assertEquals('sm12345678', $result['video_id']);
        $this->assertEquals('niconico', $result['platform']);
        $this->assertNull($result['error']);
    }

    /**
     * ニコニコ動画URLで秒数取得が成功すること（Watch API fallback）
     */
    public function test_get_video_duration_niconico_success_via_watch_api(): void
    {
        Http::fake([
            'api.search.nicovideo.jp/*' => Http::response([
                'data' => [],
            ], 200),
            'www.nicovideo.jp/api/watch/*' => Http::response([
                'data' => [
                    'video' => [
                        'duration' => 180,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->getVideoDuration('https://www.nicovideo.jp/watch/sm12345678');

        $this->assertEquals(180000, $result['duration_ms']);
        $this->assertEquals('sm12345678', $result['video_id']);
        $this->assertEquals('niconico', $result['platform']);
        $this->assertNull($result['error']);
    }

    /**
     * ニコニコ動画の両方のAPIが失敗した場合エラーを返すこと
     */
    public function test_get_video_duration_niconico_both_apis_fail(): void
    {
        Http::fake([
            'api.search.nicovideo.jp/*' => Http::response([
                'data' => [],
            ], 200),
            'www.nicovideo.jp/api/watch/*' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $result = $this->service->getVideoDuration('https://www.nicovideo.jp/watch/sm12345678');

        $this->assertNull($result['duration_ms']);
        $this->assertEquals('sm12345678', $result['video_id']);
        $this->assertEquals('niconico', $result['platform']);
        $this->assertStringContainsString('情報を取得できませんでした', $result['error']);
    }

    /**
     * 無効なニコニコ動画URLでエラーを返すこと
     */
    public function test_get_video_duration_niconico_invalid_url(): void
    {
        $result = $this->service->getVideoDuration('https://www.nicovideo.jp/invalid');

        $this->assertNull($result['duration_ms']);
        $this->assertNull($result['video_id']);
        $this->assertEquals('niconico', $result['platform']);
        $this->assertStringContainsString('有効なニコニコ動画URL', $result['error']);
    }

    /**
     * 各種ニコニコ動画URLから正しく動画IDを抽出できること
     *
     * @dataProvider niconicoUrlProvider
     */
    public function test_extract_niconico_video_id(string $url, string $expectedId): void
    {
        // Mock the snapshot API to return the video
        Http::fake([
            'api.search.nicovideo.jp/*' => Http::response([
                'data' => [
                    [
                        'contentId' => $expectedId,
                        'lengthSeconds' => 100,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->getVideoDuration($url);

        $this->assertEquals($expectedId, $result['video_id']);
    }

    public static function niconicoUrlProvider(): array
    {
        return [
            'standard url' => ['https://www.nicovideo.jp/watch/sm12345678', 'sm12345678'],
            'without www' => ['https://nicovideo.jp/watch/sm12345678', 'sm12345678'],
            'short url' => ['https://nico.ms/sm12345678', 'sm12345678'],
            'so prefix' => ['https://www.nicovideo.jp/watch/so12345678', 'so12345678'],
            'nm prefix' => ['https://www.nicovideo.jp/watch/nm12345678', 'nm12345678'],
        ];
    }

    /**
     * YouTube API例外発生時にエラーを返すこと
     */
    public function test_get_video_duration_youtube_exception(): void
    {
        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $videoId = 'dQw4w9WgXcQ';

        $this->mockYoutubeService
            ->shouldReceive('extractVideoId')
            ->with($url)
            ->once()
            ->andReturn($videoId);

        $this->mockYoutubeService
            ->shouldReceive('getVideoDuration')
            ->with($videoId)
            ->once()
            ->andThrow(new \Exception('API Error'));

        $result = $this->service->getVideoDuration($url);

        $this->assertNull($result['duration_ms']);
        $this->assertNull($result['video_id']);
        $this->assertEquals('youtube', $result['platform']);
        $this->assertEquals('API Error', $result['error']);
    }

    /**
     * ニコニコ動画API例外発生時にエラーを返すこと
     */
    public function test_get_video_duration_niconico_exception(): void
    {
        Http::fake([
            'api.search.nicovideo.jp/*' => Http::response(null, 500),
            'www.nicovideo.jp/api/watch/*' => Http::response(null, 500),
        ]);

        $result = $this->service->getVideoDuration('https://www.nicovideo.jp/watch/sm12345678');

        $this->assertNull($result['duration_ms']);
        $this->assertEquals('sm12345678', $result['video_id']);
        $this->assertEquals('niconico', $result['platform']);
        $this->assertNotNull($result['error']);
    }
}

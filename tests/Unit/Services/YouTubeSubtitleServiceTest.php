<?php

namespace Tests\Unit\Services;

use App\Services\YouTubeSubtitleService;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class YouTubeSubtitleServiceTest extends TestCase
{
    protected YouTubeSubtitleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new YouTubeSubtitleService;
    }

    // ==========================================
    // parseSubtitleXml() のテスト
    // ==========================================

    /**
     * 正常な字幕XMLをパースできる
     */
    public function test_parse_subtitle_xml_returns_correct_entries(): void
    {
        $method = new ReflectionMethod(YouTubeSubtitleService::class, 'parseSubtitleXml');
        $method->setAccessible(true);

        $xml = '<?xml version="1.0" encoding="utf-8" ?><transcript><text start="0" dur="1.54">こんにちは</text><text start="1.54" dur="4.16">歌枠やります</text></transcript>';

        $result = $method->invoke($this->service, $xml);

        $this->assertCount(2, $result);
        $this->assertEquals(0, $result[0]['start']);
        $this->assertEquals(1.54, $result[0]['duration']);
        $this->assertEquals('こんにちは', $result[0]['text']);
        $this->assertEquals(1.54, $result[1]['start']);
        $this->assertEquals(4.16, $result[1]['duration']);
        $this->assertEquals('歌枠やります', $result[1]['text']);
    }

    /**
     * HTMLエンティティを正しくデコードできる
     */
    public function test_parse_subtitle_xml_decodes_html_entities(): void
    {
        $method = new ReflectionMethod(YouTubeSubtitleService::class, 'parseSubtitleXml');
        $method->setAccessible(true);

        $xml = '<?xml version="1.0" encoding="utf-8" ?><transcript><text start="0" dur="1">I&#39;m &amp; you &lt;3</text></transcript>';

        $result = $method->invoke($this->service, $xml);

        $this->assertCount(1, $result);
        $this->assertEquals("I'm & you <3", $result[0]['text']);
    }

    /**
     * 空のtranscriptを処理できる
     */
    public function test_parse_subtitle_xml_handles_empty_transcript(): void
    {
        $method = new ReflectionMethod(YouTubeSubtitleService::class, 'parseSubtitleXml');
        $method->setAccessible(true);

        $xml = '<?xml version="1.0" encoding="utf-8" ?><transcript></transcript>';

        $result = $method->invoke($this->service, $xml);

        $this->assertCount(0, $result);
    }

    /**
     * 不正なXMLで例外をスロー
     */
    public function test_parse_subtitle_xml_throws_on_invalid_xml(): void
    {
        $method = new ReflectionMethod(YouTubeSubtitleService::class, 'parseSubtitleXml');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('字幕XMLのパースに失敗しました');

        $method->invoke($this->service, 'not valid xml');
    }

    /**
     * dur属性がない場合は0を返す
     */
    public function test_parse_subtitle_xml_handles_missing_dur(): void
    {
        $method = new ReflectionMethod(YouTubeSubtitleService::class, 'parseSubtitleXml');
        $method->setAccessible(true);

        $xml = '<?xml version="1.0" encoding="utf-8" ?><transcript><text start="5.0">テスト</text></transcript>';

        $result = $method->invoke($this->service, $xml);

        $this->assertCount(1, $result);
        $this->assertEquals(5.0, $result[0]['start']);
        $this->assertEquals(0, $result[0]['duration']);
        $this->assertEquals('テスト', $result[0]['text']);
    }

    // ==========================================
    // findBestTrack() のテスト
    // ==========================================

    /**
     * 手動字幕を優先して返す
     */
    public function test_find_best_track_prefers_manual(): void
    {
        $method = new ReflectionMethod(YouTubeSubtitleService::class, 'findBestTrack');
        $method->setAccessible(true);

        $tracks = [
            ['languageCode' => 'ja', 'kind' => 'asr', 'name' => '自動生成', 'baseUrl' => 'url1'],
            ['languageCode' => 'ja', 'kind' => '', 'name' => '手動', 'baseUrl' => 'url2'],
            ['languageCode' => 'en', 'kind' => '', 'name' => 'English', 'baseUrl' => 'url3'],
        ];

        $result = $method->invoke($this->service, $tracks, 'ja', true);

        $this->assertEquals('url2', $result['baseUrl']);
        $this->assertEquals('手動', $result['name']);
    }

    /**
     * 手動字幕がない場合は自動生成を返す
     */
    public function test_find_best_track_falls_back_to_auto(): void
    {
        $method = new ReflectionMethod(YouTubeSubtitleService::class, 'findBestTrack');
        $method->setAccessible(true);

        $tracks = [
            ['languageCode' => 'ja', 'kind' => 'asr', 'name' => '自動生成', 'baseUrl' => 'url1'],
            ['languageCode' => 'en', 'kind' => '', 'name' => 'English', 'baseUrl' => 'url2'],
        ];

        $result = $method->invoke($this->service, $tracks, 'ja', true);

        $this->assertEquals('url1', $result['baseUrl']);
    }

    /**
     * preferManual=falseで自動生成を優先する
     */
    public function test_find_best_track_prefers_auto_when_configured(): void
    {
        $method = new ReflectionMethod(YouTubeSubtitleService::class, 'findBestTrack');
        $method->setAccessible(true);

        $tracks = [
            ['languageCode' => 'ja', 'kind' => 'asr', 'name' => '自動生成', 'baseUrl' => 'url1'],
            ['languageCode' => 'ja', 'kind' => '', 'name' => '手動', 'baseUrl' => 'url2'],
        ];

        $result = $method->invoke($this->service, $tracks, 'ja', false);

        $this->assertEquals('url1', $result['baseUrl']);
    }

    /**
     * 指定言語が見つからない場合はnullを返す
     */
    public function test_find_best_track_returns_null_when_language_not_found(): void
    {
        $method = new ReflectionMethod(YouTubeSubtitleService::class, 'findBestTrack');
        $method->setAccessible(true);

        $tracks = [
            ['languageCode' => 'en', 'kind' => '', 'name' => 'English', 'baseUrl' => 'url1'],
        ];

        $result = $method->invoke($this->service, $tracks, 'ja', true);

        $this->assertNull($result);
    }

    // ==========================================
    // getCaptionTracks() のテスト（HTTP Fake）
    // ==========================================

    /**
     * 字幕トラック一覧を正しく取得・変換できる
     */
    public function test_get_caption_tracks_returns_formatted_tracks(): void
    {
        Http::fake([
            'www.youtube.com/youtubei/v1/player*' => Http::response([
                'playabilityStatus' => ['status' => 'OK'],
                'captions' => [
                    'playerCaptionsTracklistRenderer' => [
                        'captionTracks' => [
                            [
                                'baseUrl' => 'https://www.youtube.com/api/timedtext?lang=ja',
                                'name' => ['simpleText' => 'Japanese (auto-generated)'],
                                'languageCode' => 'ja',
                                'kind' => 'asr',
                                'isTranslatable' => true,
                            ],
                            [
                                'baseUrl' => 'https://www.youtube.com/api/timedtext?lang=en',
                                'name' => ['simpleText' => 'English'],
                                'languageCode' => 'en',
                                'kind' => '',
                                'isTranslatable' => true,
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $tracks = $this->service->getCaptionTracks('dQw4w9WgXcQ');

        $this->assertCount(2, $tracks);
        $this->assertEquals('ja', $tracks[0]['languageCode']);
        $this->assertEquals('asr', $tracks[0]['kind']);
        $this->assertEquals('en', $tracks[1]['languageCode']);
        $this->assertEquals('', $tracks[1]['kind']);
    }

    /**
     * 字幕がない動画は空配列を返す
     */
    public function test_get_caption_tracks_returns_empty_when_no_captions(): void
    {
        Http::fake([
            'www.youtube.com/youtubei/v1/player*' => Http::response([
                'playabilityStatus' => ['status' => 'OK'],
            ]),
        ]);

        $tracks = $this->service->getCaptionTracks('dQw4w9WgXcQ');

        $this->assertCount(0, $tracks);
    }

    /**
     * 再生不可の動画で例外をスロー
     */
    public function test_get_caption_tracks_throws_on_unplayable_video(): void
    {
        Http::fake([
            'www.youtube.com/youtubei/v1/player*' => Http::response([
                'playabilityStatus' => [
                    'status' => 'ERROR',
                    'reason' => 'Video unavailable',
                ],
            ]),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('動画を取得できません。動画が非公開・削除済み、または年齢制限がある可能性があります');

        $this->service->getCaptionTracks('dQw4w9WgXcQ');
    }

    // ==========================================
    // getSubtitles() のテスト（HTTP Fake）
    // ==========================================

    /**
     * 字幕テキストを正しく取得できる
     */
    public function test_get_subtitles_returns_parsed_subtitles(): void
    {
        Http::fake([
            'www.youtube.com/youtubei/v1/player*' => Http::response([
                'playabilityStatus' => ['status' => 'OK'],
                'captions' => [
                    'playerCaptionsTracklistRenderer' => [
                        'captionTracks' => [
                            [
                                'baseUrl' => 'https://www.youtube.com/api/timedtext?lang=ja',
                                'name' => ['simpleText' => 'Japanese'],
                                'languageCode' => 'ja',
                                'kind' => '',
                                'isTranslatable' => true,
                            ],
                        ],
                    ],
                ],
            ]),
            'www.youtube.com/api/timedtext*' => Http::response(
                '<?xml version="1.0" encoding="utf-8" ?><transcript><text start="0" dur="2.5">こんにちは</text><text start="2.5" dur="3.0">今日は歌枠です</text></transcript>'
            ),
        ]);

        $subtitles = $this->service->getSubtitles('dQw4w9WgXcQ', 'ja');

        $this->assertCount(2, $subtitles);
        $this->assertEquals('こんにちは', $subtitles[0]['text']);
        $this->assertEquals(0, $subtitles[0]['start']);
        $this->assertEquals(2.5, $subtitles[0]['duration']);
        $this->assertEquals('今日は歌枠です', $subtitles[1]['text']);
    }

    /**
     * 字幕がない動画で例外をスロー
     */
    public function test_get_subtitles_throws_when_no_subtitles(): void
    {
        Http::fake([
            'www.youtube.com/youtubei/v1/player*' => Http::response([
                'playabilityStatus' => ['status' => 'OK'],
            ]),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('この動画には字幕がありません');

        $this->service->getSubtitles('dQw4w9WgXcQ', 'ja');
    }

    /**
     * 指定言語が見つからない場合に例外をスロー
     */
    public function test_get_subtitles_throws_when_language_not_found(): void
    {
        Http::fake([
            'www.youtube.com/youtubei/v1/player*' => Http::response([
                'playabilityStatus' => ['status' => 'OK'],
                'captions' => [
                    'playerCaptionsTracklistRenderer' => [
                        'captionTracks' => [
                            [
                                'baseUrl' => 'https://www.youtube.com/api/timedtext?lang=en',
                                'name' => ['simpleText' => 'English'],
                                'languageCode' => 'en',
                                'kind' => '',
                                'isTranslatable' => true,
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('言語「ja」の字幕が見つかりません。利用可能な言語: en');

        $this->service->getSubtitles('dQw4w9WgXcQ', 'ja');
    }

    // ==========================================
    // isAllowedSubtitleUrl() のテスト
    // ==========================================

    /**
     * HTTPS YouTubeドメインは許可される
     */
    public function test_is_allowed_subtitle_url_accepts_youtube_https(): void
    {
        $method = new ReflectionMethod(YouTubeSubtitleService::class, 'isAllowedSubtitleUrl');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->service, 'https://www.youtube.com/api/timedtext?lang=ja'));
        $this->assertTrue($method->invoke($this->service, 'https://youtube.com/api/timedtext?lang=ja'));
        $this->assertTrue($method->invoke($this->service, 'https://www.googlevideo.com/subtitle'));
        $this->assertTrue($method->invoke($this->service, 'https://googlevideo.com/subtitle'));
    }

    /**
     * HTTPスキームは拒否される
     */
    public function test_is_allowed_subtitle_url_rejects_http(): void
    {
        $method = new ReflectionMethod(YouTubeSubtitleService::class, 'isAllowedSubtitleUrl');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($this->service, 'http://www.youtube.com/api/timedtext'));
    }

    /**
     * 非許可スキーム（gopher, file等）は拒否される
     */
    public function test_is_allowed_subtitle_url_rejects_dangerous_schemes(): void
    {
        $method = new ReflectionMethod(YouTubeSubtitleService::class, 'isAllowedSubtitleUrl');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($this->service, 'gopher://www.youtube.com/'));
        $this->assertFalse($method->invoke($this->service, 'file:///etc/passwd'));
        $this->assertFalse($method->invoke($this->service, 'dict://www.youtube.com/'));
    }

    /**
     * 非許可ドメインは拒否される
     */
    public function test_is_allowed_subtitle_url_rejects_unknown_hosts(): void
    {
        $method = new ReflectionMethod(YouTubeSubtitleService::class, 'isAllowedSubtitleUrl');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($this->service, 'https://evil.com/'));
        $this->assertFalse($method->invoke($this->service, 'https://notyoutube.com/'));
        $this->assertFalse($method->invoke($this->service, 'https://example.com/youtube.com'));
    }

    /**
     * 不正なURLは拒否される
     */
    public function test_is_allowed_subtitle_url_rejects_invalid_urls(): void
    {
        $method = new ReflectionMethod(YouTubeSubtitleService::class, 'isAllowedSubtitleUrl');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($this->service, 'not-a-url'));
        $this->assertFalse($method->invoke($this->service, ''));
    }

    // ==========================================
    // callInnerTubePlayer() のテスト（レスポンス検証）
    // ==========================================

    /**
     * 不正なJSONレスポンスで例外をスロー
     */
    public function test_call_innertube_player_throws_on_invalid_json(): void
    {
        Http::fake([
            'www.youtube.com/youtubei/v1/player*' => Http::response('not json', 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('YouTube字幕APIから不正なレスポンスが返されました');

        $this->service->getCaptionTracks('dQw4w9WgXcQ');
    }
}

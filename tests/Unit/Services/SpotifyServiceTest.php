<?php

namespace Tests\Unit\Services;

use App\Services\SpotifyService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpotifyServiceTest extends TestCase
{
    protected SpotifyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SpotifyService;
    }

    /**
     * 認証成功のテスト
     */
    public function test_authenticate_success(): void
    {
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_access_token_123',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),
        ]);

        $result = $this->service->authenticate('test_client_id', 'test_client_secret');

        $this->assertTrue($result);
    }

    /**
     * 認証失敗のテスト
     */
    public function test_authenticate_failure(): void
    {
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'Invalid client credentials',
            ], 401),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Spotify authentication failed with status 401');

        $this->service->authenticate('invalid_client_id', 'invalid_secret');
    }

    /**
     * 楽曲検索成功のテスト
     */
    public function test_search_tracks_success(): void
    {
        // 認証をモック
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
        ]);

        $this->service->authenticate('client_id', 'client_secret');

        // 検索APIをモック
        Http::fake([
            'https://api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => [
                        [
                            'id' => 'track1',
                            'name' => 'Song A',
                            'artists' => [['name' => 'Artist A']],
                            'album' => ['name' => 'Album A'],
                        ],
                        [
                            'id' => 'track2',
                            'name' => 'Song B',
                            'artists' => [['name' => 'Artist B']],
                            'album' => ['name' => 'Album B'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $tracks = $this->service->searchTracks('test query', 2);

        $this->assertCount(2, $tracks);
        $this->assertEquals('track1', $tracks[0]['id']);
        $this->assertEquals('Song A', $tracks[0]['name']);
        $this->assertEquals('Artist A', $tracks[0]['artists'][0]['name']);
    }

    /**
     * 未認証時の楽曲検索エラーテスト
     */
    public function test_search_tracks_without_authentication(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Spotify API is not authenticated.');

        $this->service->searchTracks('test query');
    }

    /**
     * 楽曲検索失敗のテスト（API エラー）
     */
    public function test_search_tracks_api_error(): void
    {
        // 認証をモック
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
        ]);

        $this->service->authenticate('client_id', 'client_secret');

        // 検索APIをエラーレスポンスでモック
        Http::fake([
            'https://api.spotify.com/v1/search*' => Http::response([
                'error' => [
                    'status' => 400,
                    'message' => 'Bad Request',
                ],
            ], 400),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Spotify search request failed with status 400');

        $this->service->searchTracks('invalid query');
    }

    /**
     * 楽曲検索結果が空のテスト
     */
    public function test_search_tracks_no_results(): void
    {
        // 認証をモック
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
        ]);

        $this->service->authenticate('client_id', 'client_secret');

        // 検索APIを空結果でモック
        Http::fake([
            'https://api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => [],
                ],
            ], 200),
        ]);

        $tracks = $this->service->searchTracks('nonexistent song');

        $this->assertEmpty($tracks);
    }

    /**
     * トラック情報取得成功のテスト
     */
    public function test_get_track_success(): void
    {
        // 認証をモック
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
        ]);

        $this->service->authenticate('client_id', 'client_secret');

        // トラック取得APIをモック
        Http::fake([
            'https://api.spotify.com/v1/tracks/track123' => Http::response([
                'id' => 'track123',
                'name' => 'Test Track',
                'artists' => [
                    ['name' => 'Test Artist', 'id' => 'artist123'],
                ],
                'album' => [
                    'name' => 'Test Album',
                    'release_date' => '2024-01-01',
                ],
                'duration_ms' => 180000,
                'external_urls' => [
                    'spotify' => 'https://open.spotify.com/track/track123',
                ],
            ], 200),
        ]);

        $track = $this->service->getTrack('track123');

        $this->assertNotNull($track);
        $this->assertEquals('track123', $track['id']);
        $this->assertEquals('Test Track', $track['name']);
        $this->assertEquals('Test Artist', $track['artists'][0]['name']);
    }

    /**
     * 未認証時のトラック取得エラーテスト
     */
    public function test_get_track_without_authentication(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Spotify API is not authenticated.');

        $this->service->getTrack('track123');
    }

    /**
     * トラック取得失敗のテスト（存在しないトラックID）
     */
    public function test_get_track_not_found(): void
    {
        // 認証をモック
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
        ]);

        $this->service->authenticate('client_id', 'client_secret');

        // トラック取得APIを404でモック
        Http::fake([
            'https://api.spotify.com/v1/tracks/nonexistent' => Http::response([
                'error' => [
                    'status' => 404,
                    'message' => 'Not found',
                ],
            ], 404),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Spotify get track request failed with status 404');

        $this->service->getTrack('nonexistent');
    }

    /**
     * 検索時のlimitパラメータが正しく渡されるかのテスト
     */
    public function test_search_tracks_with_custom_limit(): void
    {
        // 認証と検索APIを同時にモック
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
            'https://api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => array_fill(0, 20, [
                        'id' => 'track',
                        'name' => 'Song',
                        'artists' => [['name' => 'Artist']],
                    ]),
                ],
            ], 200),
        ]);

        $this->service->authenticate('client_id', 'client_secret');
        $tracks = $this->service->searchTracks('query', 20);

        // limitパラメータが正しく送信されたことを確認
        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.spotify.com/v1/search')
                && $request['limit'] === 20
                && $request['q'] === 'query'
                && $request['type'] === 'track';
        });

        $this->assertCount(20, $tracks);
    }

    /**
     * 認証リクエストに正しいパラメータが送信されるかのテスト
     */
    public function test_authenticate_sends_correct_parameters(): void
    {
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
        ]);

        $this->service->authenticate('my_client_id', 'my_client_secret');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://accounts.spotify.com/api/token'
                && $request['grant_type'] === 'client_credentials'
                && $request['client_id'] === 'my_client_id'
                && $request['client_secret'] === 'my_client_secret';
        });
    }

    /**
     * トラック取得時に正しい認証ヘッダーが送信されるかのテスト
     */
    public function test_get_track_sends_correct_authorization_header(): void
    {
        // 認証をモック
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'my_access_token',
            ], 200),
        ]);

        $this->service->authenticate('client_id', 'client_secret');

        // トラック取得APIをモック
        Http::fake([
            'https://api.spotify.com/v1/tracks/*' => Http::response([
                'id' => 'track123',
                'name' => 'Track',
            ], 200),
        ]);

        $this->service->getTrack('track123');

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.spotify.com/v1/tracks/')
                && $request->hasHeader('Authorization', 'Bearer my_access_token');
        });
    }
}

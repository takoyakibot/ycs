<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\User;
use App\Services\YouTubeSubtitleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ManageSubtitleTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $channelAdmin;

    protected Channel $channel;

    protected Archive $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $this->channelAdmin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->channel = Channel::factory()->create([
            'user_id' => $this->channelAdmin->id,
        ]);

        $this->archive = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'dQw4w9WgXcQ',
        ]);
    }

    // ==========================================
    // fetchSubtitleTracks のテスト
    // ==========================================

    /**
     * スーパー管理者が字幕トラック一覧を取得できる
     */
    public function test_fetch_subtitle_tracks_as_super_admin(): void
    {
        $mockService = Mockery::mock(YouTubeSubtitleService::class);
        $mockService->shouldReceive('getCaptionTracks')
            ->with('dQw4w9WgXcQ')
            ->once()
            ->andReturn([
                [
                    'languageCode' => 'ja',
                    'name' => 'Japanese (auto-generated)',
                    'kind' => 'asr',
                    'baseUrl' => 'https://example.com/timedtext?lang=ja',
                    'isTranslatable' => true,
                ],
            ]);
        $this->app->instance(YouTubeSubtitleService::class, $mockService);

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/manage/archives/subtitle-tracks?video_id=dQw4w9WgXcQ');

        $response->assertStatus(200)
            ->assertJsonPath('video_id', 'dQw4w9WgXcQ')
            ->assertJsonCount(1, 'tracks')
            ->assertJsonPath('tracks.0.languageCode', 'ja')
            ->assertJsonMissingPath('tracks.0.baseUrl');
    }

    /**
     * チャンネル管理者が自分のチャンネルの字幕トラックを取得できる
     */
    public function test_fetch_subtitle_tracks_as_channel_admin(): void
    {
        $mockService = Mockery::mock(YouTubeSubtitleService::class);
        $mockService->shouldReceive('getCaptionTracks')
            ->with('dQw4w9WgXcQ')
            ->once()
            ->andReturn([]);
        $this->app->instance(YouTubeSubtitleService::class, $mockService);

        $response = $this->actingAs($this->channelAdmin)
            ->getJson('/api/manage/archives/subtitle-tracks?video_id=dQw4w9WgXcQ');

        $response->assertStatus(200)
            ->assertJsonPath('video_id', 'dQw4w9WgXcQ');
    }

    /**
     * video_idが未指定の場合はバリデーションエラー
     */
    public function test_fetch_subtitle_tracks_requires_video_id(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/manage/archives/subtitle-tracks');

        $response->assertStatus(422);
    }

    /**
     * アーカイブに登録されていない動画は404
     */
    public function test_fetch_subtitle_tracks_returns_404_for_unknown_video(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/manage/archives/subtitle-tracks?video_id=xxxxxxxxxxx');

        $response->assertStatus(404);
    }

    /**
     * 未認証ユーザーはリダイレクトされる
     */
    public function test_fetch_subtitle_tracks_requires_auth(): void
    {
        $response = $this->get('/api/manage/archives/subtitle-tracks?video_id=dQw4w9WgXcQ');

        $response->assertRedirect();
    }

    /**
     * 他のユーザーのチャンネルのアーカイブにはアクセスできない
     */
    public function test_fetch_subtitle_tracks_denied_for_other_user(): void
    {
        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);

        $response = $this->actingAs($otherUser)
            ->getJson('/api/manage/archives/subtitle-tracks?video_id=dQw4w9WgXcQ');

        $response->assertStatus(403);
    }

    // ==========================================
    // fetchSubtitles のテスト
    // ==========================================

    /**
     * 字幕テキストを正しく取得できる
     */
    public function test_fetch_subtitles_returns_subtitles(): void
    {
        $mockService = Mockery::mock(YouTubeSubtitleService::class);
        $mockService->shouldReceive('getSubtitles')
            ->with('dQw4w9WgXcQ', 'ja')
            ->once()
            ->andReturn([
                ['start' => 0, 'duration' => 2.5, 'text' => 'こんにちは'],
                ['start' => 2.5, 'duration' => 3.0, 'text' => '今日は歌枠です'],
            ]);
        $this->app->instance(YouTubeSubtitleService::class, $mockService);

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/manage/archives/subtitles?video_id=dQw4w9WgXcQ&lang=ja');

        $response->assertStatus(200)
            ->assertJsonPath('video_id', 'dQw4w9WgXcQ')
            ->assertJsonPath('lang', 'ja')
            ->assertJsonPath('count', 2)
            ->assertJsonCount(2, 'subtitles')
            ->assertJsonPath('subtitles.0.text', 'こんにちは');
    }

    /**
     * lang未指定時はデフォルトで'ja'を使用する
     */
    public function test_fetch_subtitles_defaults_to_japanese(): void
    {
        $mockService = Mockery::mock(YouTubeSubtitleService::class);
        $mockService->shouldReceive('getSubtitles')
            ->with('dQw4w9WgXcQ', 'ja')
            ->once()
            ->andReturn([]);
        $this->app->instance(YouTubeSubtitleService::class, $mockService);

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/manage/archives/subtitles?video_id=dQw4w9WgXcQ');

        $response->assertStatus(200)
            ->assertJsonPath('lang', 'ja');
    }

    /**
     * 英語字幕を指定して取得できる
     */
    public function test_fetch_subtitles_with_english_lang(): void
    {
        $mockService = Mockery::mock(YouTubeSubtitleService::class);
        $mockService->shouldReceive('getSubtitles')
            ->with('dQw4w9WgXcQ', 'en')
            ->once()
            ->andReturn([
                ['start' => 0, 'duration' => 2.0, 'text' => 'Hello'],
            ]);
        $this->app->instance(YouTubeSubtitleService::class, $mockService);

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/manage/archives/subtitles?video_id=dQw4w9WgXcQ&lang=en');

        $response->assertStatus(200)
            ->assertJsonPath('lang', 'en')
            ->assertJsonPath('subtitles.0.text', 'Hello');
    }

    /**
     * video_idが未指定の場合はバリデーションエラー
     */
    public function test_fetch_subtitles_requires_video_id(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/manage/archives/subtitles');

        $response->assertStatus(422);
    }

    /**
     * 字幕取得エラー時は422を返す
     */
    public function test_fetch_subtitles_returns_422_on_service_error(): void
    {
        $mockService = Mockery::mock(YouTubeSubtitleService::class);
        $mockService->shouldReceive('getSubtitles')
            ->with('dQw4w9WgXcQ', 'ja')
            ->once()
            ->andThrow(new \Exception('この動画には字幕がありません'));
        $this->app->instance(YouTubeSubtitleService::class, $mockService);

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/manage/archives/subtitles?video_id=dQw4w9WgXcQ');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'この動画には字幕がありません');
    }

    /**
     * アーカイブに登録されていない動画は404
     */
    public function test_fetch_subtitles_returns_404_for_unknown_video(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/manage/archives/subtitles?video_id=xxxxxxxxxxx');

        $response->assertStatus(404);
    }

    /**
     * 他のユーザーのチャンネルの字幕にはアクセスできない
     */
    public function test_fetch_subtitles_denied_for_other_user(): void
    {
        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);

        $response = $this->actingAs($otherUser)
            ->getJson('/api/manage/archives/subtitles?video_id=dQw4w9WgXcQ');

        $response->assertStatus(403);
    }
}

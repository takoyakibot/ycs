<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\SubtitleFingerprint;
use App\Models\TsItem;
use App\Models\User;
use App\Models\VideoSubtitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 管理画面のアーカイブ一覧に字幕・フィンガープリントの状況が付加されることを検証する（#592）
 */
class ManageArchiveSubtitleStatusTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $this->channel = Channel::factory()->create(['user_id' => $this->user->id]);
    }

    private function fetchArchives(): \Illuminate\Testing\TestResponse
    {
        $encryptedId = Crypt::encryptString($this->channel->handle);

        return $this->actingAs($this->user)
            ->getJson('/api/manage/channels/'.urlencode($encryptedId));
    }

    public function test_archive_with_subtitles_and_fingerprints(): void
    {
        $archive = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'subvideo001',
        ]);

        VideoSubtitle::create([
            'id' => Str::ulid(),
            'video_id' => 'subvideo001',
            'language_code' => 'ja',
            'kind' => 'asr',
            'subtitle_data' => [['start' => 0, 'duration' => 1, 'text' => 'テスト']],
            'segment_count' => 1,
        ]);

        $tsItem = TsItem::factory()->create([
            'video_id' => 'subvideo001',
            'ts_num' => 60,
            'is_display' => '1',
        ]);

        SubtitleFingerprint::create([
            'id' => Str::ulid(),
            'video_id' => 'subvideo001',
            'ts_item_id' => $tsItem->id,
            'start_sec' => 60,
            'duration_sec' => 60,
            'fingerprint_text' => 'テスト',
            'trigrams' => ['テスト'],
        ]);

        $response = $this->fetchArchives();

        $response->assertStatus(200)
            ->assertJsonPath('data.0.subtitle_status.has_subtitles', true)
            ->assertJsonPath('data.0.subtitle_status.fingerprint_count', 1)
            ->assertJsonPath('data.0.subtitle_status.subtitle_tracks.0.language_code', 'ja')
            ->assertJsonPath('data.0.subtitle_status.subtitle_tracks.0.kind', 'asr');
    }

    public function test_archive_without_subtitles(): void
    {
        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'nosub000001',
        ]);

        $response = $this->fetchArchives();

        $response->assertStatus(200)
            ->assertJsonPath('data.0.subtitle_status.has_subtitles', false)
            ->assertJsonPath('data.0.subtitle_status.fingerprint_count', 0)
            ->assertJsonPath('data.0.subtitle_status.subtitle_tracks', []);
    }

    public function test_multiple_archives_get_individual_status(): void
    {
        // published_at降順で並ぶ想定: 新しい方（with）が先頭
        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'nosub000001',
            'published_at' => now()->subDays(2),
        ]);
        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'subvideo001',
            'published_at' => now()->subDay(),
        ]);

        VideoSubtitle::create([
            'id' => Str::ulid(),
            'video_id' => 'subvideo001',
            'language_code' => 'ja',
            'kind' => '',
            'subtitle_data' => [['start' => 0, 'duration' => 1, 'text' => 'テスト']],
            'segment_count' => 1,
        ]);

        $response = $this->fetchArchives();

        $response->assertStatus(200);

        $data = collect($response->json('data'))->keyBy('video_id');
        $this->assertTrue($data['subvideo001']['subtitle_status']['has_subtitles']);
        $this->assertFalse($data['nosub000001']['subtitle_status']['has_subtitles']);
    }
}

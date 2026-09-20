<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ManageMappingFilterTest extends TestCase
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
            'api_key' => 'test-api-key',
        ]);
        $this->channel = Channel::factory()->create(['user_id' => $this->user->id]);
    }

    private function createArchiveWithMapping(bool $allMapped): Archive
    {
        $archive = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'is_display' => '1',
        ]);

        $tsItem1 = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'is_display' => '1',
        ]);

        if ($allMapped) {
            $song = Song::factory()->create();
            TimestampSongMapping::factory()->create([
                'normalized_text' => $tsItem1->normalized_text,
                'song_id' => $song->id,
            ]);
        }

        return $archive;
    }

    public function test_mapping_filter_unmapped_returns_only_archives_with_unmapped_items(): void
    {
        $unmapped = $this->createArchiveWithMapping(false);
        $mapped = $this->createArchiveWithMapping(true);

        $cryptHandle = Crypt::encryptString($this->channel->handle);

        $response = $this->actingAs($this->user)
            ->getJson("/api/manage/channels/{$cryptHandle}?visible=2&mapping=1");

        $response->assertOk();
        $videoIds = collect($response->json('data'))->pluck('video_id')->toArray();
        $this->assertContains($unmapped->video_id, $videoIds);
        $this->assertNotContains($mapped->video_id, $videoIds);
    }

    public function test_mapping_filter_all_mapped_returns_only_fully_mapped_archives(): void
    {
        $unmapped = $this->createArchiveWithMapping(false);
        $mapped = $this->createArchiveWithMapping(true);

        $cryptHandle = Crypt::encryptString($this->channel->handle);

        $response = $this->actingAs($this->user)
            ->getJson("/api/manage/channels/{$cryptHandle}?visible=2&mapping=2");

        $response->assertOk();
        $videoIds = collect($response->json('data'))->pluck('video_id')->toArray();
        $this->assertContains($mapped->video_id, $videoIds);
        $this->assertNotContains($unmapped->video_id, $videoIds);
    }

    public function test_mapping_filter_empty_returns_all(): void
    {
        $unmapped = $this->createArchiveWithMapping(false);
        $mapped = $this->createArchiveWithMapping(true);

        $cryptHandle = Crypt::encryptString($this->channel->handle);

        $response = $this->actingAs($this->user)
            ->getJson("/api/manage/channels/{$cryptHandle}?visible=2&mapping=");

        $response->assertOk();
        $videoIds = collect($response->json('data'))->pluck('video_id')->toArray();
        $this->assertContains($unmapped->video_id, $videoIds);
        $this->assertContains($mapped->video_id, $videoIds);
    }

    public function test_pending_mapping_treated_as_unmapped(): void
    {
        $archive = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'is_display' => '1',
        ]);

        $tsItem = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'is_display' => '1',
        ]);

        // song_id=null の保留マッピング（pendingステータス相当）
        TimestampSongMapping::factory()->create([
            'normalized_text' => $tsItem->normalized_text,
            'song_id' => null,
            'is_not_song' => false,
        ]);

        $cryptHandle = Crypt::encryptString($this->channel->handle);

        // 未紐付フィルタにヒットすべき（保留マッピングは未紐付扱い）
        $response = $this->actingAs($this->user)
            ->getJson("/api/manage/channels/{$cryptHandle}?visible=2&mapping=1");
        $response->assertOk();
        $videoIds = collect($response->json('data'))->pluck('video_id')->toArray();
        $this->assertContains($archive->video_id, $videoIds);

        // 全て紐付済フィルタにはヒットしないべき
        $response = $this->actingAs($this->user)
            ->getJson("/api/manage/channels/{$cryptHandle}?visible=2&mapping=2");
        $response->assertOk();
        $videoIds = collect($response->json('data'))->pluck('video_id')->toArray();
        $this->assertNotContains($archive->video_id, $videoIds);
    }

    public function test_cross_channel_view_returns_archives_from_all_channels(): void
    {
        $channel2 = Channel::factory()->create(['user_id' => $this->user->id]);

        $archive1 = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'is_display' => '1',
        ]);
        $archive2 = Archive::factory()->create([
            'channel_id' => $channel2->channel_id,
            'is_display' => '1',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/manage/archives/all?visible=2');

        $response->assertOk();
        $videoIds = collect($response->json('data'))->pluck('video_id')->toArray();
        $this->assertContains($archive1->video_id, $videoIds);
        $this->assertContains($archive2->video_id, $videoIds);
    }

    public function test_cross_channel_view_with_mapping_filter(): void
    {
        $channel2 = Channel::factory()->create(['user_id' => $this->user->id]);

        $archive1 = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'is_display' => '1',
        ]);
        TsItem::factory()->create([
            'video_id' => $archive1->video_id,
            'is_display' => '1',
        ]);

        $archive2 = Archive::factory()->create([
            'channel_id' => $channel2->channel_id,
            'is_display' => '1',
        ]);
        $tsItem2 = TsItem::factory()->create([
            'video_id' => $archive2->video_id,
            'is_display' => '1',
        ]);
        $song = Song::factory()->create();
        TimestampSongMapping::factory()->create([
            'normalized_text' => $tsItem2->normalized_text,
            'song_id' => $song->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/manage/archives/all?visible=2&mapping=1');

        $response->assertOk();
        $videoIds = collect($response->json('data'))->pluck('video_id')->toArray();
        $this->assertContains($archive1->video_id, $videoIds);
        $this->assertNotContains($archive2->video_id, $videoIds);
    }

    public function test_cross_channel_view_includes_channel_info(): void
    {
        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'is_display' => '1',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/manage/archives/all?visible=2');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('channel', $data[0]);
        $this->assertEquals($this->channel->title, $data[0]['channel']['title']);
    }

    public function test_cross_channel_page_accessible(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/channels/manage/all');

        $response->assertOk();
    }

    public function test_regular_admin_only_sees_own_channels_in_cross_view(): void
    {
        $adminUser = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
            'api_key' => 'test-api-key',
        ]);
        $ownChannel = Channel::factory()->create(['user_id' => $adminUser->id]);
        $otherChannel = Channel::factory()->create();

        $ownArchive = Archive::factory()->create([
            'channel_id' => $ownChannel->channel_id,
            'is_display' => '1',
        ]);
        $otherArchive = Archive::factory()->create([
            'channel_id' => $otherChannel->channel_id,
            'is_display' => '1',
        ]);

        $response = $this->actingAs($adminUser)
            ->getJson('/api/manage/archives/all?visible=2');

        $response->assertOk();
        $videoIds = collect($response->json('data'))->pluck('video_id')->toArray();
        $this->assertContains($ownArchive->video_id, $videoIds);
        $this->assertNotContains($otherArchive->video_id, $videoIds);
    }
}

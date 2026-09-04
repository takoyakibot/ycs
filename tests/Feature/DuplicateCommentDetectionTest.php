<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class DuplicateCommentDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Channel $channel;

    protected Archive $archive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
        $this->channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $this->archive = Archive::factory()->create(['channel_id' => $this->channel->channel_id]);
    }

    public function test_api_returns_duplicate_pairs(): void
    {
        TsItem::factory()->fromComments()->create([
            'video_id' => $this->archive->video_id,
            'ts_num' => 750,
            'comment_id' => 'comment-aaa',
            'text' => '夜に駆ける',
        ]);
        TsItem::factory()->fromComments()->create([
            'video_id' => $this->archive->video_id,
            'ts_num' => 753,
            'comment_id' => 'comment-bbb',
            'text' => '夜に駆ける / YOASOBI',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/manage/archives/{$this->archive->video_id}/duplicate-comments");

        $response->assertStatus(200)
            ->assertJsonPath('total_pairs', 1)
            ->assertJsonPath('duplicate_pairs.0.diff_seconds', 3);
    }

    public function test_api_returns_empty_for_no_duplicates(): void
    {
        TsItem::factory()->fromComments()->create([
            'video_id' => $this->archive->video_id,
            'ts_num' => 100,
            'comment_id' => 'comment-aaa',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/manage/archives/{$this->archive->video_id}/duplicate-comments");

        $response->assertStatus(200)
            ->assertJsonPath('total_pairs', 0)
            ->assertJsonCount(0, 'duplicate_pairs');
    }

    public function test_fetch_archives_includes_duplicate_pair_count(): void
    {
        TsItem::factory()->fromComments()->create([
            'video_id' => $this->archive->video_id,
            'ts_num' => 750,
            'comment_id' => 'comment-aaa',
        ]);
        TsItem::factory()->fromComments()->create([
            'video_id' => $this->archive->video_id,
            'ts_num' => 753,
            'comment_id' => 'comment-bbb',
        ]);

        $encryptedHandle = Crypt::encryptString($this->channel->handle);
        $response = $this->actingAs($this->user)
            ->getJson("/api/manage/channels/{$encryptedHandle}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $archiveData = collect($data)->firstWhere('video_id', $this->archive->video_id);
        $this->assertEquals(1, $archiveData['duplicate_pair_count']);
    }

    public function test_unauthorized_user_gets_403(): void
    {
        $otherUser = User::factory()->create(['role' => User::ROLE_USER]);
        $otherChannel = Channel::factory()->create(['user_id' => $otherUser->id]);
        $otherArchive = Archive::factory()->create(['channel_id' => $otherChannel->channel_id]);

        $regularUser = User::factory()->create(['role' => User::ROLE_USER]);

        $response = $this->actingAs($regularUser)
            ->getJson("/api/manage/archives/{$otherArchive->video_id}/duplicate-comments");

        $response->assertStatus(403);
    }

    public function test_nonexistent_video_returns_404(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/manage/archives/NONEXISTENT/duplicate-comments');

        $response->assertStatus(404);
    }
}

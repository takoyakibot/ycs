<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 拡張向け: タイムスタンプ作成状況API（#945）
 */
class ExtensionTimestampStatusTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->channel = Channel::factory()->create(['user_id' => $this->user->id]);
    }

    private function requestStatus(string $videoIds): \Illuminate\Testing\TestResponse
    {
        $token = $this->user->createToken('extension')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/extension/timestamp-status?video_ids={$videoIds}");
    }

    public function test_returns_counts_for_display_items(): void
    {
        $archive = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'dQw4w9WgXcQ',
            'is_display' => true,
        ]);

        TsItem::factory()->count(3)->create([
            'video_id' => 'dQw4w9WgXcQ',
            'is_display' => '1',
        ]);
        TsItem::factory()->create([
            'video_id' => 'dQw4w9WgXcQ',
            'is_display' => '0',
        ]);

        $response = $this->requestStatus('dQw4w9WgXcQ');

        $response->assertStatus(200)
            ->assertJsonPath('statuses.dQw4w9WgXcQ', 3);
    }

    public function test_returns_zero_for_no_items(): void
    {
        $response = $this->requestStatus('xxxxxxxxxxx');

        $response->assertStatus(200)
            ->assertJsonPath('statuses.xxxxxxxxxxx', 0);
    }

    public function test_handles_multiple_video_ids(): void
    {
        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'aaaaaaaaaaa',
            'is_display' => true,
        ]);
        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'bbbbbbbbbbb',
            'is_display' => true,
        ]);

        TsItem::factory()->count(2)->create([
            'video_id' => 'aaaaaaaaaaa',
            'is_display' => '1',
        ]);

        $response = $this->requestStatus('aaaaaaaaaaa,bbbbbbbbbbb');

        $response->assertStatus(200)
            ->assertJsonPath('statuses.aaaaaaaaaaa', 2)
            ->assertJsonPath('statuses.bbbbbbbbbbb', 0);
    }

    public function test_filters_invalid_video_ids(): void
    {
        $response = $this->requestStatus('invalid,!!bad!!');

        $response->assertStatus(200)
            ->assertJsonPath('statuses', []);
    }

    public function test_requires_auth(): void
    {
        $response = $this->getJson('/api/extension/timestamp-status?video_ids=dQw4w9WgXcQ');

        $response->assertStatus(401);
    }

    public function test_requires_video_ids_param(): void
    {
        $token = $this->user->createToken('extension')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/extension/timestamp-status');

        $response->assertStatus(422);
    }

    public function test_does_not_expose_counts_for_other_users_channels(): void
    {
        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);
        $otherChannel = Channel::factory()->create(['user_id' => $otherUser->id]);

        Archive::factory()->create([
            'channel_id' => $otherChannel->channel_id,
            'video_id' => 'othersvideo',
            'is_display' => true,
        ]);
        TsItem::factory()->count(5)->create([
            'video_id' => 'othersvideo',
            'is_display' => '1',
        ]);

        $response = $this->requestStatus('othersvideo');

        $response->assertStatus(200)
            ->assertJsonPath('statuses.othersvideo', 0);
    }

    public function test_excludes_hidden_archives(): void
    {
        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'hiddenarchv',
            'is_display' => false,
        ]);
        TsItem::factory()->count(3)->create([
            'video_id' => 'hiddenarchv',
            'is_display' => '1',
        ]);

        $response = $this->requestStatus('hiddenarchv');

        $response->assertStatus(200)
            ->assertJsonPath('statuses.hiddenarchv', 0);
    }
}

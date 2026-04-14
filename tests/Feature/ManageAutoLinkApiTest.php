<?php

namespace Tests\Feature;

use App\Jobs\AutoLinkChannelJob;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ManageAutoLinkApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_link_dispatches_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $channel = Channel::factory()->create(['user_id' => $user->id]);
        $cryptHandle = Crypt::encryptString($channel->handle);

        $response = $this->actingAs($user)
            ->postJson("/api/manage/channels/{$cryptHandle}/auto-link");

        $response->assertOk();
        $response->assertJson(['message' => '自動紐付けをバックグラウンドで開始しました。']);

        Bus::assertDispatched(AutoLinkChannelJob::class, function ($job) use ($channel) {
            return $job->channel->id === $channel->id;
        });
    }

    public function test_auto_link_requires_authentication(): void
    {
        $channel = Channel::factory()->create();
        $cryptHandle = Crypt::encryptString($channel->handle);

        $response = $this->postJson("/api/manage/channels/{$cryptHandle}/auto-link");

        $response->assertUnauthorized();
    }

    public function test_auto_link_requires_channel_access(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $channel = Channel::factory()->create(['user_id' => $owner->id]);
        $cryptHandle = Crypt::encryptString($channel->handle);

        $response = $this->actingAs($otherUser)
            ->postJson("/api/manage/channels/{$cryptHandle}/auto-link");

        $response->assertForbidden();
    }
}

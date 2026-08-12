<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 拡張向け: 音量リストスキャン対象（タイムスタンプなしアーカイブ）一覧API（#601）
 */
class ExtensionScanTargetsTest extends TestCase
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

    private function requestTargets(): \Illuminate\Testing\TestResponse
    {
        $token = $this->user->createToken('extension')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/extension/scan-targets');
    }

    public function test_returns_archives_without_timestamps(): void
    {
        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'notimestamp',
            'is_display' => true,
        ]);

        $response = $this->requestTargets();

        $response->assertStatus(200)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('targets.0.video_id', 'notimestamp');
    }

    public function test_includes_archives_with_only_hidden_timestamps(): void
    {
        // 非表示のts_itemしかない = 表示中のタイムスタンプなし → 対象
        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'hiddentsonly',
            'is_display' => true,
        ]);
        TsItem::factory()->create([
            'video_id' => 'hiddentsonly',
            'ts_num' => 60,
            'text' => 'テスト曲',
            'is_display' => '0',
        ]);

        $response = $this->requestTargets();

        $response->assertStatus(200)->assertJsonPath('count', 1);
    }

    public function test_excludes_archives_with_displayed_timestamps(): void
    {
        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'hastimestamp',
            'is_display' => true,
        ]);
        TsItem::factory()->create([
            'video_id' => 'hastimestamp',
            'ts_num' => 60,
            'text' => 'テスト曲',
            'is_display' => '1',
        ]);

        $response = $this->requestTargets();

        $response->assertStatus(200)->assertJsonPath('count', 0);
    }

    public function test_excludes_hidden_archives(): void
    {
        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'hiddenvideo',
            'is_display' => false,
        ]);

        $response = $this->requestTargets();

        $response->assertStatus(200)->assertJsonPath('count', 0);
    }

    public function test_scopes_to_own_channels_for_regular_admin(): void
    {
        $otherChannel = Channel::factory()->create();
        Archive::factory()->create([
            'channel_id' => $otherChannel->channel_id,
            'video_id' => 'othervideo1',
            'is_display' => true,
        ]);

        $response = $this->requestTargets();

        $response->assertStatus(200)->assertJsonPath('count', 0);
    }

    public function test_requires_auth(): void
    {
        $response = $this->getJson('/api/extension/scan-targets');

        $response->assertStatus(401);
    }
}

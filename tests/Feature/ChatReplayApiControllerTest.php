<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\ChatReplayData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatReplayApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);
    }

    private function validPayload(): array
    {
        return [
            'video_id' => 'dQw4w9WgXcQ',
            'duration' => 3600.0,
            'chat_data' => [
                ['message' => 'こんにちは', 'timestamp' => 10000, 'type' => 'normal'],
                ['message' => '🎵🎵🎵', 'timestamp' => 120000, 'type' => 'normal'],
                ['message' => 'すごい！', 'timestamp' => 300000, 'type' => 'superchat'],
            ],
        ];
    }

    private function postChatReplayData(array $payload, ?string $token = null): \Illuminate\Testing\TestResponse
    {
        $token = $token ?? $this->user->createToken('extension')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/extension/chat-replay-data', $payload);
    }

    public function test_stores_chat_replay_data_successfully(): void
    {
        $response = $this->postChatReplayData($this->validPayload());

        $response->assertStatus(200)
            ->assertJsonPath('video_id', 'dQw4w9WgXcQ')
            ->assertJsonPath('message_count', 3)
            ->assertJsonPath('is_new', true);

        $this->assertDatabaseHas('chat_replay_data', [
            'video_id' => 'dQw4w9WgXcQ',
            'message_count' => 3,
        ]);
    }

    public function test_stores_data_for_video_not_in_archives(): void
    {
        $response = $this->postChatReplayData($this->validPayload());

        $response->assertStatus(200)
            ->assertJsonPath('video_id', 'dQw4w9WgXcQ')
            ->assertJsonPath('is_new', true);
    }

    public function test_denied_for_other_users_channel_when_archived(): void
    {
        $channel = Channel::factory()->create(['user_id' => $this->user->id]);
        Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'dQw4w9WgXcQ',
        ]);

        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);
        $token = $otherUser->createToken('extension')->plainTextToken;

        $response = $this->postChatReplayData($this->validPayload(), $token);

        $response->assertStatus(403);
    }

    public function test_update_or_create_overwrites_existing(): void
    {
        $this->postChatReplayData($this->validPayload());

        $updated = $this->validPayload();
        $updated['duration'] = 7200.0;
        $updated['chat_data'] = [
            ['message' => '更新テスト', 'timestamp' => 5000, 'type' => 'normal'],
        ];

        $response = $this->postChatReplayData($updated);

        $response->assertStatus(200)
            ->assertJsonPath('is_new', false)
            ->assertJsonPath('message_count', 1);

        $this->assertDatabaseCount('chat_replay_data', 1);

        $record = ChatReplayData::where('video_id', 'dQw4w9WgXcQ')->first();
        $this->assertEquals(7200.0, $record->duration);
        $this->assertCount(1, $record->chat_data);
    }

    public function test_requires_auth(): void
    {
        $response = $this->postJson('/api/extension/chat-replay-data', $this->validPayload());

        $response->assertStatus(401);
    }

    public function test_validates_required_fields(): void
    {
        $response = $this->postChatReplayData([]);

        $response->assertStatus(422);
    }

    public function test_validates_video_id_format(): void
    {
        $payload = $this->validPayload();
        $payload['video_id'] = 'short';

        $response = $this->postChatReplayData($payload);

        $response->assertStatus(422);
    }

    public function test_validates_chat_data_structure(): void
    {
        $payload = $this->validPayload();
        $payload['chat_data'] = [
            ['message' => 'テスト', 'timestamp' => 1000, 'type' => 'invalid_type'],
        ];

        $response = $this->postChatReplayData($payload);

        $response->assertStatus(422);
    }

    public function test_validates_chat_data_required_fields(): void
    {
        $payload = $this->validPayload();
        $payload['chat_data'] = [
            ['message' => 'テスト'],
        ];

        $response = $this->postChatReplayData($payload);

        $response->assertStatus(422);
    }

    public function test_accepts_superchat_with_empty_message(): void
    {
        $payload = $this->validPayload();
        $payload['chat_data'] = [
            ['message' => '', 'timestamp' => 10000, 'type' => 'superchat'],
            ['message' => null, 'timestamp' => 20000, 'type' => 'superchat'],
            ['message' => 'テスト', 'timestamp' => 30000, 'type' => 'normal'],
        ];

        $response = $this->postChatReplayData($payload);

        $response->assertStatus(200)
            ->assertJsonPath('message_count', 3);
    }

    public function test_rejects_message_exceeding_max_length(): void
    {
        $payload = $this->validPayload();
        $payload['chat_data'] = [
            ['message' => str_repeat('あ', 501), 'timestamp' => 10000, 'type' => 'normal'],
        ];

        $response = $this->postChatReplayData($payload);

        $response->assertStatus(422);
    }
}

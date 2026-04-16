<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelStripPattern;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ManageStripPatternApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Channel $channel;

    private string $cryptHandle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $this->cryptHandle = Crypt::encryptString($this->channel->handle);
    }

    public function test_add_string_pattern(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/manage/channels/{$this->cryptHandle}/strip-patterns", [
                'pattern' => '🎵',
            ]);

        $response->assertCreated();
        $response->assertJsonFragment(['pattern' => '🎵', 'is_regex' => false]);

        $this->assertDatabaseHas('channel_strip_patterns', [
            'channel_id' => $this->channel->channel_id,
            'pattern' => '🎵',
            'is_regex' => false,
        ]);
    }

    public function test_add_regex_pattern(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/manage/channels/{$this->cryptHandle}/strip-patterns", [
                'pattern' => '/【.*?】/u',
                'is_regex' => true,
            ]);

        $response->assertCreated();
        $response->assertJsonFragment(['pattern' => '/【.*?】/u', 'is_regex' => true]);

        $this->assertDatabaseHas('channel_strip_patterns', [
            'channel_id' => $this->channel->channel_id,
            'pattern' => '/【.*?】/u',
            'is_regex' => true,
        ]);
    }

    public function test_add_invalid_regex_returns_422(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/manage/channels/{$this->cryptHandle}/strip-patterns", [
                'pattern' => '/[invalid/',
                'is_regex' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => '無効な正規表現パターンです']);

        $this->assertDatabaseMissing('channel_strip_patterns', [
            'channel_id' => $this->channel->channel_id,
            'pattern' => '/[invalid/',
        ]);
    }

    public function test_fetch_patterns_includes_is_regex(): void
    {
        ChannelStripPattern::create([
            'channel_id' => $this->channel->channel_id,
            'pattern' => '🎵',
            'is_regex' => false,
        ]);
        ChannelStripPattern::create([
            'channel_id' => $this->channel->channel_id,
            'pattern' => '/【.*?】/u',
            'is_regex' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/manage/channels/{$this->cryptHandle}/strip-patterns");

        $response->assertOk();
        $patterns = $response->json();
        $this->assertCount(2, $patterns);

        // is_regex フラグが含まれていることを確認
        $regexPattern = collect($patterns)->firstWhere('is_regex', true);
        $stringPattern = collect($patterns)->firstWhere('is_regex', false);
        $this->assertNotNull($regexPattern);
        $this->assertNotNull($stringPattern);
        $this->assertEquals('/【.*?】/u', $regexPattern['pattern']);
        $this->assertEquals('🎵', $stringPattern['pattern']);
    }

    public function test_add_pattern_without_is_regex_defaults_to_false(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/manage/channels/{$this->cryptHandle}/strip-patterns", [
                'pattern' => '♪',
            ]);

        $response->assertCreated();
        $response->assertJsonFragment(['is_regex' => false]);
    }
}

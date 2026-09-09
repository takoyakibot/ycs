<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\SpectralScanData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpectralApiControllerTest extends TestCase
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
            'role' => User::ROLE_ADMIN,
        ]);

        $this->channel = Channel::factory()->create(['user_id' => $this->user->id]);

        $this->archive = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'dQw4w9WgXcQ',
        ]);
    }

    private function validPayload(): array
    {
        return [
            'video_id' => 'dQw4w9WgXcQ',
            'sampling_interval' => 2,
            'duration' => 3600.0,
            'spectral_data' => [
                ['flatness' => 0.123, 'voiceBandRatio' => 0.456],
                null,
                ['flatness' => 0.789, 'voiceBandRatio' => 0.321],
            ],
        ];
    }

    private function postSpectralData(array $payload, ?string $token = null): \Illuminate\Testing\TestResponse
    {
        $token = $token ?? $this->user->createToken('extension')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/extension/spectral-data', $payload);
    }

    public function test_stores_spectral_data_successfully(): void
    {
        $response = $this->postSpectralData($this->validPayload());

        $response->assertStatus(200)
            ->assertJsonPath('video_id', 'dQw4w9WgXcQ')
            ->assertJsonPath('data_points', 2)
            ->assertJsonPath('is_new', true);

        $this->assertDatabaseHas('spectral_scan_data', [
            'video_id' => 'dQw4w9WgXcQ',
            'sampling_interval' => 2,
        ]);
    }

    public function test_update_or_create_overwrites_existing(): void
    {
        $this->postSpectralData($this->validPayload());

        $updated = $this->validPayload();
        $updated['duration'] = 7200.0;
        $updated['spectral_data'] = [
            ['flatness' => 0.5, 'voiceBandRatio' => 0.5],
        ];

        $response = $this->postSpectralData($updated);

        $response->assertStatus(200)
            ->assertJsonPath('is_new', false)
            ->assertJsonPath('data_points', 1);

        $this->assertDatabaseCount('spectral_scan_data', 1);

        $record = SpectralScanData::where('video_id', 'dQw4w9WgXcQ')->first();
        $this->assertEquals(7200.0, $record->duration);
    }

    public function test_requires_auth(): void
    {
        $response = $this->postJson('/api/extension/spectral-data', $this->validPayload());

        $response->assertStatus(401);
    }

    public function test_denied_for_other_users_channel(): void
    {
        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);
        $token = $otherUser->createToken('extension')->plainTextToken;

        $response = $this->postSpectralData($this->validPayload(), $token);

        $response->assertStatus(403);
    }

    public function test_returns_404_for_unknown_video(): void
    {
        $payload = $this->validPayload();
        $payload['video_id'] = 'xxxxxxxxxxx';

        $response = $this->postSpectralData($payload);

        $response->assertStatus(404);
    }

    public function test_validates_required_fields(): void
    {
        $response = $this->postSpectralData([]);

        $response->assertStatus(422);
    }

    public function test_validates_video_id_format(): void
    {
        $payload = $this->validPayload();
        $payload['video_id'] = 'short';

        $response = $this->postSpectralData($payload);

        $response->assertStatus(422);
    }

    public function test_validates_spectral_data_values(): void
    {
        $payload = $this->validPayload();
        $payload['spectral_data'] = [
            ['flatness' => 2.0, 'voiceBandRatio' => 0.5],
        ];

        $response = $this->postSpectralData($payload);

        $response->assertStatus(422);
    }
}

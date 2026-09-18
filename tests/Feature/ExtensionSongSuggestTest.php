<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExtensionSongSuggestTest extends TestCase
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

    private function request(string $query): \Illuminate\Testing\TestResponse
    {
        $token = $this->user->createToken('extension')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/extension/song-suggest?q='.urlencode($query));
    }

    public function test_returns_songs_matching_title(): void
    {
        Song::factory()->create(['title' => 'ドライフラワー', 'artist' => '優里']);
        Song::factory()->create(['title' => 'ドラマツルギー', 'artist' => 'Eve']);

        $response = $this->request('ドラ');

        $response->assertOk();
        $suggestions = $response->json('suggestions');
        $this->assertCount(2, $suggestions);
        $this->assertSame('song', $suggestions[0]['source']);
    }

    public function test_returns_songs_matching_artist(): void
    {
        Song::factory()->create(['title' => 'シャルル', 'artist' => 'バルーン']);

        $response = $this->request('バルーン');

        $response->assertOk();
        $suggestions = $response->json('suggestions');
        $this->assertCount(1, $suggestions);
        $this->assertStringContains('バルーン', $suggestions[0]['text']);
    }

    public function test_returns_ts_items_as_supplement(): void
    {
        TsItem::factory()->create([
            'video_id' => $this->archive->video_id,
            'text' => 'ハッピーエンド back number',
            'is_display' => '1',
        ]);

        $response = $this->request('ハッピーエンド');

        $response->assertOk();
        $suggestions = $response->json('suggestions');
        $this->assertGreaterThanOrEqual(1, count($suggestions));
        $found = collect($suggestions)->firstWhere('source', 'ts_item');
        $this->assertNotNull($found);
        $this->assertSame('ハッピーエンド back number', $found['text']);
    }

    public function test_excludes_is_not_song_ts_items(): void
    {
        $tsItem = TsItem::factory()->create([
            'video_id' => $this->archive->video_id,
            'text' => 'おしゃべりタイム',
            'is_display' => '1',
        ]);

        TimestampSongMapping::factory()->create([
            'normalized_text' => $tsItem->normalized_text,
            'is_not_song' => true,
        ]);

        $response = $this->request('おしゃべり');

        $response->assertOk();
        $suggestions = $response->json('suggestions');
        $this->assertCount(0, $suggestions);
    }

    public function test_deduplicates_song_and_ts_item(): void
    {
        $song = Song::factory()->create(['title' => 'Lemon', 'artist' => '米津玄師']);

        TsItem::factory()->create([
            'video_id' => $this->archive->video_id,
            'text' => 'Lemon / 米津玄師',
            'is_display' => '1',
        ]);

        $response = $this->request('Lemon');

        $response->assertOk();
        $suggestions = $response->json('suggestions');
        $texts = array_column($suggestions, 'text');
        $this->assertCount(count(array_unique($texts)), $texts);
    }

    public function test_requires_minimum_2_characters(): void
    {
        Song::factory()->create(['title' => 'A', 'artist' => 'Test']);

        $response = $this->request('A');

        $response->assertStatus(422);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/extension/song-suggest?q=test');

        $response->assertStatus(401);
    }

    public function test_limits_results_to_10(): void
    {
        for ($i = 0; $i < 15; $i++) {
            Song::factory()->create(['title' => "テスト曲{$i}", 'artist' => 'テスト']);
        }

        $response = $this->request('テスト');

        $response->assertOk();
        $this->assertLessThanOrEqual(10, count($response->json('suggestions')));
    }

    public function test_hidden_ts_items_are_excluded(): void
    {
        TsItem::factory()->create([
            'video_id' => $this->archive->video_id,
            'text' => '非表示の曲名テスト',
            'is_display' => '0',
        ]);

        $response = $this->request('非表示の曲名');

        $response->assertOk();
        $this->assertCount(0, $response->json('suggestions'));
    }

    private static function assertStringContains(string $needle, string $haystack): void
    {
        self::assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}

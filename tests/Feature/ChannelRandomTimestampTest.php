<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ガチャ（ランダム再生）APIが一覧と同じ絞り込み条件を考慮することを検証する
 */
class ChannelRandomTimestampTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    private Archive $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->channel = Channel::create([
            'handle' => 'test-channel',
            'channel_id' => 'UC123456789',
            'title' => 'Test Channel',
            'thumbnail' => 'https://example.com/thumb.jpg',
            'user_id' => $user->id,
        ]);

        $this->archive = Archive::create([
            'id' => 'video123',
            'channel_id' => 'UC123456789',
            'video_id' => 'video123',
            'title' => 'Test Archive',
            'thumbnail' => 'https://example.com/video.jpg',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);
    }

    private function createTsItem(string $text, int $tsNum): TsItem
    {
        return TsItem::factory()->create([
            'video_id' => $this->archive->video_id,
            'text' => $text,
            'ts_text' => gmdate('H:i:s', $tsNum),
            'ts_num' => $tsNum,
            'is_display' => 1,
        ]);
    }

    public function test_random_endpoint_respects_search_query(): void
    {
        $this->createTsItem('夜に駆ける', 0);
        $this->createTsItem('アイドル', 180);

        $response = $this->getJson('/api/channels/test-channel/timestamps/random?search='.urlencode('夜に駆ける'));

        $response->assertOk()
            ->assertJsonPath('text', '夜に駆ける');
    }

    public function test_random_endpoint_respects_index_filter(): void
    {
        $this->createTsItem('あいうえお', 0);
        $this->createTsItem('かきくけこ', 180);

        $response = $this->getJson('/api/channels/test-channel/timestamps/random?index='.urlencode('か'));

        $response->assertOk()
            ->assertJsonPath('text', 'かきくけこ');
    }

    public function test_random_endpoint_returns_404_when_filter_matches_nothing(): void
    {
        $this->createTsItem('夜に駆ける', 0);

        $response = $this->getJson('/api/channels/test-channel/timestamps/random?search='.urlencode('存在しない曲名'));

        $response->assertNotFound();
    }

    public function test_random_endpoint_rejects_invalid_index(): void
    {
        $this->createTsItem('夜に駆ける', 0);

        $response = $this->getJson('/api/channels/test-channel/timestamps/random?index=invalid');

        $response->assertStatus(422);
    }

    public function test_random_endpoint_without_filters_returns_any_item(): void
    {
        $this->createTsItem('夜に駆ける', 0);

        $response = $this->getJson('/api/channels/test-channel/timestamps/random');

        $response->assertOk()
            ->assertJsonPath('text', '夜に駆ける');
    }
}

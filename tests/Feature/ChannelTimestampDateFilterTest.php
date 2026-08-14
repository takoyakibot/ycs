<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * タイムスタンプ一覧・ガチャAPIの公開日（期間）フィルターを検証する
 */
class ChannelTimestampDateFilterTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

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
    }

    private function createArchiveWithTsItem(string $videoId, string $publishedAt, string $text): TsItem
    {
        Archive::create([
            'id' => $videoId,
            'channel_id' => $this->channel->channel_id,
            'video_id' => $videoId,
            'title' => 'Archive '.$publishedAt,
            'thumbnail' => 'https://example.com/video.jpg',
            'is_public' => true,
            'is_display' => true,
            'published_at' => $publishedAt,
            'comments_updated_at' => now(),
        ]);

        return TsItem::factory()->create([
            'video_id' => $videoId,
            'text' => $text,
            'ts_text' => '00:00:00',
            'ts_num' => 0,
            'is_display' => 1,
        ]);
    }

    public function test_timestamps_endpoint_filters_by_published_from(): void
    {
        $this->createArchiveWithTsItem('video0000001', '2024-01-15 12:00:00', '古い曲');
        $this->createArchiveWithTsItem('video0000002', '2024-06-15 12:00:00', '新しい曲');

        $response = $this->getJson('/api/channels/test-channel/timestamps?published_from=2024-06-01');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.text', '新しい曲');
    }

    public function test_timestamps_endpoint_filters_by_published_to(): void
    {
        $this->createArchiveWithTsItem('video0000001', '2024-01-15 12:00:00', '古い曲');
        $this->createArchiveWithTsItem('video0000002', '2024-06-15 12:00:00', '新しい曲');

        $response = $this->getJson('/api/channels/test-channel/timestamps?published_to=2024-05-31');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.text', '古い曲');
    }

    public function test_timestamps_endpoint_filters_by_published_range(): void
    {
        $this->createArchiveWithTsItem('video0000001', '2024-01-15 12:00:00', '古い曲');
        $this->createArchiveWithTsItem('video0000002', '2024-06-15 12:00:00', '期間内の曲');
        $this->createArchiveWithTsItem('video0000003', '2024-12-15 12:00:00', '未来の曲');

        $response = $this->getJson('/api/channels/test-channel/timestamps?published_from=2024-06-01&published_to=2024-06-30');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.text', '期間内の曲');
    }

    public function test_timestamps_endpoint_includes_boundary_dates(): void
    {
        $this->createArchiveWithTsItem('video0000001', '2024-06-01 00:00:00', '開始日の曲');
        $this->createArchiveWithTsItem('video0000002', '2024-06-30 23:59:59', '終了日の曲');

        $response = $this->getJson('/api/channels/test-channel/timestamps?published_from=2024-06-01&published_to=2024-06-30');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_timestamps_endpoint_combines_date_filter_with_search(): void
    {
        $this->createArchiveWithTsItem('video0000001', '2024-06-15 12:00:00', '夜に駆ける');
        $this->createArchiveWithTsItem('video0000002', '2024-06-15 12:00:00', 'アイドル');
        $this->createArchiveWithTsItem('video0000003', '2024-01-15 12:00:00', '夜に駆ける（旧）');

        $response = $this->getJson('/api/channels/test-channel/timestamps?published_from=2024-06-01&search='.urlencode('夜に駆ける'));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.text', '夜に駆ける');
    }

    public function test_timestamps_endpoint_available_indexes_respect_date_filter(): void
    {
        $this->createArchiveWithTsItem('video0000001', '2024-01-15 12:00:00', 'あいうえお');
        $this->createArchiveWithTsItem('video0000002', '2024-06-15 12:00:00', 'かきくけこ');

        $response = $this->getJson('/api/channels/test-channel/timestamps?published_from=2024-06-01');

        $response->assertOk()
            ->assertJsonPath('available_indexes', ['か']);
    }

    public function test_timestamps_endpoint_rejects_invalid_date_format(): void
    {
        $this->createArchiveWithTsItem('video0000001', '2024-06-15 12:00:00', '夜に駆ける');

        $response = $this->getJson('/api/channels/test-channel/timestamps?published_from=2024/06/01');

        $response->assertStatus(422);
    }

    public function test_random_endpoint_respects_date_filter(): void
    {
        $this->createArchiveWithTsItem('video0000001', '2024-01-15 12:00:00', '古い曲');
        $this->createArchiveWithTsItem('video0000002', '2024-06-15 12:00:00', '新しい曲');

        $response = $this->getJson('/api/channels/test-channel/timestamps/random?published_from=2024-06-01');

        $response->assertOk()
            ->assertJsonPath('text', '新しい曲');
    }

    public function test_random_endpoint_returns_404_when_date_filter_matches_nothing(): void
    {
        $this->createArchiveWithTsItem('video0000001', '2024-01-15 12:00:00', '古い曲');

        $response = $this->getJson('/api/channels/test-channel/timestamps/random?published_from=2025-01-01');

        $response->assertNotFound();
    }
}

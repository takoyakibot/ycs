<?php

namespace Tests\Unit\Services;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\TimestampStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimestampStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TimestampStatsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TimestampStatsService::class);
    }

    private function createTsItem(string $text, array $overrides = [], array $archiveOverrides = []): TsItem
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(array_merge(
            ['channel_id' => $channel->channel_id],
            $archiveOverrides
        ));

        return TsItem::factory()->create(array_merge([
            'video_id' => $archive->video_id,
            'text' => $text,
            'is_display' => 1,
        ], $overrides));
    }

    public function test_empty_state_returns_zeros(): void
    {
        $summary = $this->service->getSummary();

        $this->assertEquals(0, $summary['unlinked']);
        $this->assertEquals(0, $summary['linked']);
        $this->assertEquals(0, $summary['not_song']);
        $this->assertEquals(0, $summary['linked_rate']);
        $this->assertEquals(0, $summary['recent_count']);
    }

    public function test_counts_unlinked_linked_and_not_song(): void
    {
        $song = Song::factory()->create();

        $this->createTsItem('紐付け済みの曲');
        TimestampSongMapping::factory()->withSong($song)->withText('紐付け済みの曲')->create();

        $this->createTsItem('楽曲ではない');
        TimestampSongMapping::factory()->notSong()->withText('楽曲ではない')->create();

        $this->createTsItem('未紐付けの曲');

        $summary = $this->service->getSummary();

        $this->assertEquals(1, $summary['unlinked']);
        $this->assertEquals(1, $summary['linked']);
        $this->assertEquals(1, $summary['not_song']);
    }

    public function test_linked_rate_calculation(): void
    {
        $song = Song::factory()->create();

        // 4つのユニークテキスト: 2紐付け + 1楽曲でない + 1未紐付け = rate (2+1)/4 = 75%
        $this->createTsItem('曲A');
        TimestampSongMapping::factory()->withSong($song)->withText('曲A')->create();

        $this->createTsItem('曲B');
        TimestampSongMapping::factory()->withSong($song)->withText('曲B')->create();

        $this->createTsItem('雑談');
        TimestampSongMapping::factory()->notSong()->withText('雑談')->create();

        $this->createTsItem('未紐付け');

        $summary = $this->service->getSummary();

        $this->assertEquals(75.0, $summary['linked_rate']);
    }

    public function test_recent_count_within_seven_days(): void
    {
        $this->createTsItem('最近の曲', [], ['published_at' => now()->subDays(3)->toDateString()]);
        $this->createTsItem('古い曲', [], ['published_at' => now()->subDays(10)->toDateString()]);

        $summary = $this->service->getSummary();

        $this->assertEquals(1, $summary['recent_count']);
    }

    public function test_excludes_cover_songs_and_hidden_items(): void
    {
        $this->createTsItem('カバー曲', ['type' => '3']);
        $this->createTsItem('非表示の曲', ['is_display' => 0]);
        $this->createTsItem('表示中の曲');

        $summary = $this->service->getSummary();

        $this->assertEquals(1, $summary['unlinked']);
    }

    public function test_duplicate_normalized_text_counted_once(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'シャルル',
            'is_display' => 1,
        ]);

        $archive2 = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archive2->video_id,
            'text' => 'シャルル',
            'is_display' => 1,
        ]);

        $summary = $this->service->getSummary();

        $this->assertEquals(1, $summary['unlinked']);
    }
}

<?php

namespace Tests\Unit\Services;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\TsItem;
use App\Services\AutoLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoLinkServiceChannelFilterTest extends TestCase
{
    use RefreshDatabase;

    protected AutoLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AutoLinkService::class);
    }

    public function test_channel_filter_only_processes_specified_channel(): void
    {
        $channelA = Channel::factory()->create();
        $archiveA = Archive::factory()->create(['channel_id' => $channelA->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archiveA->video_id,
            'text' => 'Song A',
            'is_display' => 1,
        ]);

        $channelB = Channel::factory()->create();
        $archiveB = Archive::factory()->create(['channel_id' => $channelB->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archiveB->video_id,
            'text' => 'Song B',
            'is_display' => 1,
        ]);

        $result = $this->service->autoLinkUnlinkedTimestamps(100, null, $channelA->channel_id);

        $this->assertEquals(1, $result['processed']);

        $tsItemB = TsItem::where('text', 'Song B')->first();
        $this->assertDatabaseMissing('timestamp_song_mappings', [
            'normalized_text' => $tsItemB->normalized_text,
        ]);
    }

    public function test_null_channel_id_processes_all_channels(): void
    {
        $channelA = Channel::factory()->create();
        $archiveA = Archive::factory()->create(['channel_id' => $channelA->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archiveA->video_id,
            'text' => 'Song Alpha',
            'is_display' => 1,
        ]);

        $channelB = Channel::factory()->create();
        $archiveB = Archive::factory()->create(['channel_id' => $channelB->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archiveB->video_id,
            'text' => 'Song Beta',
            'is_display' => 1,
        ]);

        $result = $this->service->autoLinkUnlinkedTimestamps(100, null, null);

        $this->assertEquals(2, $result['processed']);
    }

    public function test_channel_filter_with_no_unlinked_items(): void
    {
        $channelA = Channel::factory()->create();

        $channelB = Channel::factory()->create();
        $archiveB = Archive::factory()->create(['channel_id' => $channelB->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archiveB->video_id,
            'text' => 'Song B',
            'is_display' => 1,
        ]);

        $result = $this->service->autoLinkUnlinkedTimestamps(100, null, $channelA->channel_id);

        $this->assertEquals(0, $result['processed']);
    }
}

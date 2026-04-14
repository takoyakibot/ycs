<?php

namespace Tests\Unit\Services;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\TsItem;
use App\Services\AutoLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    private function fakeSpotifyApi(array $tracks = []): void
    {
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
            'https://api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => $tracks,
                ],
            ], 200),
        ]);

        config(['services.spotify.client_id' => 'test_id']);
        config(['services.spotify.client_secret' => 'test_secret']);
    }

    /**
     * チャンネルA指定時にチャンネルAのみ処理されるテスト
     */
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

        $this->fakeSpotifyApi([
            [
                'id' => 'spotify_track_a',
                'name' => 'Song A',
                'artists' => [['name' => 'Artist A']],
            ],
        ]);

        $result = $this->service->autoLinkUnlinkedTimestamps(100, null, $channelA->channel_id);

        // チャンネルAのアイテムのみ処理される
        $this->assertEquals(1, $result['processed']);

        // チャンネルBのアイテムはマッピングされていない
        $tsItemB = TsItem::where('text', 'Song B')->first();
        $this->assertDatabaseMissing('timestamp_song_mappings', [
            'normalized_text' => $tsItemB->normalized_text,
        ]);
    }

    /**
     * channelId=null時は全チャンネル処理するテスト
     */
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

        $this->fakeSpotifyApi([
            [
                'id' => 'spotify_track_generic',
                'name' => 'Song',
                'artists' => [['name' => 'Artist']],
            ],
        ]);

        // channelId=null（デフォルト）で全チャンネル処理
        $result = $this->service->autoLinkUnlinkedTimestamps(100, null, null);

        $this->assertEquals(2, $result['processed']);
    }

    /**
     * 対象チャンネルに未紐付けがない場合のテスト
     */
    public function test_channel_filter_with_no_unlinked_items(): void
    {
        $channelA = Channel::factory()->create();
        // チャンネルAにはアイテムなし

        $channelB = Channel::factory()->create();
        $archiveB = Archive::factory()->create(['channel_id' => $channelB->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archiveB->video_id,
            'text' => 'Song B',
            'is_display' => 1,
        ]);

        $this->fakeSpotifyApi();

        // チャンネルAを指定 → 未紐付けなし
        $result = $this->service->autoLinkUnlinkedTimestamps(100, null, $channelA->channel_id);

        $this->assertEquals(0, $result['processed']);
        $this->assertEquals(0, $result['linked']);
        $this->assertEquals(0, $result['failed']);
    }
}

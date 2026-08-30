<?php

namespace Tests\Unit\Services;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\TimestampService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 公開クエリ（TimestampService）で、未レビューの自動紐付け（is_manual=false）の
 * 楽曲マスタ情報が非表示になることを検証する（Issue #675）
 */
class TimestampServiceMappingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private TimestampService $service;

    private Channel $channel;

    private Archive $archive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TimestampService::class);
        $this->channel = Channel::factory()->create();
        $this->archive = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'is_display' => 1,
        ]);
    }

    private function createTsItemWithMapping(bool $isManual, string $status = TimestampSongMapping::STATUS_LINKED, int $tsNum = 0): TsItem
    {
        $tsItem = TsItem::factory()->create([
            'video_id' => $this->archive->video_id,
            'text' => 'テスト曲',
            'ts_text' => gmdate('H:i:s', $tsNum),
            'ts_num' => $tsNum,
            'is_display' => 1,
        ]);
        $song = Song::factory()->create([
            'title' => '確定楽曲名',
            'artist' => '確定アーティスト',
        ]);
        TimestampSongMapping::factory()->withSong($song)->withText($tsItem->text)->create([
            'is_manual' => $isManual,
            'status' => $status,
        ]);

        return $tsItem;
    }

    // --- getTimestampsWithMapping ---

    public function test_get_timestamps_with_mapping_shows_song_for_confirmed_mapping(): void
    {
        $this->createTsItemWithMapping(isManual: true);

        $result = $this->service->getTimestampsWithMapping($this->channel);

        $this->assertNotNull($result['data'][0]['mapping']);
        $this->assertNotNull($result['data'][0]['mapping']['song']);
        $this->assertEquals('確定楽曲名', $result['data'][0]['mapping']['song']['title']);
    }

    public function test_get_timestamps_with_mapping_hides_song_for_unreviewed_auto_link(): void
    {
        $this->createTsItemWithMapping(isManual: false);

        $result = $this->service->getTimestampsWithMapping($this->channel);

        $this->assertNotNull($result['data'][0]['mapping']);
        $this->assertNull($result['data'][0]['mapping']['song']);
    }

    public function test_get_timestamps_with_mapping_hides_song_when_status_not_linked(): void
    {
        // is_manual=true でも status が linked でなければ非表示にする
        $this->createTsItemWithMapping(isManual: true, status: TimestampSongMapping::STATUS_PENDING);

        $result = $this->service->getTimestampsWithMapping($this->channel);

        $this->assertNotNull($result['data'][0]['mapping']);
        $this->assertNull($result['data'][0]['mapping']['song']);
    }

    // --- getRandomTimestamp ---

    public function test_get_random_timestamp_shows_song_for_confirmed_mapping(): void
    {
        $this->createTsItemWithMapping(isManual: true);

        $result = $this->service->getRandomTimestamp($this->channel);

        $this->assertNotNull($result['mapping']);
        $this->assertNotNull($result['mapping']['song']);
        $this->assertEquals('確定楽曲名', $result['mapping']['song']['title']);
    }

    public function test_get_random_timestamp_hides_song_for_unreviewed_auto_link(): void
    {
        $this->createTsItemWithMapping(isManual: false);

        $result = $this->service->getRandomTimestamp($this->channel);

        $this->assertNotNull($result['mapping']);
        $this->assertNull($result['mapping']['song']);
    }

    // --- getNextTimestampInArchive ---

    public function test_get_next_timestamp_in_archive_shows_song_for_confirmed_mapping(): void
    {
        TsItem::factory()->create([
            'video_id' => $this->archive->video_id,
            'text' => '1曲目',
            'ts_text' => '0:00',
            'ts_num' => 0,
            'is_display' => 1,
        ]);
        $this->createTsItemWithMapping(isManual: true, tsNum: 180);

        $result = $this->service->getNextTimestampInArchive($this->channel, $this->archive->video_id, 0);

        $this->assertNotNull($result);
        $this->assertNotNull($result['mapping']['song']);
        $this->assertEquals('確定楽曲名', $result['mapping']['song']['title']);
    }

    public function test_get_next_timestamp_in_archive_hides_song_for_unreviewed_auto_link(): void
    {
        TsItem::factory()->create([
            'video_id' => $this->archive->video_id,
            'text' => '1曲目',
            'ts_text' => '0:00',
            'ts_num' => 0,
            'is_display' => 1,
        ]);
        $this->createTsItemWithMapping(isManual: false, tsNum: 180);

        $result = $this->service->getNextTimestampInArchive($this->channel, $this->archive->video_id, 0);

        $this->assertNotNull($result);
        $this->assertNull($result['mapping']['song']);
    }
}

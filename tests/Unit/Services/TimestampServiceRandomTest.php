<?php

namespace Tests\Unit\Services;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\TimestampService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TimestampServiceRandomTest extends TestCase
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

    private function createTsItem(array $overrides = []): TsItem
    {
        return TsItem::factory()->create(array_merge([
            'video_id' => $this->archive->video_id,
            'is_display' => 1,
        ], $overrides));
    }

    public function test_get_random_timestamp_returns_item(): void
    {
        $this->createTsItem(['text' => 'テスト曲', 'ts_text' => '1:00', 'ts_num' => 60]);

        $result = $this->service->getRandomTimestamp($this->channel);

        $this->assertNotNull($result);
        $this->assertEquals('テスト曲', $result['text']);
        $this->assertEquals('1:00', $result['ts_text']);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('next_ts_num', $result);
    }

    public function test_get_random_timestamp_returns_null_when_no_items(): void
    {
        $result = $this->service->getRandomTimestamp($this->channel);

        $this->assertNull($result);
    }

    public function test_get_random_timestamp_excludes_video_id(): void
    {
        // archive1 のアイテム（除外対象）
        $this->createTsItem(['text' => '除外曲', 'ts_text' => '0:00', 'ts_num' => 0]);

        // archive2 のアイテム
        $archive2 = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'is_display' => 1,
        ]);
        TsItem::factory()->create([
            'video_id' => $archive2->video_id,
            'text' => '別アーカイブ曲',
            'ts_text' => '2:00',
            'ts_num' => 120,
            'is_display' => 1,
        ]);

        $result = $this->service->getRandomTimestamp($this->channel, 50, $this->archive->video_id);

        $this->assertNotNull($result);
        $this->assertEquals($archive2->video_id, $result['video_id']);
    }

    public function test_get_random_timestamp_fallbacks_when_exclude_leaves_no_results(): void
    {
        // アーカイブ1つしかない → excludeVideoIdで除外しても、フォールバックで取得される
        $this->createTsItem(['text' => '唯一の曲', 'ts_text' => '0:00', 'ts_num' => 0]);

        $result = $this->service->getRandomTimestamp($this->channel, 50, $this->archive->video_id);

        $this->assertNotNull($result);
        $this->assertEquals('唯一の曲', $result['text']);
    }

    public function test_get_random_timestamp_excludes_not_song_items(): void
    {
        $tsItem = $this->createTsItem(['text' => '楽曲ではない', 'ts_text' => '0:00', 'ts_num' => 0]);

        // 「楽曲ではない」マーク
        TimestampSongMapping::create([
            'id' => Str::ulid(),
            'normalized_text' => $tsItem->normalized_text,
            'is_not_song' => true,
        ]);

        $result = $this->service->getRandomTimestamp($this->channel);

        $this->assertNull($result);
    }

    public function test_get_random_timestamp_skips_hidden_archives(): void
    {
        $hiddenArchive = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'is_display' => 0,
        ]);
        TsItem::factory()->create([
            'video_id' => $hiddenArchive->video_id,
            'text' => '非表示アーカイブの曲',
            'is_display' => 1,
        ]);

        $result = $this->service->getRandomTimestamp($this->channel);

        $this->assertNull($result);
    }

    public function test_get_random_timestamp_skips_hidden_ts_items(): void
    {
        $this->createTsItem(['text' => '非表示アイテム', 'ts_text' => '0:00', 'ts_num' => 0, 'is_display' => 0]);

        $result = $this->service->getRandomTimestamp($this->channel);

        $this->assertNull($result);
    }

    public function test_get_next_timestamp_in_archive_returns_next(): void
    {
        $this->createTsItem(['text' => '1曲目', 'ts_text' => '0:00', 'ts_num' => 0]);
        $this->createTsItem(['text' => '2曲目', 'ts_text' => '3:00', 'ts_num' => 180]);
        $this->createTsItem(['text' => '3曲目', 'ts_text' => '6:00', 'ts_num' => 360]);

        $result = $this->service->getNextTimestampInArchive($this->channel, $this->archive->video_id, 0);

        $this->assertNotNull($result);
        $this->assertEquals('2曲目', $result['text']);
        $this->assertEquals(180, $result['ts_num']);
    }

    public function test_get_next_timestamp_in_archive_returns_null_at_end(): void
    {
        $this->createTsItem(['text' => '最後の曲', 'ts_text' => '0:00', 'ts_num' => 0]);

        $result = $this->service->getNextTimestampInArchive($this->channel, $this->archive->video_id, 0);

        $this->assertNull($result);
    }

    public function test_get_next_timestamp_in_archive_skips_not_song(): void
    {
        $this->createTsItem(['text' => '1曲目', 'ts_text' => '0:00', 'ts_num' => 0]);
        $notSongItem = $this->createTsItem(['text' => 'MC', 'ts_text' => '3:00', 'ts_num' => 180]);
        $this->createTsItem(['text' => '3曲目', 'ts_text' => '6:00', 'ts_num' => 360]);

        TimestampSongMapping::create([
            'id' => Str::ulid(),
            'normalized_text' => $notSongItem->normalized_text,
            'is_not_song' => true,
        ]);

        $result = $this->service->getNextTimestampInArchive($this->channel, $this->archive->video_id, 0);

        $this->assertNotNull($result);
        $this->assertEquals('3曲目', $result['text']);
        $this->assertEquals(360, $result['ts_num']);
    }
}

<?php

namespace Tests\Unit\Services;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\TsItem;
use App\Services\TimestampService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimestampServiceMinusSearchTest extends TestCase
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

    private function createTsItem(string $text, int $tsNum = 0): TsItem
    {
        return TsItem::factory()->create([
            'video_id' => $this->archive->video_id,
            'text' => $text,
            'ts_text' => gmdate('H:i:s', $tsNum),
            'ts_num' => $tsNum,
            'is_display' => 1,
        ]);
    }

    public function test_minus_search_excludes_matching_timestamps(): void
    {
        $this->createTsItem('夜に駆ける', 0);
        $this->createTsItem('夜空ノムコウ', 60);
        $this->createTsItem('残響散歌', 120);

        $result = $this->service->getTimestampsWithMapping(
            $this->channel,
            search: '夜 -駆ける'
        );

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('夜空ノムコウ', $result['data'][0]['text']);
    }

    public function test_minus_search_only_exclusion_filters_correctly(): void
    {
        $this->createTsItem('夜に駆ける', 0);
        $this->createTsItem('残響散歌', 60);

        $result = $this->service->getTimestampsWithMapping(
            $this->channel,
            search: '-駆ける'
        );

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('残響散歌', $result['data'][0]['text']);
    }

    public function test_fullwidth_minus_works_as_exclusion(): void
    {
        $this->createTsItem('夜に駆ける', 0);
        $this->createTsItem('残響散歌', 60);

        $result = $this->service->getTimestampsWithMapping(
            $this->channel,
            search: '－駆ける'
        );

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('残響散歌', $result['data'][0]['text']);
    }
}

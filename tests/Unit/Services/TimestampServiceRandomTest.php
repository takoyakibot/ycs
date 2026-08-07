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

    public function test_get_random_timestamp_respects_search_filter(): void
    {
        $this->createTsItem(['text' => '夜に駆ける', 'ts_text' => '0:00', 'ts_num' => 0]);
        $this->createTsItem(['text' => 'アイドル', 'ts_text' => '3:00', 'ts_num' => 180]);
        $this->createTsItem(['text' => '怪物', 'ts_text' => '6:00', 'ts_num' => 360]);

        // 何度引いても検索条件に合致する1件しか返らない
        for ($i = 0; $i < 10; $i++) {
            $result = $this->service->getRandomTimestamp($this->channel, 50, null, '夜に駆ける');

            $this->assertNotNull($result);
            $this->assertEquals('夜に駆ける', $result['text']);
        }
    }

    public function test_get_random_timestamp_returns_null_when_search_matches_nothing(): void
    {
        $this->createTsItem(['text' => '夜に駆ける', 'ts_text' => '0:00', 'ts_num' => 0]);

        $result = $this->service->getRandomTimestamp($this->channel, 50, null, '存在しない曲名');

        $this->assertNull($result);
    }

    public function test_get_random_timestamp_search_filter_survives_exclude_fallback(): void
    {
        // 除外対象のアーカイブにしか該当曲がない場合、フォールバックしても
        // 検索条件を外して別の曲を返してはいけない
        $this->createTsItem(['text' => '夜に駆ける', 'ts_text' => '0:00', 'ts_num' => 0]);

        $archive2 = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'is_display' => 1,
        ]);
        TsItem::factory()->create([
            'video_id' => $archive2->video_id,
            'text' => '別アーカイブの別曲',
            'ts_text' => '2:00',
            'ts_num' => 120,
            'is_display' => 1,
        ]);

        $result = $this->service->getRandomTimestamp(
            $this->channel,
            50,
            $this->archive->video_id,
            '夜に駆ける'
        );

        $this->assertNotNull($result);
        $this->assertEquals('夜に駆ける', $result['text']);
    }

    public function test_get_random_timestamp_respects_index_filter(): void
    {
        $this->createTsItem(['text' => 'あいうえお', 'ts_text' => '0:00', 'ts_num' => 0]);
        $this->createTsItem(['text' => 'かきくけこ', 'ts_text' => '3:00', 'ts_num' => 180]);

        for ($i = 0; $i < 10; $i++) {
            $result = $this->service->getRandomTimestamp($this->channel, 50, null, '', 'か');

            $this->assertNotNull($result);
            $this->assertEquals('かきくけこ', $result['text']);
        }
    }

    public function test_get_random_timestamp_page_matches_filtered_list(): void
    {
        // 絞り込みで除外される曲。ソート順で対象曲より前に来るため、
        // ページ計算に混ざると返却される page がずれる
        foreach (['0除外1', '0除外2', '0除外3', '0除外4'] as $index => $text) {
            $this->createTsItem([
                'text' => $text,
                'ts_text' => "{$index}:00",
                'ts_num' => $index * 60,
            ]);
        }
        foreach (['A対象1', 'A対象2', 'A対象3'] as $index => $text) {
            $this->createTsItem([
                'text' => $text,
                'ts_text' => '1'.$index.':00',
                'ts_num' => 600 + $index * 60,
            ]);
        }

        $result = $this->service->getRandomTimestamp($this->channel, 2, null, 'A対象');

        $this->assertNotNull($result);
        $this->assertStringStartsWith('A対象', $result['text']);

        // 絞り込み後は3件しかないため、2ページを超えることはない
        $this->assertLessThanOrEqual(2, $result['page']);

        // 返却された page を同じ条件の一覧に渡すと、その曲が含まれている
        $list = $this->service->getTimestampsWithMapping($this->channel, 2, $result['page'], 'A対象');
        $this->assertContains($result['text'], array_column($list['data'], 'text'));
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

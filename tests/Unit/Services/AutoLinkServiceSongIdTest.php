<?php

namespace Tests\Unit\Services;

use App\Helpers\TextNormalizer;
use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TsItem;
use App\Services\AutoLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoLinkServiceSongIdTest extends TestCase
{
    use RefreshDatabase;

    protected AutoLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AutoLinkService::class);
    }

    /**
     * ts_items.song_idが設定済みのアイテムはgetUnlinkedTextsから除外されること
     */
    public function test_individually_mapped_items_excluded(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC_AUTOLINK']);

        Archive::create([
            'id' => Str::ulid(),
            'video_id' => 'autolink_v1',
            'channel_id' => $channel->channel_id,
            'title' => '歌枠テスト',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        $song = Song::factory()->create(['title' => 'Linked Song']);

        $linkedText = '個別紐付け済みの曲';
        $unlinkedText = '未紐付けの曲';

        // song_idが設定済みのts_item（個別マッピング済み）
        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'autolink_v1',
            'comment_id' => 'autolink_v1',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => $linkedText,
            'normalized_text' => TextNormalizer::normalize($linkedText),
            'is_display' => true,
            'song_id' => $song->id,
        ]);

        // song_idがnullのts_item（未紐付け）
        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'autolink_v1',
            'comment_id' => 'autolink_v1',
            'type' => '1',
            'ts_text' => '2:00',
            'ts_num' => 120,
            'text' => $unlinkedText,
            'normalized_text' => TextNormalizer::normalize($unlinkedText),
            'is_display' => true,
            'song_id' => null,
        ]);

        // autoLinkUnlinkedTimestampsを呼ぶ（getUnlinkedTextsを間接的にテスト）
        $result = $this->service->autoLinkUnlinkedTimestamps(100);

        // 個別紐付け済みのテキストは処理されない
        // 未紐付けのテキストのみが処理対象（1件）
        $this->assertEquals(1, $result['processed']);
    }
}

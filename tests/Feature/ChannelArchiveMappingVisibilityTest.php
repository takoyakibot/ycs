<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 公開アーカイブ一覧APIで、未レビューの自動紐付け（is_manual=false）の
 * 楽曲マスタ情報が非表示になることを検証する（Issue #675）
 */
class ChannelArchiveMappingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function setupChannelWithTimestamp(bool $isManual, string $status = TimestampSongMapping::STATUS_LINKED): Channel
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 1,
        ]);
        $tsItem = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'テスト曲 / テストアーティスト',
            'is_display' => 1,
        ]);
        $song = Song::factory()->create([
            'title' => 'テスト曲',
            'artist' => 'テストアーティスト',
        ]);
        TimestampSongMapping::factory()->withSong($song)->withText($tsItem->text)->create([
            'is_manual' => $isManual,
            'status' => $status,
        ]);

        return $channel;
    }

    public function test_confirmed_mapping_shows_song_info(): void
    {
        $channel = $this->setupChannelWithTimestamp(isManual: true);

        $response = $this->getJson("/api/channels/{$channel->handle}");

        $response->assertOk();
        $tsItems = $response->json('data.0.ts_items_display');
        $this->assertNotNull($tsItems[0]['song']);
        $this->assertEquals('テスト曲', $tsItems[0]['song']['title']);
    }

    public function test_unreviewed_auto_link_hides_song_info(): void
    {
        $channel = $this->setupChannelWithTimestamp(isManual: false);

        $response = $this->getJson("/api/channels/{$channel->handle}");

        $response->assertOk();
        $tsItems = $response->json('data.0.ts_items_display');
        $this->assertNull($tsItems[0]['song']);
    }

    public function test_pending_manual_mapping_hides_song_info(): void
    {
        // is_manual=true だが status が linked でない（レビュー未完了）場合も非表示にする
        $channel = $this->setupChannelWithTimestamp(isManual: true, status: TimestampSongMapping::STATUS_PENDING);

        $response = $this->getJson("/api/channels/{$channel->handle}");

        $response->assertOk();
        $tsItems = $response->json('data.0.ts_items_display');
        $this->assertNull($tsItems[0]['song']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\ChannelStripPattern;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManageStripPatternPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Channel $channel;

    private string $cryptHandle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $this->cryptHandle = Crypt::encryptString($this->channel->handle);
    }

    public function test_preview_returns_empty_when_no_patterns(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/manage/channels/{$this->cryptHandle}/strip-patterns/preview");

        $response->assertOk();
        $response->assertJson(['ts_items' => [], 'songs' => []]);
    }

    public function test_preview_shows_ts_items_with_changes(): void
    {
        $archive = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'is_display' => 1,
        ]);

        $tsItemId = Str::ulid();
        TsItem::create([
            'id' => $tsItemId,
            'video_id' => $archive->video_id,
            'type' => '1',
            'ts_text' => '0:00',
            'ts_num' => 0,
            'text' => '🎵 テスト曲名 ♪',
            'normalized_text' => '🎵 テスト曲名 ♪',
            'is_display' => 1,
            'comment_id' => $archive->video_id,
        ]);

        // normalized_textを強制的に設定（savingイベントをバイパス）
        \DB::table('ts_items')->where('id', $tsItemId)->update([
            'normalized_text' => '🎵 テスト曲名 ♪',
        ]);

        ChannelStripPattern::create([
            'channel_id' => $this->channel->channel_id,
            'pattern' => '🎵',
            'is_regex' => false,
        ]);
        ChannelStripPattern::create([
            'channel_id' => $this->channel->channel_id,
            'pattern' => '♪',
            'is_regex' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/manage/channels/{$this->cryptHandle}/strip-patterns/preview");

        $response->assertOk();
        $tsItems = $response->json('ts_items');
        $this->assertNotEmpty($tsItems);
        $this->assertEquals('🎵 テスト曲名 ♪', $tsItems[0]['text']);
    }

    public function test_preview_excludes_ts_items_without_changes(): void
    {
        $archive = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'is_display' => 1,
        ]);

        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => $archive->video_id,
            'type' => '1',
            'ts_text' => '0:00',
            'ts_num' => 0,
            'text' => 'テスト曲名',
            'is_display' => 1,
            'comment_id' => $archive->video_id,
        ]);

        // パターンはテキストにマッチしない
        ChannelStripPattern::create([
            'channel_id' => $this->channel->channel_id,
            'pattern' => '🎵',
            'is_regex' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/manage/channels/{$this->cryptHandle}/strip-patterns/preview");

        $response->assertOk();
        $this->assertEmpty($response->json('ts_items'));
    }

    public function test_preview_shows_songs_with_pattern_hits(): void
    {
        $archive = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'is_display' => 1,
        ]);

        $tsItem = TsItem::create([
            'id' => Str::ulid(),
            'video_id' => $archive->video_id,
            'type' => '1',
            'ts_text' => '0:00',
            'ts_num' => 0,
            'text' => 'テスト曲名',
            'is_display' => 1,
            'comment_id' => $archive->video_id,
        ]);

        $song = Song::create([
            'id' => Str::ulid(),
            'title' => '【MV】テスト曲名',
            'artist' => 'テストアーティスト',
        ]);

        TimestampSongMapping::create([
            'id' => Str::ulid(),
            'normalized_text' => $tsItem->normalized_text,
            'song_id' => $song->id,
        ]);

        ChannelStripPattern::create([
            'channel_id' => $this->channel->channel_id,
            'pattern' => '/【.*?】/u',
            'is_regex' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/manage/channels/{$this->cryptHandle}/strip-patterns/preview");

        $response->assertOk();
        $songs = $response->json('songs');
        $this->assertNotEmpty($songs);
        $this->assertEquals('テスト曲名', $songs[0]['title_after']);
    }

    public function test_preview_denied_for_unauthorized_user(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)
            ->getJson("/api/manage/channels/{$this->cryptHandle}/strip-patterns/preview");

        $response->assertForbidden();
    }
}

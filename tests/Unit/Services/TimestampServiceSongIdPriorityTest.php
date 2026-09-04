<?php

namespace Tests\Unit\Services;

use App\Helpers\TextNormalizer;
use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\TimestampService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TimestampServiceSongIdPriorityTest extends TestCase
{
    use RefreshDatabase;

    protected TimestampService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TimestampService::class);
    }

    /**
     * ts_items.song_idが設定されている場合、timestamp_song_mappingsのマッピングより優先されること
     */
    public function test_individual_song_id_takes_priority(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC_PRIORITY']);

        Archive::create([
            'id' => Str::ulid(),
            'video_id' => 'priority_v1',
            'channel_id' => $channel->channel_id,
            'title' => '歌枠テスト',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        // マッピング用の楽曲
        $mappingSong = Song::factory()->create([
            'title' => 'Mapping Song Title',
            'artist' => 'Mapping Artist',
        ]);

        // 個別マッピング用の楽曲（こちらが優先される）
        $individualSong = Song::factory()->create([
            'title' => 'Individual Song Title',
            'artist' => 'Individual Artist',
        ]);

        $tsItemText = 'テスト楽曲名';
        $normalizedText = TextNormalizer::normalize($tsItemText);

        // timestamp_song_mappingsにマッピングを作成（確定済み）
        TimestampSongMapping::factory()->withSong($mappingSong)->manual()->create([
            'normalized_text' => $normalizedText,
        ]);

        // ts_itemsにsong_idを個別に設定
        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'priority_v1',
            'comment_id' => 'priority_v1',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => $tsItemText,
            'normalized_text' => $normalizedText,
            'is_display' => true,
            'song_id' => $individualSong->id,
        ]);

        $result = $this->service->getTimestampsWithMapping($channel);

        $this->assertCount(1, $result['data']);

        $item = $result['data'][0];
        $this->assertNotNull($item['mapping']);
        $this->assertNotNull($item['mapping']['song']);
        $this->assertEquals('Individual Song Title', $item['mapping']['song']['title']);
        $this->assertEquals('Individual Artist', $item['mapping']['song']['artist']);
        $this->assertTrue($item['is_individual_mapping']);
    }

    /**
     * マッピングなし + ts_items.song_idありの場合、楽曲情報が返ること
     */
    public function test_individual_song_shown_without_mapping(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC_NO_MAP']);

        Archive::create([
            'id' => Str::ulid(),
            'video_id' => 'nomap_vid1',
            'channel_id' => $channel->channel_id,
            'title' => '歌枠テスト',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        $individualSong = Song::factory()->create([
            'title' => 'Solo Song Title',
            'artist' => 'Solo Artist',
        ]);

        $tsItemText = 'ユニークなテキスト123';
        $normalizedText = TextNormalizer::normalize($tsItemText);

        // timestamp_song_mappingsには何も作成しない

        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'nomap_vid1',
            'comment_id' => 'nomap_vid1',
            'type' => '1',
            'ts_text' => '2:00',
            'ts_num' => 120,
            'text' => $tsItemText,
            'normalized_text' => $normalizedText,
            'is_display' => true,
            'song_id' => $individualSong->id,
        ]);

        $result = $this->service->getTimestampsWithMapping($channel);

        $this->assertCount(1, $result['data']);

        $item = $result['data'][0];
        $this->assertNotNull($item['mapping']);
        $this->assertNotNull($item['mapping']['song']);
        $this->assertEquals('Solo Song Title', $item['mapping']['song']['title']);
        $this->assertTrue($item['is_individual_mapping']);
    }

    /**
     * is_not_song=trueでもts_items.song_idがあれば除外されないこと
     */
    public function test_is_not_song_bypassed_when_individual_song_id_set(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC_BYPASS']);

        Archive::create([
            'id' => Str::ulid(),
            'video_id' => 'bypass_vid1',
            'channel_id' => $channel->channel_id,
            'title' => '歌枠テスト',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        $individualSong = Song::factory()->create([
            'title' => 'Bypass Song',
            'artist' => 'Bypass Artist',
        ]);

        $tsItemText = 'バイパステスト曲';
        $normalizedText = TextNormalizer::normalize($tsItemText);

        // is_not_song=trueのマッピングを作成（通常なら除外される）
        TimestampSongMapping::factory()->notSong()->create([
            'normalized_text' => $normalizedText,
        ]);

        // しかしts_items.song_idも設定されている
        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'bypass_vid1',
            'comment_id' => 'bypass_vid1',
            'type' => '1',
            'ts_text' => '3:00',
            'ts_num' => 180,
            'text' => $tsItemText,
            'normalized_text' => $normalizedText,
            'is_display' => true,
            'song_id' => $individualSong->id,
        ]);

        $result = $this->service->getTimestampsWithMapping($channel);

        // is_not_songでも個別マッピングがあるため除外されない
        $this->assertCount(1, $result['data']);

        $item = $result['data'][0];
        $this->assertNotNull($item['mapping']);
        $this->assertNotNull($item['mapping']['song']);
        $this->assertEquals('Bypass Song', $item['mapping']['song']['title']);
        $this->assertTrue($item['is_individual_mapping']);
    }
}

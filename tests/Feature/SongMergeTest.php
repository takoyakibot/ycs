<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\SongTag;
use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SongMergeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    public function test_find_duplicates_returns_groups(): void
    {
        // 同じnormalized_titleを持つ2曲を作成
        Song::factory()->create(['title' => 'Test Song', 'artist' => 'Artist A']);
        Song::factory()->create(['title' => 'test song', 'artist' => 'artist a']);

        // 別の曲（重複なし）
        Song::factory()->create(['title' => 'Unique Song', 'artist' => 'Artist B']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/duplicates');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertCount(2, $data[0]['songs']);
    }

    public function test_find_duplicates_with_search(): void
    {
        Song::factory()->create(['title' => 'Test Song', 'artist' => 'Artist']);
        Song::factory()->create(['title' => 'test song', 'artist' => 'artist']);
        Song::factory()->create(['title' => 'Other Song', 'artist' => 'Other']);
        Song::factory()->create(['title' => 'other song', 'artist' => 'other']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/duplicates?search=Test');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
    }

    public function test_merge_songs_happy_path(): void
    {
        $targetSong = Song::factory()->create(['title' => 'Test Song', 'artist' => 'Artist']);
        $sourceSong = Song::factory()->create(['title' => 'test song', 'artist' => 'artist']);

        // sourceSongにマッピングを紐付け
        TimestampSongMapping::factory()
            ->withSong($sourceSong)
            ->withText('test text 1')
            ->create();
        TimestampSongMapping::factory()
            ->withSong($sourceSong)
            ->withText('test text 2')
            ->create();

        // sourceSongに個別ts_itemを紐付け
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'song_id' => $sourceSong->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/songs/merge', [
                'source_song_id' => $sourceSong->id,
                'target_song_id' => $targetSong->id,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'affected_mappings' => 2,
            'affected_ts_items' => 1,
        ]);

        // sourceSongが削除されていること
        $this->assertDatabaseMissing('songs', ['id' => $sourceSong->id]);

        // マッピングがtargetSongに移行されていること
        $this->assertEquals(2, TimestampSongMapping::where('song_id', $targetSong->id)->count());

        // ts_itemがtargetSongに移行されていること
        $this->assertEquals(1, TsItem::where('song_id', $targetSong->id)->count());

        // ログが記録されていること
        $this->assertDatabaseHas('normalization_logs', [
            'action' => 'merge_song',
            'target_id' => $targetSong->id,
        ]);
    }

    public function test_merge_songs_with_no_references(): void
    {
        $targetSong = Song::factory()->create(['title' => 'Target']);
        $sourceSong = Song::factory()->create(['title' => 'Source']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/songs/merge', [
                'source_song_id' => $sourceSong->id,
                'target_song_id' => $targetSong->id,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'affected_mappings' => 0,
            'affected_ts_items' => 0,
            'affected_decompositions' => 0,
        ]);

        $this->assertDatabaseMissing('songs', ['id' => $sourceSong->id]);
    }

    public function test_merge_songs_rejects_same_id(): void
    {
        $song = Song::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson('/api/songs/merge', [
                'source_song_id' => $song->id,
                'target_song_id' => $song->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['target_song_id']);
    }

    public function test_merge_songs_rejects_nonexistent_id(): void
    {
        $song = Song::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson('/api/songs/merge', [
                'source_song_id' => 'nonexistent-id',
                'target_song_id' => $song->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['source_song_id']);
    }

    public function test_find_duplicates_returns_empty_when_no_duplicates(): void
    {
        Song::factory()->create(['title' => 'Song A']);
        Song::factory()->create(['title' => 'Song B']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/duplicates');

        $response->assertStatus(200)
            ->assertJsonCount(0);
    }

    public function test_search_songs_for_merge_returns_partial_matches(): void
    {
        Song::factory()->create(['title' => 'Yoru ni Kakeru', 'artist' => 'YOASOBI']);
        Song::factory()->create(['title' => 'Yoru ni Kakeru / YOASOBI', 'artist' => '']);
        Song::factory()->create(['title' => 'Unrelated Song', 'artist' => 'Other']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/search-for-merge?search=Yoru ni Kakeru');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(2, $data);
    }

    public function test_search_songs_for_merge_empty_search_returns_empty(): void
    {
        Song::factory()->create(['title' => 'Test']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/search-for-merge?search=');

        $response->assertStatus(200)
            ->assertJsonCount(0);
    }

    public function test_merge_songs_handles_multiple_mappings(): void
    {
        $targetSong = Song::factory()->create(['title' => 'Target Song', 'artist' => 'Artist']);
        $sourceSong = Song::factory()->create(['title' => 'Source Song', 'artist' => 'Artist']);

        // targetにマッピングを作成
        TimestampSongMapping::factory()
            ->withSong($targetSong)
            ->withText('target text')
            ->create();

        // sourceにマッピングを複数作成
        TimestampSongMapping::factory()
            ->withSong($sourceSong)
            ->withText('source text 1')
            ->create();
        TimestampSongMapping::factory()
            ->withSong($sourceSong)
            ->withText('source text 2')
            ->create();

        $response = $this->actingAs($this->user)
            ->postJson('/api/songs/merge', [
                'source_song_id' => $sourceSong->id,
                'target_song_id' => $targetSong->id,
            ]);

        $response->assertStatus(200);

        // sourceSongが削除されていること
        $this->assertDatabaseMissing('songs', ['id' => $sourceSong->id]);

        // sourceのマッピングがtargetに付け替えられていること
        $targetMappings = TimestampSongMapping::where('song_id', $targetSong->id)->get();
        $this->assertEquals(3, $targetMappings->count());
        $this->assertTrue($targetMappings->pluck('normalized_text')->contains('target text'));
        $this->assertTrue($targetMappings->pluck('normalized_text')->contains('source text 1'));
        $this->assertTrue($targetMappings->pluck('normalized_text')->contains('source text 2'));
    }

    public function test_merge_songs_transfers_decompositions(): void
    {
        $targetSong = Song::factory()->create(['title' => 'Target Song', 'artist' => 'Artist']);
        $sourceSong = Song::factory()->create(['title' => 'Source Song', 'artist' => 'Artist']);

        $decomposition = TimestampDecomposition::create([
            'normalized_text' => 'source song - artist',
            'original_text' => 'Source Song - Artist',
            'parts' => ['Source Song', 'Artist'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_AUTO_MATCHED,
            'song_id' => $sourceSong->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/songs/merge', [
                'source_song_id' => $sourceSong->id,
                'target_song_id' => $targetSong->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['affected_decompositions' => 1]);

        $decomposition->refresh();
        $this->assertEquals($targetSong->id, $decomposition->song_id);
    }

    public function test_merge_songs_migrates_tags(): void
    {
        $targetSong = Song::factory()->create(['title' => 'Target', 'artist' => 'Artist']);
        $sourceSong = Song::factory()->create(['title' => 'Source', 'artist' => 'Artist']);

        SongTag::factory()->create(['song_id' => $targetSong->id, 'value' => 'BBB']);
        SongTag::factory()->create(['song_id' => $sourceSong->id, 'value' => 'AAA']);
        SongTag::factory()->create(['song_id' => $sourceSong->id, 'value' => 'BBB']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/songs/merge', [
                'source_song_id' => $sourceSong->id,
                'target_song_id' => $targetSong->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['migrated_tags' => 1]);

        $targetTags = SongTag::where('song_id', $targetSong->id)->pluck('value')->sort()->values()->toArray();
        $this->assertEquals(['AAA', 'BBB'], $targetTags);
    }

    public function test_merge_songs_with_no_tags(): void
    {
        $targetSong = Song::factory()->create(['title' => 'Target']);
        $sourceSong = Song::factory()->create(['title' => 'Source']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/songs/merge', [
                'source_song_id' => $sourceSong->id,
                'target_song_id' => $targetSong->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['migrated_tags' => 0]);
    }

    public function test_delete_song_clears_ts_item_song_id(): void
    {
        $song = Song::factory()->create(['title' => 'Delete Me', 'artist' => 'Artist']);

        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        $tsItem = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'song_id' => $song->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/songs/{$song->id}");

        $response->assertStatus(200);

        // songが削除されていること
        $this->assertDatabaseMissing('songs', ['id' => $song->id]);

        // ts_item.song_idがnullにクリアされていること
        $tsItem->refresh();
        $this->assertNull($tsItem->song_id);
    }
}

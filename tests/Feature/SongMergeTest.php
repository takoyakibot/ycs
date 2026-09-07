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

    public function test_merge_songs_happy_path(): void
    {
        $targetSong = Song::factory()->create(['title' => 'Test Song', 'artist' => 'Artist']);
        $sourceSong = Song::factory()->create(['title' => 'test song', 'artist' => 'artist']);

        TimestampSongMapping::factory()
            ->withSong($sourceSong)
            ->withText('test text 1')
            ->create();
        TimestampSongMapping::factory()
            ->withSong($sourceSong)
            ->withText('test text 2')
            ->create();

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

        $this->assertDatabaseMissing('songs', ['id' => $sourceSong->id]);
        $this->assertEquals(2, TimestampSongMapping::where('song_id', $targetSong->id)->count());
        $this->assertEquals(1, TsItem::where('song_id', $targetSong->id)->count());
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

    public function test_merge_songs_handles_multiple_mappings(): void
    {
        $targetSong = Song::factory()->create(['title' => 'Target Song', 'artist' => 'Artist']);
        $sourceSong = Song::factory()->create(['title' => 'Source Song', 'artist' => 'Artist']);

        TimestampSongMapping::factory()
            ->withSong($targetSong)
            ->withText('target text')
            ->create();

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

        $this->assertDatabaseMissing('songs', ['id' => $sourceSong->id]);

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

        $this->assertDatabaseMissing('songs', ['id' => $song->id]);

        $tsItem->refresh();
        $this->assertNull($tsItem->song_id);
    }
}

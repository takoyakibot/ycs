<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use App\Models\Archive;
use App\Models\Channel;
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
}

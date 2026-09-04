<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\SongGroupReview;
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
        Song::factory()->create(['title' => 'Test Song', 'artist' => 'Artist A']);
        Song::factory()->create(['title' => 'test song', 'artist' => 'artist a']);
        Song::factory()->create(['title' => 'Unique Song', 'artist' => 'Artist B']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/duplicates');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertCount(2, $data[0]['songs']);
    }

    public function test_find_duplicates_groups_by_title_only(): void
    {
        Song::factory()->create(['title' => 'Lemon', 'artist' => '米津玄師']);
        Song::factory()->create(['title' => 'LEMON', 'artist' => 'Kenshi Yonezu']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/duplicates');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertCount(2, $data[0]['songs']);
        $this->assertArrayHasKey('normalized_artist', $data[0]['songs'][0]);
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

    public function test_find_duplicates_excludes_distinct_groups(): void
    {
        $song1 = Song::factory()->create(['title' => 'Drops', 'artist' => '']);
        $song2 = Song::factory()->create(['title' => 'Drops', 'artist' => '坂本真綾']);

        $sortedIds = [$song1->id, $song2->id];
        sort($sortedIds);
        SongGroupReview::create([
            'normalized_title' => 'drops',
            'song_ids_hash' => SongGroupReview::hashSongIds($sortedIds),
            'song_ids' => $sortedIds,
            'decision' => SongGroupReview::DECISION_DISTINCT,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/duplicates');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(0, $data);
    }

    public function test_find_duplicates_filter_pending(): void
    {
        $song1 = Song::factory()->create(['title' => 'Song A', 'artist' => 'Artist 1']);
        $song2 = Song::factory()->create(['title' => 'song a', 'artist' => 'Artist 2']);
        Song::factory()->create(['title' => 'Song B', 'artist' => 'Artist 3']);
        Song::factory()->create(['title' => 'song b', 'artist' => 'Artist 4']);

        $sortedIds = [$song1->id, $song2->id];
        sort($sortedIds);
        SongGroupReview::create([
            'normalized_title' => 'song a',
            'song_ids_hash' => SongGroupReview::hashSongIds($sortedIds),
            'song_ids' => $sortedIds,
            'decision' => SongGroupReview::DECISION_PENDING,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/duplicates?filter=active');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('song b', $data[0]['normalized_title']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/duplicates?filter=pending');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('song a', $data[0]['normalized_title']);
    }

    public function test_find_duplicates_includes_song_ids_hash(): void
    {
        Song::factory()->create(['title' => 'Test Song', 'artist' => 'A']);
        Song::factory()->create(['title' => 'test song', 'artist' => 'B']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/duplicates');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('song_ids_hash', $data[0]);
        $this->assertNotEmpty($data[0]['song_ids_hash']);
    }

    public function test_find_duplicates_preserves_count_desc_order(): void
    {
        Song::factory()->create(['title' => 'AAA', 'artist' => 'Alpha']);
        Song::factory()->create(['title' => 'aaa', 'artist' => 'Aleph']);

        Song::factory()->create(['title' => 'ZZZ', 'artist' => 'Zebra']);
        Song::factory()->create(['title' => 'zzz', 'artist' => 'Zulu']);
        Song::factory()->create(['title' => 'ZZZ', 'artist' => 'Zone']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/duplicates');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(2, $data);
        $this->assertEquals('zzz', $data[0]['normalized_title']);
        $this->assertEquals('aaa', $data[1]['normalized_title']);
    }

    public function test_find_duplicates_shows_lower_ranked_groups_after_review(): void
    {
        // 55グループ作成（各2曲）
        for ($i = 1; $i <= 55; $i++) {
            $title = sprintf('Song %03d', $i);
            Song::factory()->create(['title' => $title, 'artist' => 'Artist A']);
            Song::factory()->create(['title' => strtolower($title), 'artist' => 'Artist B']);
        }

        // 初回: 50件返る
        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/duplicates');
        $data = $response->json();
        $this->assertCount(50, $data);

        // 10件をdistinctにする
        for ($i = 0; $i < 10; $i++) {
            $group = $data[$i];
            $songIds = array_column($group['songs'], 'id');
            sort($songIds);
            SongGroupReview::create([
                'normalized_title' => $group['normalized_title'],
                'song_ids_hash' => SongGroupReview::hashSongIds($songIds),
                'song_ids' => $songIds,
                'decision' => SongGroupReview::DECISION_DISTINCT,
                'created_by' => $this->user->id,
            ]);
        }

        // 2回目: 依然50件返る（51-55番目が繰り上がる）
        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/duplicates');
        $data = $response->json();
        $this->assertGreaterThanOrEqual(45, count($data));
    }

    public function test_find_duplicates_rejects_invalid_filter(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/duplicates?filter=invalid');

        $response->assertStatus(422);
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

    public function test_search_songs_for_merge_fuzzy_search(): void
    {
        Song::factory()->create(['title' => '夜に駆ける', 'artist' => 'YOASOBI']);
        Song::factory()->create(['title' => '夜に駆ける(cover)', 'artist' => 'Someone']);
        Song::factory()->create(['title' => 'Unrelated', 'artist' => 'Other']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/search-for-merge?search='.urlencode('夜に駆ける'));

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(2, $data);
    }

    public function test_search_songs_for_merge_fuzzy_multiword(): void
    {
        Song::factory()->create(['title' => '夜に駆ける', 'artist' => 'YOASOBI']);
        Song::factory()->create(['title' => '群青', 'artist' => 'YOASOBI']);
        Song::factory()->create(['title' => 'Other', 'artist' => 'Other']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/search-for-merge?search='.urlencode('YOASOBI 群青'));

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('群青', $data[0]['title']);
    }

    public function test_search_songs_for_merge_matches_artist(): void
    {
        Song::factory()->create(['title' => 'Song A', 'artist' => 'YOASOBI']);
        Song::factory()->create(['title' => 'Song B', 'artist' => 'Ado']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/search-for-merge?search=YOASOBI');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('Song A', $data[0]['title']);
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

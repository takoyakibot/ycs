<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\SongGroupReview;
use App\Models\TimestampSongMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SongCleansingTest extends TestCase
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

    public function test_preview_artist_rename_without_conflict(): void
    {
        Song::factory()->create(['title' => 'Yoru ni Kakeru', 'artist' => 'maaya sakamoto']);
        Song::factory()->create(['title' => 'Loop', 'artist' => 'maaya sakamoto']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/cleansing/artist-rename-preview?'.http_build_query([
                'from' => 'maaya sakamoto',
                'to' => '坂本真綾',
            ]));

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals(2, $data['rename_count']);
        $this->assertEquals(0, $data['merge_count']);
        foreach ($data['plan'] as $item) {
            $this->assertEquals('rename', $item['action']);
            $this->assertNull($item['conflict_song_id']);
        }
    }

    public function test_preview_artist_rename_with_conflict(): void
    {
        Song::factory()->create(['title' => 'Yoru ni Kakeru', 'artist' => 'maaya sakamoto']);
        $existing = Song::factory()->create(['title' => 'Yoru ni Kakeru', 'artist' => '坂本真綾']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/cleansing/artist-rename-preview?'.http_build_query([
                'from' => 'maaya sakamoto',
                'to' => '坂本真綾',
            ]));

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals(0, $data['rename_count']);
        $this->assertEquals(1, $data['merge_count']);
        $this->assertEquals($existing->id, $data['plan'][0]['conflict_song_id']);
    }

    public function test_rename_artist_renames_without_conflict(): void
    {
        $song = Song::factory()->create(['title' => 'Loop', 'artist' => 'maaya sakamoto']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/songs/cleansing/artist-rename', [
                'from' => 'maaya sakamoto',
                'to' => '坂本真綾',
            ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data['renamed']);
        $this->assertCount(0, $data['merged']);

        $song->refresh();
        $this->assertEquals('坂本真綾', $song->artist);
    }

    public function test_rename_artist_merges_on_conflict(): void
    {
        $source = Song::factory()->create(['title' => 'Yoru ni Kakeru', 'artist' => 'maaya sakamoto']);
        $target = Song::factory()->create(['title' => 'Yoru ni Kakeru', 'artist' => '坂本真綾']);

        TimestampSongMapping::factory()
            ->withSong($source)
            ->withText('yoru ni kakeru maaya')
            ->create();

        $response = $this->actingAs($this->user)
            ->postJson('/api/songs/cleansing/artist-rename', [
                'from' => 'maaya sakamoto',
                'to' => '坂本真綾',
            ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(0, $data['renamed']);
        $this->assertCount(1, $data['merged']);
        $this->assertEquals(1, $data['merged'][0]['affected_mappings']);

        $this->assertDatabaseMissing('songs', ['id' => $source->id]);
        $this->assertEquals(1, TimestampSongMapping::where('song_id', $target->id)->count());

        $this->assertDatabaseHas('normalization_logs', [
            'action' => 'rename_artist',
        ]);
    }

    public function test_rename_artist_rejects_same_from_and_to(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/songs/cleansing/artist-rename', [
                'from' => 'maaya sakamoto',
                'to' => 'maaya sakamoto',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_find_title_groups_returns_multi_artist_titles(): void
    {
        Song::factory()->create(['title' => '会いたかった', 'artist' => 'A']);
        Song::factory()->create(['title' => '会いたかった', 'artist' => 'B']);
        Song::factory()->create(['title' => 'Unique Title', 'artist' => 'C']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/cleansing/title-groups');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertCount(2, $data[0]['songs']);
    }

    public function test_review_title_group_as_distinct_hides_it_from_active_filter(): void
    {
        $songA = Song::factory()->create(['title' => '会いたかった', 'artist' => 'A']);
        $songB = Song::factory()->create(['title' => '会いたかった', 'artist' => 'B']);

        $reviewResponse = $this->actingAs($this->user)
            ->postJson('/api/songs/cleansing/title-groups/review', [
                'normalized_title' => $songA->normalized_title,
                'song_ids' => [$songA->id, $songB->id],
                'decision' => 'distinct',
            ]);

        $reviewResponse->assertStatus(200);

        $activeResponse = $this->actingAs($this->user)
            ->getJson('/api/songs/cleansing/title-groups?filter=active');
        $activeResponse->assertStatus(200)->assertJsonCount(0);

        $this->assertDatabaseHas('normalization_logs', [
            'action' => 'review_song_group',
        ]);
    }

    public function test_review_title_group_as_pending_moves_it_to_pending_filter(): void
    {
        $songA = Song::factory()->create(['title' => '会いたかった', 'artist' => 'A']);
        $songB = Song::factory()->create(['title' => '会いたかった', 'artist' => 'B']);

        $this->actingAs($this->user)
            ->postJson('/api/songs/cleansing/title-groups/review', [
                'normalized_title' => $songA->normalized_title,
                'song_ids' => [$songA->id, $songB->id],
                'decision' => 'pending',
            ])->assertStatus(200);

        $this->actingAs($this->user)
            ->getJson('/api/songs/cleansing/title-groups?filter=active')
            ->assertStatus(200)->assertJsonCount(0);

        $this->actingAs($this->user)
            ->getJson('/api/songs/cleansing/title-groups?filter=pending')
            ->assertStatus(200)->assertJsonCount(1);
    }

    public function test_review_title_group_rejects_single_song(): void
    {
        $song = Song::factory()->create(['title' => 'Solo', 'artist' => 'A']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/songs/cleansing/title-groups/review', [
                'normalized_title' => $song->normalized_title,
                'song_ids' => [$song->id],
                'decision' => 'distinct',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['song_ids']);
    }

    public function test_find_title_groups_ordered_by_artist_count_desc(): void
    {
        Song::factory()->create(['title' => 'ソングA', 'artist' => 'X']);
        Song::factory()->create(['title' => 'ソングA', 'artist' => 'Y']);

        Song::factory()->create(['title' => 'ソングB', 'artist' => 'P']);
        Song::factory()->create(['title' => 'ソングB', 'artist' => 'Q']);
        Song::factory()->create(['title' => 'ソングB', 'artist' => 'R']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/cleansing/title-groups');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(2, $data);
        $this->assertEquals('ソングB', $data[0]['songs'][0]['title']);
        $this->assertEquals('ソングA', $data[1]['songs'][0]['title']);
    }

    public function test_find_title_groups_reviewed_groups_do_not_shrink_result(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            Song::factory()->create(['title' => "グループ{$i}", 'artist' => 'A']);
            Song::factory()->create(['title' => "グループ{$i}", 'artist' => 'B']);
        }

        $songA = Song::where('title', 'グループ1')->where('artist', 'A')->first();
        $songB = Song::where('title', 'グループ1')->where('artist', 'B')->first();
        $sortedIds = collect([$songA->id, $songB->id])->sort()->values()->all();

        SongGroupReview::create([
            'normalized_title' => $songA->normalized_title,
            'song_ids_hash' => SongGroupReview::hashSongIds($sortedIds),
            'song_ids' => $sortedIds,
            'decision' => SongGroupReview::DECISION_DISTINCT,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/cleansing/title-groups?filter=active');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(2, $data);
    }
}

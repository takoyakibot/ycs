<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\SongGroupReview;
use App\Models\TimestampSongMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_find_title_groups_ordered_by_normalized_title(): void
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
        $this->assertEquals('ソングA', $data[0]['songs'][0]['title']);
        $this->assertEquals('ソングB', $data[1]['songs'][0]['title']);
    }

    public function test_find_title_groups_excludes_empty_normalized_title(): void
    {
        Song::factory()->create(['title' => '///', 'artist' => 'A', 'normalized_title' => '', 'normalized_artist' => 'a']);
        Song::factory()->create(['title' => '---', 'artist' => 'B', 'normalized_title' => '', 'normalized_artist' => 'b']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/cleansing/title-groups');

        $response->assertStatus(200)
            ->assertJsonCount(0);
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

    public function test_artists_with_count_returns_artist_names_and_counts(): void
    {
        Song::factory()->create(['title' => 'Song A', 'artist' => 'Alpha']);
        Song::factory()->create(['title' => 'Song B', 'artist' => 'Alpha']);
        Song::factory()->create(['title' => 'Song C', 'artist' => 'Beta']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/artists-with-count');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(2, $data);

        $alpha = collect($data)->firstWhere('name', 'Alpha');
        $beta = collect($data)->firstWhere('name', 'Beta');
        $this->assertEquals(2, $alpha['count']);
        $this->assertEquals(1, $beta['count']);
    }

    public function test_artists_with_count_returns_empty_for_no_songs(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/artists-with-count');

        $response->assertStatus(200)
            ->assertJsonCount(0);
    }

    public function test_artists_with_count_excludes_empty_artist(): void
    {
        Song::factory()->create(['title' => 'Song A', 'artist' => 'Alpha']);
        Song::factory()->create(['title' => 'Song C', 'artist' => '']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/artists-with-count');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('Alpha', $data[0]['name']);
    }

    public function test_songs_by_artist_returns_songs_for_given_artist(): void
    {
        Song::factory()->create(['title' => 'Song A', 'artist' => 'Alpha']);
        Song::factory()->create(['title' => 'Song B', 'artist' => 'Alpha']);
        Song::factory()->create(['title' => 'Song C', 'artist' => 'Beta']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/by-artist?artist=Alpha');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(2, $data);
        $this->assertEquals('Song A', $data[0]['title']);
        $this->assertEquals('Song B', $data[1]['title']);
    }

    public function test_preview_artist_rename_ignores_surrounding_spaces_in_stored_artist(): void
    {
        // Song 保存時の trim をバイパスしてレガシーデータを再現する
        $s1 = Song::factory()->create(['title' => 'Darling', 'artist' => 'Kana Nishino']);
        DB::table('songs')->where('id', $s1->id)->update(['artist' => ' Kana Nishino']);
        $s2 = Song::factory()->create(['title' => 'Best Friend', 'artist' => 'Kana Nishino']);
        DB::table('songs')->where('id', $s2->id)->update(['artist' => 'Kana Nishino ']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/cleansing/artist-rename-preview?'.http_build_query([
                'from' => ' Kana Nishino',
                'to' => '西野カナ',
            ]));

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('rename_count'));
    }

    public function test_rename_artist_ignores_surrounding_spaces_in_stored_artist(): void
    {
        $song = Song::factory()->create(['title' => 'Darling', 'artist' => 'Kana Nishino']);
        DB::table('songs')->where('id', $song->id)->update(['artist' => ' Kana Nishino']);

        $this->actingAs($this->user)
            ->postJson('/api/songs/cleansing/artist-rename', [
                'from' => ' Kana Nishino',
                'to' => '西野カナ',
            ])
            ->assertStatus(200);

        $this->assertEquals('西野カナ', $song->fresh()->artist);
    }

    public function test_rename_artist_merges_into_target_with_surrounding_spaces(): void
    {
        $source = Song::factory()->create(['title' => 'Darling', 'artist' => 'Kana Nishino']);
        $target = Song::factory()->create(['title' => 'Darling', 'artist' => '西野カナ']);
        DB::table('songs')->where('id', $target->id)->update(['artist' => ' 西野カナ']);

        $this->actingAs($this->user)
            ->postJson('/api/songs/cleansing/artist-rename', [
                'from' => 'Kana Nishino',
                'to' => '西野カナ',
            ])
            ->assertStatus(200)
            ->assertJsonCount(1, 'merged');

        $this->assertNull(Song::find($source->id));
        $this->assertNotNull(Song::find($target->id));
    }

    public function test_artists_with_count_groups_artist_ignoring_surrounding_spaces(): void
    {
        Song::factory()->create(['title' => 'Song A', 'artist' => 'Alpha']);
        $s2 = Song::factory()->create(['title' => 'Song B', 'artist' => 'Alpha2']);
        DB::table('songs')->where('id', $s2->id)->update(['artist' => ' Alpha']);
        $s3 = Song::factory()->create(['title' => 'Song C', 'artist' => 'placeholder']);
        DB::table('songs')->where('id', $s3->id)->update(['artist' => '  ']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/artists-with-count');

        $response->assertStatus(200);
        $this->assertEquals([['name' => 'Alpha', 'count' => 2]], $response->json());
    }

    public function test_songs_by_artist_ignores_surrounding_spaces_in_stored_artist(): void
    {
        Song::factory()->create(['title' => 'Song A', 'artist' => 'Alpha']);
        $s2 = Song::factory()->create(['title' => 'Song B', 'artist' => 'Alpha2']);
        DB::table('songs')->where('id', $s2->id)->update(['artist' => ' Alpha']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/songs/by-artist?artist='.urlencode(' Alpha'));

        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }

    public function test_rename_artist_merges_same_title_variants_with_and_without_spaces(): void
    {
        $first = Song::factory()->create(['title' => 'Darling', 'artist' => 'Kana Nishino']);
        $second = Song::factory()->create(['title' => 'Darling', 'artist' => 'Kana Nishino2']);
        DB::table('songs')->where('id', $second->id)->update(['artist' => ' Kana Nishino']);
        $params = ['from' => 'Kana Nishino', 'to' => '西野カナ'];

        $preview = $this->actingAs($this->user)
            ->getJson('/api/songs/cleansing/artist-rename-preview?'.http_build_query($params))
            ->assertStatus(200)
            ->json();

        $result = $this->actingAs($this->user)
            ->postJson('/api/songs/cleansing/artist-rename', $params)
            ->assertStatus(200)
            ->json();

        $this->assertEquals(1, $preview['rename_count']);
        $this->assertEquals(1, $preview['merge_count']);
        $this->assertCount(1, $result['renamed']);
        $this->assertCount(1, $result['merged']);

        // プレビューで示したリネーム側の曲が、実行後も残る
        $renamedId = collect($preview['plan'])->firstWhere('action', 'rename')['song_id'];
        $this->assertEquals($renamedId, $result['renamed'][0]['song_id']);
        $this->assertEquals('西野カナ', Song::find($renamedId)->artist);
        $this->assertEquals(1, Song::whereIn('id', [$first->id, $second->id])->count());
    }

    public function test_rename_artist_merges_into_target_whose_title_matches_only_by_db_collation(): void
    {
        // 本番の「もっと…」と「もっと...」のように、DB の照合順序では同じ title だが normalized_title が異なるケース。
        // SQLite は BINARY 比較のため、title は同じ文字列にして normalized_title だけをずらして再現する
        $source = Song::factory()->create(['title' => 'もっと…', 'artist' => 'Kana Nishino']);
        $target = Song::factory()->create(['title' => 'もっと…', 'artist' => '西野カナ']);
        DB::table('songs')->where('id', $target->id)->update(['normalized_title' => 'もっと...']);
        $params = ['from' => 'Kana Nishino', 'to' => '西野カナ'];

        $this->actingAs($this->user)
            ->getJson('/api/songs/cleansing/artist-rename-preview?'.http_build_query($params))
            ->assertStatus(200)
            ->assertJsonPath('merge_count', 1)
            ->assertJsonPath('plan.0.conflict_song_id', $target->id);

        $this->actingAs($this->user)
            ->postJson('/api/songs/cleansing/artist-rename', $params)
            ->assertStatus(200)
            ->assertJsonCount(1, 'merged');

        $this->assertNull(Song::find($source->id));
        $this->assertNotNull(Song::find($target->id));
    }

    public function test_rename_artist_prefers_normalized_title_match_as_merge_target(): void
    {
        $source = Song::factory()->create(['title' => 'Song', 'artist' => 'A']);
        $byTitle = Song::factory()->create(['title' => 'Song', 'artist' => 'B']);
        DB::table('songs')->where('id', $byTitle->id)->update(['normalized_title' => 'song-other']);
        DB::table('songs')->where('id', $source->id)->update(['normalized_title' => 'song']);
        $byNormalized = Song::factory()->create(['title' => 'SONG', 'artist' => 'B']);
        DB::table('songs')->where('id', $byNormalized->id)->update(['normalized_title' => 'song']);

        $this->actingAs($this->user)
            ->postJson('/api/songs/cleansing/artist-rename', ['from' => 'A', 'to' => 'B'])
            ->assertStatus(200)
            ->assertJsonPath('merged.0.target_song_id', $byNormalized->id);
    }

    public function test_rename_artist_returns_409_instead_of_500_on_unique_violation(): void
    {
        $this->mock(\App\Services\SongCleansingService::class, function ($mock) {
            $mock->shouldReceive('executeArtistRename')->andThrow(new \Illuminate\Database\UniqueConstraintViolationException(
                'mysql', 'update songs', [], new \Exception('Duplicate entry')
            ));
        });

        $this->actingAs($this->user)
            ->postJson('/api/songs/cleansing/artist-rename', ['from' => 'A', 'to' => 'B'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '統合'));
    }
}

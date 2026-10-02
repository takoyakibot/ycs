<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\SongTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 楽曲マスタの検索で、タグを曲名・アーティストと同列の検索対象にする（#1009）
 */
class SongTagSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email_verified_at' => now()]);
    }

    private function songWithTags(string $title, string $artist, array $tags): Song
    {
        $song = Song::factory()->create(['title' => $title, 'artist' => $artist]);
        foreach ($tags as $tag) {
            SongTag::create(['song_id' => $song->id, 'value' => $tag]);
        }

        return $song;
    }

    private function fetchSongTitles(string $search, string $mode = 'fuzzy'): array
    {
        return collect($this->actingAs($this->user)
            ->getJson(route('songs.fetchSongs', ['search' => $search, 'search_mode' => $mode]))
            ->assertOk()
            ->json('data'))->pluck('title')->sort()->values()->all();
    }

    public function test_saving_tag_sets_normalized_value(): void
    {
        $song = Song::factory()->create(['artist' => 'A']);
        $tag = SongTag::create(['song_id' => $song->id, 'value' => 'ＨＡＴＳＵＮＥ　ＭＩＫＵ']);

        $this->assertSame('hatsune miku', $tag->normalized_value);

        $tag->update(['value' => '初音ミク']);
        $this->assertSame('初音ミク', $tag->fresh()->normalized_value);
    }

    public function test_keywords_can_span_title_and_tag(): void
    {
        $this->songWithTags('ハッピーシンセサイザ', 'EasyPop', ['初音ミク']);
        $this->songWithTags('ハッピーシンセサイザ', '別の人', []);

        $this->assertSame(['ハッピーシンセサイザ'], $this->fetchSongTitles('ハッピー 初音ミク'));
        $this->assertSame(['ハッピーシンセサイザ'], $this->fetchSongTitles('ハッピー 初音ミク', 'exact'));
    }

    public function test_exclusion_applies_to_tags(): void
    {
        $this->songWithTags('曲A', 'EasyPop', ['初音ミク']);
        $this->songWithTags('曲B', 'EasyPop', ['鏡音リン']);

        $this->assertSame(['曲B'], $this->fetchSongTitles('EasyPop -初音ミク'));
        $this->assertSame(['曲B'], $this->fetchSongTitles('EasyPop -"初音ミク"'));
    }

    public function test_exact_term_matches_tag(): void
    {
        $this->songWithTags('曲A', 'EasyPop', ['初音ミク']);
        $this->songWithTags('曲B', 'EasyPop', ['初音ミク V3']);

        $this->assertSame(['曲A'], $this->fetchSongTitles('"初音ミク"'));
    }

    public function test_fuzzy_search_matches_normalized_tag(): void
    {
        $this->songWithTags('曲A', 'Someone', ['ＨＡＴＳＵＮＥ ＭＩＫＵ']);

        $this->assertSame(['曲A'], $this->fetchSongTitles('hatsune'));
        // あいまい検索ではない場合は生の値と照合する
        $this->assertSame([], $this->fetchSongTitles('hatsune', 'exact'));
    }

    public function test_tag_match_does_not_duplicate_rows(): void
    {
        $this->songWithTags('曲A', 'EasyPop', ['初音ミク', '初音ミクV4X']);

        $response = $this->actingAs($this->user)
            ->getJson(route('songs.fetchSongs', ['search' => '初音ミク']))
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    public function test_search_for_merge_includes_tags(): void
    {
        $this->songWithTags('ハッピーシンセサイザ', 'EasyPop', ['初音ミク']);
        $this->songWithTags('別の曲', 'EasyPop', ['初音ミク']);
        $this->songWithTags('ハッピーシンセサイザ', 'Other', []);

        $titles = collect($this->actingAs($this->user)
            ->getJson('/api/songs/search-for-merge?search='.urlencode('ハッピー 初音ミク'))
            ->assertOk()
            ->json())->pluck('title')->all();

        $this->assertSame(['ハッピーシンセサイザ'], $titles);

        $titles = collect($this->actingAs($this->user)
            ->getJson('/api/songs/search-for-merge?search='.urlencode('EasyPop -初音ミク'))
            ->assertOk()
            ->json())->pluck('title')->all();
        $this->assertSame([], $titles);
    }

    public function test_candidates_include_tag_matches(): void
    {
        $this->songWithTags('ハッピーシンセサイザ', 'EasyPop', ['初音ミク']);
        $this->songWithTags('ハッピーシンセサイザ', 'Other', []);

        $songs = $this->actingAs($this->user)
            ->getJson('/api/songs/candidates?'.http_build_query(['text' => 'ハッピーシンセサイザ / 初音ミク']))
            ->assertOk()
            ->json('songs');

        $this->assertSame(['EasyPop'], collect($songs)->pluck('artist')->all());
    }

    public function test_symbol_only_search_does_not_return_all_songs(): void
    {
        $this->songWithTags('曲A', 'EasyPop', ['初音ミク']);

        $this->assertSame([], $this->fetchSongTitles('♪'));
    }

    public function test_exact_mode_exclusion_applies_to_tags(): void
    {
        $this->songWithTags('曲A', 'EasyPop', ['初音ミク']);
        $this->songWithTags('曲B', 'EasyPop', ['鏡音リン']);

        $this->assertSame(['曲B'], $this->fetchSongTitles('EasyPop -"初音ミク"', 'exact'));
    }

    public function test_sync_artist_tags_updates_normalized_value(): void
    {
        $song = Song::factory()->create(['title' => '曲A', 'artist' => 'Kana Nishino']);

        $song->syncArtistTags('Kana Nishino', '西野カナ');

        $this->assertSame(['西野カナ'], $song->tags()->pluck('normalized_value')->all());
    }

    public function test_tag_without_normalized_value_does_not_break_fuzzy_search(): void
    {
        $song = $this->songWithTags('曲A', 'EasyPop', ['初音ミク']);
        \Illuminate\Support\Facades\DB::table('song_tags')->where('song_id', $song->id)->update(['normalized_value' => null]);

        $this->assertSame(['曲A'], $this->fetchSongTitles('曲A'));
        $this->assertSame([], $this->fetchSongTitles('初音ミク'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SongAutoTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_tags_are_auto_created_on_song_create(): void
    {
        $song = Song::create([
            'id' => (string) Str::ulid(),
            'title' => 'テスト曲',
            'artist' => 'アーティストA / アーティストB',
        ]);

        $tags = $song->tags()->pluck('value')->sort()->values()->all();
        $this->assertSame(['アーティストA', 'アーティストB'], $tags);
    }

    public function test_no_tags_created_for_empty_artist(): void
    {
        $song = Song::create([
            'id' => (string) Str::ulid(),
            'title' => 'テスト曲',
            'artist' => '',
        ]);

        $this->assertCount(0, $song->tags);
    }

    public function test_no_tags_created_when_artist_is_null_via_factory(): void
    {
        $song = Song::factory()->create(['artist' => '']);

        $this->assertCount(0, $song->tags);
    }

    public function test_single_artist_creates_single_tag(): void
    {
        $song = Song::create([
            'id' => (string) Str::ulid(),
            'title' => 'テスト曲',
            'artist' => 'ソロアーティスト',
        ]);

        $tags = $song->tags()->pluck('value')->all();
        $this->assertSame(['ソロアーティスト'], $tags);
    }

    public function test_store_song_does_not_duplicate_auto_tags(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/api/songs', [
            'title' => 'テスト曲',
            'artist' => 'A / B',
            'tags' => ['A', 'B'],
            'force_create' => true,
        ]);

        $response->assertSuccessful();
        $song = Song::where('title', 'テスト曲')->first();
        $tags = $song->tags()->pluck('value')->sort()->values()->all();
        $this->assertSame(['A', 'B'], $tags);
    }

    public function test_store_song_merges_manual_and_auto_tags(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/api/songs', [
            'title' => 'テスト曲2',
            'artist' => 'A / B',
            'tags' => ['C'],
            'force_create' => true,
        ]);

        $response->assertSuccessful();
        $song = Song::where('title', 'テスト曲2')->first();
        $tags = $song->tags()->pluck('value')->sort()->values()->all();
        $this->assertSame(['A', 'B', 'C'], $tags);
    }
}

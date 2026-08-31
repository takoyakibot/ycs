<?php

namespace Tests\Unit\Models;

use App\Models\Song;
use App\Models\SongTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SongTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_song_has_many_tags(): void
    {
        $song = Song::factory()->create();
        SongTag::factory()->create(['song_id' => $song->id, 'value' => 'バルーン']);
        SongTag::factory()->create(['song_id' => $song->id, 'value' => 'ボカロ']);

        $this->assertCount(2, $song->tags);
        $this->assertEquals('バルーン', $song->tags[0]->value);
    }

    public function test_tag_belongs_to_song(): void
    {
        $song = Song::factory()->create();
        $tag = SongTag::factory()->create(['song_id' => $song->id, 'value' => 'テスト']);

        $this->assertEquals($song->id, $tag->song->id);
    }

    public function test_tags_are_deleted_when_song_is_deleted(): void
    {
        $song = Song::factory()->create();
        SongTag::factory()->create(['song_id' => $song->id, 'value' => 'タグ1']);
        SongTag::factory()->create(['song_id' => $song->id, 'value' => 'タグ2']);

        $song->delete();

        $this->assertDatabaseCount('song_tags', 0);
    }

    public function test_ulid_is_auto_generated(): void
    {
        $song = Song::factory()->create();
        $tag = SongTag::create(['song_id' => $song->id, 'value' => 'テスト']);

        $this->assertNotNull($tag->id);
        $this->assertEquals(26, strlen($tag->id));
    }

    public function test_same_value_tags_allowed_on_same_song(): void
    {
        $song = Song::factory()->create();
        SongTag::factory()->create(['song_id' => $song->id, 'value' => 'AAA']);
        SongTag::factory()->create(['song_id' => $song->id, 'value' => 'AAA']);

        $this->assertCount(2, $song->tags);
    }
}

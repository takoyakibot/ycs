<?php

namespace Tests\Feature;

use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SongArtistTrimTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_trims_artist(): void
    {
        $song = Song::factory()->create([
            'title' => 'テスト曲',
            'artist' => '  テストアーティスト  ',
        ]);

        $this->assertSame('テストアーティスト', $song->artist);
    }

    public function test_saving_trims_title(): void
    {
        $song = Song::factory()->create([
            'title' => '  テスト曲  ',
            'artist' => 'テストアーティスト',
        ]);

        $this->assertSame('テスト曲', $song->title);
    }

    public function test_update_trims_artist(): void
    {
        $song = Song::factory()->create([
            'title' => 'テスト曲',
            'artist' => 'テスト',
        ]);

        $song->update(['artist' => ' 新アーティスト ']);
        $song->refresh();

        $this->assertSame('新アーティスト', $song->artist);
    }

    public function test_empty_artist_stays_empty(): void
    {
        $song = Song::factory()->create([
            'title' => 'テスト曲',
            'artist' => '',
        ]);

        $this->assertSame('', $song->artist);
    }
}

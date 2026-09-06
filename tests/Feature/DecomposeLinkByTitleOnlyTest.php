<?php

namespace Tests\Feature;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\User;
use App\Services\TimestampDecompositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DecomposeLinkByTitleOnlyTest extends TestCase
{
    use RefreshDatabase;

    private TimestampDecompositionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->service = app(TimestampDecompositionService::class);
    }

    private function createDecomposition(string $text, ?string $title, ?string $artist = null): TimestampDecomposition
    {
        return TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize($text),
            'original_text' => $text,
            'parts' => [$text],
            'separator_count' => 0,
            'derived_title' => $title,
            'derived_artist' => $artist,
            'status' => TimestampDecomposition::STATUS_SELECTED,
            'confidence' => 0.5,
        ]);
    }

    public function test_links_when_single_song_matches_title(): void
    {
        $song = Song::factory()->create([
            'title' => 'テスト曲名',
            'artist' => 'テストアーティスト',
        ]);

        $decomposition = $this->createDecomposition('テスト曲名', 'テスト曲名');

        $result = $this->service->linkToSong($decomposition);

        $this->assertNotNull($result);
        $this->assertEquals($song->id, $result->id);
        $this->assertDatabaseHas('timestamp_decompositions', [
            'id' => $decomposition->id,
            'song_id' => $song->id,
        ]);
    }

    public function test_does_not_link_when_multiple_songs_match_title(): void
    {
        Song::factory()->create(['title' => '同名曲', 'artist' => 'アーティストA']);
        Song::factory()->create(['title' => '同名曲', 'artist' => 'アーティストB']);

        $decomposition = $this->createDecomposition('同名曲', '同名曲');

        $result = $this->service->linkToSong($decomposition);

        $this->assertNull($result);
        $this->assertDatabaseHas('timestamp_decompositions', [
            'id' => $decomposition->id,
            'song_id' => null,
        ]);
    }

    public function test_does_not_link_when_no_song_matches_title(): void
    {
        $decomposition = $this->createDecomposition('存在しない曲', '存在しない曲');

        $result = $this->service->linkToSong($decomposition);

        $this->assertNull($result);
    }

    public function test_links_with_normalized_title_match(): void
    {
        $song = Song::factory()->create([
            'title' => 'ＴＥＳＴ曲名',
            'artist' => 'アーティスト',
        ]);

        $decomposition = $this->createDecomposition('TEST曲名', 'TEST曲名');

        $result = $this->service->linkToSong($decomposition);

        $this->assertNotNull($result);
        $this->assertEquals($song->id, $result->id);
    }

    public function test_with_artist_still_creates_new_song(): void
    {
        $decomposition = $this->createDecomposition('曲名/アーティスト', '曲名', 'アーティスト');

        $result = $this->service->linkToSong($decomposition);

        $this->assertNotNull($result);
        $this->assertEquals('曲名', $result->title);
        $this->assertEquals('アーティスト', $result->artist);
    }
}

<?php

namespace Tests\Feature;

use App\Helpers\TextNormalizer;
use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use App\Services\SongCleansingService;
use App\Services\SongMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TsItemsCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function createSongWithMappedTsItems(string $title, string $artist, int $tsCount): Song
    {
        $song = Song::factory()->create(['title' => $title, 'artist' => $artist]);

        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => true,
        ]);

        for ($i = 0; $i < $tsCount; $i++) {
            $text = "{$title} - {$artist} ({$i})";
            $normalizedText = TextNormalizer::normalize($text);

            TsItem::factory()->create([
                'video_id' => $archive->video_id,
                'text' => $text,
                'normalized_text' => $normalizedText,
                'is_display' => true,
            ]);

            TimestampSongMapping::factory()->create([
                'normalized_text' => $normalizedText,
                'song_id' => $song->id,
                'status' => 'linked',
                'is_manual' => true,
            ]);
        }

        return $song;
    }

    public function test_cleansing_service_counts_via_mappings(): void
    {
        $song1 = $this->createSongWithMappedTsItems('テスト曲', 'アーティストA', 3);
        $song2 = $this->createSongWithMappedTsItems('テスト曲', 'アーティストB', 2);

        $service = app(SongCleansingService::class);
        $groups = $service->findDuplicates();

        $this->assertNotEmpty($groups);

        $songs = collect($groups[0]['songs']);
        $songA = $songs->firstWhere('id', $song1->id);
        $songB = $songs->firstWhere('id', $song2->id);

        $this->assertEquals(3, $songA['ts_items_count']);
        $this->assertEquals(2, $songB['ts_items_count']);
    }

    public function test_merge_service_counts_via_mappings(): void
    {
        $song = $this->createSongWithMappedTsItems('検索テスト曲', 'テストアーティスト', 5);

        $service = app(SongMergeService::class);
        $results = $service->searchSongs('検索テスト曲');

        $this->assertNotEmpty($results);
        $found = collect($results)->firstWhere('id', $song->id);
        $this->assertEquals(5, $found['ts_items_count']);
    }
}

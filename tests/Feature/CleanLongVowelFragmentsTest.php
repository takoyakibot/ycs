<?php

namespace Tests\Feature;

use App\Helpers\TextNormalizer;
use App\Models\Archive;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CleanLongVowelFragmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    /**
     * 本番で見つかった形を再現する: 長音が / になった孤立マッピングと、正しい元テキストのTS分解が断片を指している
     */
    private function fragment(string $fragment, string $artist, string $orphanText, string $originalText): array
    {
        $song = Song::factory()->create(['title' => $fragment, 'artist' => $artist]);
        $mapping = TimestampSongMapping::create(['normalized_text' => $orphanText, 'song_id' => $song->id]);
        $decomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize($originalText),
            'original_text' => $originalText,
            'parts' => [$fragment, 'x', $artist],
            'separator_count' => 2,
            'derived_title' => $fragment,
            'status' => TimestampDecomposition::STATUS_SELECTED,
            'song_id' => $song->id,
        ]);

        return [$song, $mapping, $decomposition];
    }

    private function visibleTsItem(string $text): TsItem
    {
        $archive = Archive::factory()->create();

        return TsItem::factory()->create(['video_id' => $archive->video_id, 'text' => $text]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        [$song, $mapping, $decomposition] = $this->fragment('チュ', 'ナナヲアカリ', 'チュ/リングラブ / ナナヲアカリ', 'チューリングラブ / ナナヲアカリ');

        $this->artisan('songs:clean-long-vowel-fragments')
            ->expectsOutputToContain('ドライラン')
            ->assertSuccessful();

        $this->assertNotNull(Song::find($song->id));
        $this->assertNotNull(TimestampSongMapping::find($mapping->id));
        $this->assertSame($song->id, $decomposition->fresh()->song_id);
    }

    public function test_merges_into_existing_correct_song(): void
    {
        $correct = Song::factory()->create(['title' => 'チューリングラブ', 'artist' => 'ナナヲアカリ']);
        [$song, $mapping, $decomposition] = $this->fragment('チュ', 'ナナヲアカリ', 'チュ/リングラブ / ナナヲアカリ', 'チューリングラブ / ナナヲアカリ');

        $this->artisan('songs:clean-long-vowel-fragments --apply')->assertSuccessful();

        $this->assertNull(Song::find($song->id));
        $this->assertNull(TimestampSongMapping::find($mapping->id));
        $this->assertSame($correct->id, $decomposition->fresh()->song_id);
    }

    public function test_deletes_fragment_and_resets_decomposition_when_no_correct_song(): void
    {
        [$song, $mapping, $decomposition] = $this->fragment('フラジ', 'ぬゆり', 'フラジ/ル / ぬゆり', 'フラジール / ぬゆり');

        $this->artisan('songs:clean-long-vowel-fragments --apply')->assertSuccessful();

        $this->assertNull(Song::find($song->id));
        $this->assertNull(TimestampSongMapping::find($mapping->id));
        $decomposition->refresh();
        $this->assertNull($decomposition->song_id);
        $this->assertSame(TimestampDecomposition::STATUS_PENDING, $decomposition->status);
        $this->assertNull($decomposition->derived_title);
        $this->assertSame(['フラジール', 'ぬゆり'], $decomposition->parts);
    }

    public function test_ignores_correct_title_whose_artist_was_split(): void
    {
        // 曲名は正しく、アーティスト側（ワンダーランズ）だけが切れているもの
        [$song] = $this->fragment(
            'セカイはまだ始まってすらいない',
            'ワンダーランズ×ショウタイム',
            'セカイはまだ始まってすらいない/ワンダ/ランズxショウタイム',
            'セカイはまだ始まってすらいない/ワンダーランズ×ショウタイム'
        );

        $this->artisan('songs:clean-long-vowel-fragments --apply')->assertSuccessful();

        $this->assertNotNull(Song::find($song->id));
    }

    public function test_ignores_song_that_still_has_timestamps(): void
    {
        [$song] = $this->fragment('チュ', 'ナナヲアカリ', 'チュ/リングラブ / ナナヲアカリ', 'チューリングラブ / ナナヲアカリ');
        $tsItem = $this->visibleTsItem('チュー');
        TimestampSongMapping::create(['normalized_text' => $tsItem->normalized_text, 'song_id' => $song->id]);

        $this->artisan('songs:clean-long-vowel-fragments --apply')->assertSuccessful();

        $this->assertNotNull(Song::find($song->id));
    }

    public function test_leaves_fragment_when_correct_song_is_ambiguous(): void
    {
        Song::factory()->create(['title' => 'ルージュの伝言', 'artist' => '別の人A']);
        Song::factory()->create(['title' => 'ルージュの伝言', 'artist' => '別の人B']);
        [$song, , $decomposition] = $this->fragment('ル', '松任谷由実', 'ル/ジュの伝言 / 松任谷由実', 'ルージュの伝言 / 松任谷由実');

        $this->artisan('songs:clean-long-vowel-fragments --apply')->assertSuccessful();

        // 統合先が決められないので、断片を消してTS分解を再選別に戻す
        $this->assertNull(Song::find($song->id));
        $this->assertSame(TimestampDecomposition::STATUS_PENDING, $decomposition->fresh()->status);
    }
}

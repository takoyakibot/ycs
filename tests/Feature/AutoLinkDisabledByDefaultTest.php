<?php

namespace Tests\Feature;

use App\Helpers\TextNormalizer;
use App\Models\Archive;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\AutoLinkService;
use App\Services\MappingDictionaryService;
use App\Services\SongMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 自動紐付けが既定で無効であること
 *
 * 現在の信頼度の付け方では誤った紐付けを自動で書き込んでしまうため、
 * config/songs.php の auto_link_threshold を 1.0 にして無効化している。
 * 信頼度の設計を作り直すまで、既定で有効に戻してはいけない（Issue #672）。
 */
class AutoLinkDisabledByDefaultTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 信頼度の最大値より閾値が高いこと
     *
     * 最大値と同値だと >= 判定で通ってしまうため、必ず上回っている必要がある。
     */
    public function test_threshold_is_above_the_highest_confidence(): void
    {
        $threshold = (float) config('songs.matching.auto_link_threshold');

        $this->assertGreaterThan(SongMatchingService::CONFIDENCE_EXACT, $threshold);
        $this->assertGreaterThan(MappingDictionaryService::CONFIDENCE_KEY_MATCH, $threshold);
    }

    /**
     * 完全一致でも自動紐付けされないこと
     */
    public function test_does_not_auto_link_even_on_exact_match(): void
    {
        $song = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        $archive = Archive::factory()->create();
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'ロキ / みきとP',
            'is_display' => 1,
        ]);

        $result = app(AutoLinkService::class)->autoLinkUnlinkedTimestamps();

        $this->assertSame(0, $result['linked']);
        $this->assertDatabaseCount('timestamp_song_mappings', 0);
        $this->assertNull(app(SongMatchingService::class)->findBestMatch('ロキ / みきとP'));

        // 楽曲マスタは作られていない（既存の1件だけ）
        $this->assertSame(1, Song::count());
        $this->assertSame($song->id, Song::first()->id);
    }

    /**
     * 辞書の完全キー一致でも自動紐付けされないこと
     */
    public function test_does_not_auto_link_via_dictionary(): void
    {
        $song = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('♪ロキ / みきとP'),
            'song_id' => $song->id,
            'is_not_song' => false,
            'is_manual' => true,
            'status' => 'linked',
        ]);

        $this->assertNull(app(SongMatchingService::class)->findBestMatch('ロキ / みきとP (cover)'));
    }

    /**
     * 候補の「表示」は無効化の影響を受けないこと
     *
     * 自動紐付けを止めても、人が確認して選ぶための候補提示は動く必要がある。
     */
    public function test_candidates_are_still_offered(): void
    {
        Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);

        $candidates = app(SongMatchingService::class)->findCandidates('♪ロキ / みきとP');

        $this->assertNotEmpty($candidates);
        $this->assertSame('ロキ', $candidates[0]['title']);
    }
}

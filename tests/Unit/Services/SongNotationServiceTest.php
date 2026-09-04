<?php

namespace Tests\Unit\Services;

use App\Helpers\TextNormalizer;
use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\SongNotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SongNotationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SongNotationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SongNotationService::class);
    }

    private function createTsItem(string $videoId, string $text, array $overrides = []): TsItem
    {
        return TsItem::factory()->create(array_merge([
            'video_id' => $videoId,
            'text' => $text,
            'normalized_text' => TextNormalizer::normalize($text),
            'type' => '1',
            'is_display' => 1,
        ], $overrides));
    }

    /**
     * 通常マッピング経由で候補が取得できる（is_manual=trueの場合のみ）
     */
    public function test_get_candidates_via_normal_mapping(): void
    {
        $song = Song::factory()->create(['title' => 'テスト曲', 'artist' => 'テストアーティスト']);
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id, 'is_display' => 1]);

        $text = 'テストアーティスト / テスト曲';
        $normalizedText = TextNormalizer::normalize($text);

        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => $normalizedText,
            'song_id' => $song->id,
            'status' => TimestampSongMapping::STATUS_LINKED,
            'is_manual' => true,
            'is_not_song' => false,
        ]);

        $this->createTsItem($archive->video_id, $text);

        $result = $this->service->getNotationCandidates($song->id);

        $this->assertEquals($song->id, $result['song']['id']);
        $this->assertCount(1, $result['notations']);
        $this->assertEquals($text, $result['notations'][0]['text']);
        $this->assertEquals(1, $result['notations'][0]['frequency']);
    }

    /**
     * 個別マッピング（ts_items.song_id）経由で候補が取得できる
     */
    public function test_get_candidates_via_direct_song_id(): void
    {
        $song = Song::factory()->create(['title' => 'テスト曲', 'artist' => 'テストアーティスト']);
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id, 'is_display' => 1]);

        $text = 'テストアーティスト - テスト曲';
        $this->createTsItem($archive->video_id, $text, ['song_id' => $song->id]);

        $result = $this->service->getNotationCandidates($song->id);

        $this->assertCount(1, $result['notations']);
        $this->assertEquals($text, $result['notations'][0]['text']);
    }

    /**
     * アーティスト名なし（区切り文字なし）が除外される
     */
    public function test_excludes_texts_without_separators(): void
    {
        $song = Song::factory()->create(['title' => 'テスト曲', 'artist' => 'テストアーティスト']);
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id, 'is_display' => 1]);

        $textWithSep = 'アーティスト / 曲名';
        $textWithoutSep = 'アーティスト名なし曲名';

        $normalizedWithSep = TextNormalizer::normalize($textWithSep);
        $normalizedWithoutSep = TextNormalizer::normalize($textWithoutSep);

        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => $normalizedWithSep,
            'song_id' => $song->id,
            'status' => TimestampSongMapping::STATUS_LINKED,
            'is_manual' => true,
            'is_not_song' => false,
        ]);

        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => $normalizedWithoutSep,
            'song_id' => $song->id,
            'status' => TimestampSongMapping::STATUS_LINKED,
            'is_manual' => true,
            'is_not_song' => false,
        ]);

        $this->createTsItem($archive->video_id, $textWithSep);
        // 区切り文字なし2件
        $this->createTsItem($archive->video_id, $textWithoutSep);
        $this->createTsItem($archive->video_id, $textWithoutSep);

        $result = $this->service->getNotationCandidates($song->id);

        $this->assertCount(1, $result['notations']);
        $this->assertEquals($textWithSep, $result['notations'][0]['text']);
        $this->assertEquals(3, $result['total_timestamps']);
        $this->assertEquals(2, $result['excluded_count']);
    }

    /**
     * is_display=0のタイムスタンプ/アーカイブが除外される
     */
    public function test_excludes_hidden_items(): void
    {
        $song = Song::factory()->create(['title' => 'テスト曲', 'artist' => 'テストアーティスト']);
        $channel = Channel::factory()->create();
        $visibleArchive = Archive::factory()->create(['channel_id' => $channel->channel_id, 'is_display' => 1]);
        $hiddenArchive = Archive::factory()->create(['channel_id' => $channel->channel_id, 'is_display' => 0]);

        $text = 'アーティスト / 曲名';
        $normalizedText = TextNormalizer::normalize($text);

        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => $normalizedText,
            'song_id' => $song->id,
            'status' => TimestampSongMapping::STATUS_LINKED,
            'is_manual' => true,
            'is_not_song' => false,
        ]);

        // 表示中のアーカイブ+表示中のTS
        $this->createTsItem($visibleArchive->video_id, $text);
        // 非表示のアーカイブのTS
        $this->createTsItem($hiddenArchive->video_id, $text);
        // 表示中のアーカイブ+非表示のTS
        $this->createTsItem($visibleArchive->video_id, $text, ['is_display' => 0]);

        $result = $this->service->getNotationCandidates($song->id);

        $this->assertCount(1, $result['notations']);
        $this->assertEquals(1, $result['notations'][0]['frequency']);
        $this->assertEquals(1, $result['total_timestamps']);
    }

    /**
     * type='3'（カバー曲）が除外される
     */
    public function test_excludes_cover_type(): void
    {
        $song = Song::factory()->create(['title' => 'テスト曲', 'artist' => 'テストアーティスト']);
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id, 'is_display' => 1]);

        $text = 'アーティスト / 曲名';
        $normalizedText = TextNormalizer::normalize($text);

        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => $normalizedText,
            'song_id' => $song->id,
            'status' => TimestampSongMapping::STATUS_LINKED,
            'is_manual' => true,
            'is_not_song' => false,
        ]);

        // 通常タイムスタンプ
        $this->createTsItem($archive->video_id, $text, ['type' => '1']);
        // カバー曲タイムスタンプ
        $this->createTsItem($archive->video_id, $text, ['type' => '3']);

        $result = $this->service->getNotationCandidates($song->id);

        $this->assertCount(1, $result['notations']);
        $this->assertEquals(1, $result['notations'][0]['frequency']);
    }

    /**
     * 頻度の降順でソートされる
     */
    public function test_sorted_by_frequency_descending(): void
    {
        $song = Song::factory()->create(['title' => 'テスト曲', 'artist' => 'テストアーティスト']);
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id, 'is_display' => 1]);

        $textA = 'アーティストA / 曲名A';
        $textB = 'アーティストB / 曲名B';

        foreach ([$textA, $textB] as $text) {
            TimestampSongMapping::create([
                'id' => (string) Str::ulid(),
                'normalized_text' => TextNormalizer::normalize($text),
                'song_id' => $song->id,
                'status' => TimestampSongMapping::STATUS_LINKED,
                'is_manual' => true,
                'is_not_song' => false,
            ]);
        }

        // textAは1件、textBは3件
        $this->createTsItem($archive->video_id, $textA);
        $this->createTsItem($archive->video_id, $textB);
        $this->createTsItem($archive->video_id, $textB);
        $this->createTsItem($archive->video_id, $textB);

        $result = $this->service->getNotationCandidates($song->id);

        $this->assertCount(2, $result['notations']);
        $this->assertEquals($textB, $result['notations'][0]['text']);
        $this->assertEquals(3, $result['notations'][0]['frequency']);
        $this->assertEquals($textA, $result['notations'][1]['text']);
        $this->assertEquals(1, $result['notations'][1]['frequency']);
    }

    /**
     * 上限件数（30件）が適用される
     */
    public function test_max_notations_limit(): void
    {
        $song = Song::factory()->create(['title' => 'テスト曲', 'artist' => 'テストアーティスト']);
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id, 'is_display' => 1]);

        // 35種類の表記を作成
        for ($i = 0; $i < 35; $i++) {
            $text = "アーティスト{$i} / 曲名{$i}";
            TimestampSongMapping::create([
                'id' => (string) Str::ulid(),
                'normalized_text' => TextNormalizer::normalize($text),
                'song_id' => $song->id,
                'status' => TimestampSongMapping::STATUS_LINKED,
                'is_manual' => true,
                'is_not_song' => false,
            ]);
            $this->createTsItem($archive->video_id, $text);
        }

        $result = $this->service->getNotationCandidates($song->id);

        $this->assertCount(30, $result['notations']);
        $this->assertEquals(35, $result['total_timestamps']);
    }

    /**
     * excluded_countが正確に計算される
     */
    public function test_excluded_count_calculation(): void
    {
        $song = Song::factory()->create(['title' => 'テスト曲', 'artist' => 'テストアーティスト']);
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id, 'is_display' => 1]);

        $textWithSep = 'アーティスト / 曲名';
        $textWithoutSep1 = '曲名のみA';
        $textWithoutSep2 = '曲名のみB';

        foreach ([$textWithSep, $textWithoutSep1, $textWithoutSep2] as $text) {
            TimestampSongMapping::create([
                'id' => (string) Str::ulid(),
                'normalized_text' => TextNormalizer::normalize($text),
                'song_id' => $song->id,
                'status' => TimestampSongMapping::STATUS_LINKED,
                'is_manual' => true,
                'is_not_song' => false,
            ]);
        }

        // 区切り文字あり: 2件
        $this->createTsItem($archive->video_id, $textWithSep);
        $this->createTsItem($archive->video_id, $textWithSep);
        // 区切り文字なし: 3件
        $this->createTsItem($archive->video_id, $textWithoutSep1);
        $this->createTsItem($archive->video_id, $textWithoutSep2);
        $this->createTsItem($archive->video_id, $textWithoutSep2);

        $result = $this->service->getNotationCandidates($song->id);

        $this->assertEquals(5, $result['total_timestamps']);
        $this->assertEquals(3, $result['excluded_count']);
        $this->assertCount(1, $result['notations']);
        $this->assertEquals(2, $result['notations'][0]['frequency']);
    }

    /**
     * is_manual=falseのマッピングは通常マッピング経路では取得されない
     */
    public function test_excludes_non_manual_mappings(): void
    {
        $song = Song::factory()->create(['title' => 'テスト曲', 'artist' => 'テストアーティスト']);
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id, 'is_display' => 1]);

        $textManual = 'アーティスト手動 / 曲名手動';
        $textAuto = 'アーティスト自動 / 曲名自動';

        // 手動マッピング
        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize($textManual),
            'song_id' => $song->id,
            'status' => TimestampSongMapping::STATUS_LINKED,
            'is_manual' => true,
            'is_not_song' => false,
        ]);

        // 自動マッピング（is_manual=false）
        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize($textAuto),
            'song_id' => $song->id,
            'status' => TimestampSongMapping::STATUS_LINKED,
            'is_manual' => false,
            'is_not_song' => false,
        ]);

        $this->createTsItem($archive->video_id, $textManual);
        $this->createTsItem($archive->video_id, $textAuto);

        $result = $this->service->getNotationCandidates($song->id);

        // 手動マッピングの分のみ取得される
        $this->assertCount(1, $result['notations']);
        $this->assertEquals($textManual, $result['notations'][0]['text']);
    }
}

<?php

namespace Tests\Unit\Services;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\AutoLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AutoLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AutoLinkService::class);
    }

    private function createTsItem(string $text, array $overrides = []): TsItem
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        return TsItem::factory()->create(array_merge([
            'video_id' => $archive->video_id,
            'text' => $text,
            'is_display' => 1,
        ], $overrides));
    }

    public function test_auto_link_with_no_unlinked_timestamps(): void
    {
        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(0, $result['processed']);
        $this->assertEquals(0, $result['linked']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['skipped']);
    }

    public function test_auto_link_matches_existing_song_by_normalized_title(): void
    {
        $existingSong = Song::factory()->create([
            'title' => 'シャルル',
            'artist' => 'バルーン',
        ]);

        $this->createTsItem('シャルル');

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(1, $result['linked']);

        $this->assertDatabaseHas('timestamp_song_mappings', [
            'song_id' => $existingSong->id,
            'is_manual' => false,
        ]);
    }

    public function test_auto_link_matches_existing_song_with_separator(): void
    {
        $existingSong = Song::factory()->create([
            'title' => '千本桜',
            'artist' => '初音ミク',
        ]);

        $this->createTsItem('千本桜 / 初音ミク');

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['linked']);
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'song_id' => $existingSong->id,
        ]);
    }

    public function test_auto_link_returns_not_found_when_no_match(): void
    {
        $this->createTsItem('Unknown Song / Unknown Artist');

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(0, $result['linked']);
        $this->assertEquals(1, $result['skipped']);
        $this->assertDatabaseCount('timestamp_song_mappings', 0);
    }

    public function test_auto_link_skips_already_linked_timestamps(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        $linkedTs = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Linked Song',
            'is_display' => 1,
        ]);

        $song = Song::factory()->create();
        TimestampSongMapping::factory()
            ->withSong($song)
            ->withText($linkedTs->text)
            ->create();

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(0, $result['processed']);
    }

    public function test_auto_link_skips_hidden_timestamps(): void
    {
        $this->createTsItem('Hidden Song', ['is_display' => 0]);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(0, $result['processed']);
    }

    public function test_auto_link_skips_cover_song_timestamps(): void
    {
        $this->createTsItem('Cover Song', ['type' => '3']);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(0, $result['processed']);
    }

    public function test_auto_link_respects_limit(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        for ($i = 1; $i <= 5; $i++) {
            TsItem::factory()->create([
                'video_id' => $archive->video_id,
                'text' => "Song {$i}",
                'is_display' => 1,
            ]);
        }

        $result = $this->service->autoLinkUnlinkedTimestamps(2);

        $this->assertEquals(2, $result['processed']);
    }

    public function test_auto_link_detects_existing_song_with_character_variants(): void
    {
        $existingSong = Song::factory()->create([
            'title' => "Don't say \"lazy\"",
            'artist' => '桜高軽音部',
        ]);

        // UnicodeクォートのバリエーションもTextNormalizerで正規化されるためマッチする
        $this->createTsItem("Don\xE2\x80\x99t say \xE2\x80\x9Clazy\xE2\x80\x9D / 桜高軽音部");

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(1, $result['linked']);
        $this->assertDatabaseCount('songs', 1);
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'song_id' => $existingSong->id,
        ]);
    }

    public function test_auto_link_calls_progress_callback(): void
    {
        Song::factory()->create([
            'title' => 'Test Song',
            'artist' => 'Test Artist',
        ]);

        $this->createTsItem('Test Song');

        $messages = [];
        $this->service->autoLinkUnlinkedTimestamps(10, function ($message) use (&$messages) {
            $messages[] = $message;
        });

        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('処理します', $messages[0]);
    }

    public function test_auto_link_with_reversed_artist_title_order(): void
    {
        $existingSong = Song::factory()->create([
            'title' => '夜に駆ける',
            'artist' => 'YOASOBI',
        ]);

        // アーティスト/楽曲名の順序が逆でもマッチする
        $this->createTsItem('YOASOBI / 夜に駆ける');

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['linked']);
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'song_id' => $existingSong->id,
        ]);
    }

    public function test_auto_link_matches_text_with_decorations(): void
    {
        $existingSong = Song::factory()->create([
            'title' => 'ロキ',
            'artist' => 'みきとP',
        ]);

        // 除去パターンを定義していない装飾（曲番号・時間範囲・記号）でも紐付く
        $this->createTsItem('♪01.ロキ/みきとP (0:00～3:20)');

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['linked']);
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'song_id' => $existingSong->id,
            'is_manual' => false,
        ]);
    }

    public function test_auto_link_does_not_create_songs(): void
    {
        $this->createTsItem('マスタに存在しない曲 / だれか');

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(0, $result['linked']);
        $this->assertDatabaseCount('songs', 0);
    }

    public function test_auto_link_skips_ambiguous_matches(): void
    {
        // 同名タイトルで別アーティストの楽曲が2件ある状態
        Song::factory()->create(['title' => 'カタルシス', 'artist' => 'アーティストA']);
        Song::factory()->create(['title' => 'カタルシス', 'artist' => 'アーティストB']);

        $this->createTsItem('カタルシス');

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(0, $result['linked']);
        $this->assertDatabaseCount('timestamp_song_mappings', 0);
    }

    public function test_auto_link_records_matching_confidence(): void
    {
        Song::factory()->create(['title' => 'シャルル', 'artist' => 'バルーン']);

        $this->createTsItem('シャルル');

        $this->service->autoLinkUnlinkedTimestamps(10);

        $mapping = TimestampSongMapping::first();
        $this->assertNotNull($mapping);
        $this->assertEquals(0.95, $mapping->confidence);
    }

    public function test_analyze_summarizes_without_writing(): void
    {
        Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);

        $this->createTsItem('♪ロキ / みきとP');
        $this->createTsItem('マスタに存在しない曲');

        $summary = $this->service->analyzeUnlinkedTimestamps(10);

        $this->assertEquals(2, $summary['total']);
        $this->assertEquals(1, $summary['auto_linkable']);
        $this->assertEquals(1, $summary['no_match']);

        // ドライランなのでマッピングは作られない
        $this->assertDatabaseCount('timestamp_song_mappings', 0);
    }

    public function test_auto_link_uses_manually_linked_text_as_dictionary(): void
    {
        // マスタは英語表記だが、実際のタイムスタンプはカナ表記で書かれている。
        // 過去に人間が紐付けた表記を辞書として利用することで紐付けられる。
        $song = Song::factory()->create([
            'title' => 'Lost Umbrella',
            'artist' => 'Inabakumori',
        ]);

        TimestampSongMapping::create([
            'normalized_text' => \App\Helpers\TextNormalizer::normalize('ロストアンブレラ'),
            'song_id' => $song->id,
            'is_not_song' => false,
            'status' => TimestampSongMapping::STATUS_LINKED,
            'is_manual' => true,
            'confidence' => 1.0,
        ]);

        $this->createTsItem('♪ロストアンブレラ♪');

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['linked']);
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'normalized_text' => \App\Helpers\TextNormalizer::normalize('♪ロストアンブレラ♪'),
            'song_id' => $song->id,
            'is_manual' => false,
        ]);
    }

    public function test_auto_link_ignores_auto_linked_text_as_dictionary(): void
    {
        // 自動紐付けの結果は辞書に使わない（誤りが連鎖するのを防ぐ）
        $song = Song::factory()->create([
            'title' => 'Lost Umbrella',
            'artist' => 'Inabakumori',
        ]);

        TimestampSongMapping::create([
            'normalized_text' => \App\Helpers\TextNormalizer::normalize('ロストアンブレラ'),
            'song_id' => $song->id,
            'is_not_song' => false,
            'status' => TimestampSongMapping::STATUS_LINKED,
            'is_manual' => false,
            'confidence' => 0.9,
        ]);

        $this->createTsItem('♪ロストアンブレラ♪');

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(0, $result['linked']);
    }

    public function test_analyze_counts_candidate_only_matches(): void
    {
        Song::factory()->create(['title' => '夜', 'artist' => 'ヨルシカ']);

        // 短いタイトルの部分一致は自動紐付けせず候補提示に留まる
        $this->createTsItem('夜行 / だれか');

        $summary = $this->service->analyzeUnlinkedTimestamps(10);

        $this->assertEquals(1, $summary['total']);
        $this->assertEquals(0, $summary['auto_linkable']);
        $this->assertDatabaseCount('timestamp_song_mappings', 0);
    }
}

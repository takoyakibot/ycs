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

    public function test_auto_link_sets_is_manual_true_when_artist_matches(): void
    {
        Song::factory()->create([
            'title' => '千本桜',
            'artist' => '初音ミク',
        ]);

        // 「千本桜 / 初音ミク」→ タイトル・アーティスト両方一致
        $this->createTsItem('千本桜 / 初音ミク');

        $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertDatabaseHas('timestamp_song_mappings', [
            'is_manual' => true,
            'status' => 'linked',
        ]);
    }

    public function test_auto_link_sets_is_manual_false_when_artist_does_not_match(): void
    {
        Song::factory()->create([
            'title' => 'シャルル',
            'artist' => 'バルーン',
        ]);

        // 「シャルル」のみ → タイトル一致だがアーティスト情報なし
        $this->createTsItem('シャルル');

        $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertDatabaseHas('timestamp_song_mappings', [
            'is_manual' => false,
            'status' => 'linked',
        ]);
    }

    public function test_auto_link_sets_is_manual_false_when_artist_mismatches(): void
    {
        Song::factory()->create([
            'title' => 'Lemon',
            'artist' => '米津玄師',
        ]);

        // 「Lemon / 別のアーティスト」→ タイトル一致だがアーティスト不一致
        $this->createTsItem('Lemon / 別のアーティスト');

        $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertDatabaseHas('timestamp_song_mappings', [
            'is_manual' => false,
            'status' => 'linked',
        ]);
    }

    public function test_auto_link_sets_is_manual_true_with_reversed_artist_title_when_artist_matches(): void
    {
        Song::factory()->create([
            'title' => '夜に駆ける',
            'artist' => 'YOASOBI',
        ]);

        // アーティスト/楽曲名の順序が逆でもアーティスト一致判定が効く
        $this->createTsItem('YOASOBI / 夜に駆ける');

        $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertDatabaseHas('timestamp_song_mappings', [
            'is_manual' => true,
            'status' => 'linked',
        ]);
    }
}

<?php

namespace Tests\Feature\Integration;

use App\Helpers\TextNormalizer;
use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\ChangeListService;
use App\Services\ChannelQueryService;
use App\Services\CoverSongTitleExtractorService;
use App\Services\RefreshArchiveService;
use App\Services\SubtitleFingerprintService;
use App\Services\VideoAnalyzerService;
use App\Services\YouTubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * YouTube API取得 → RefreshArchiveService → ts_items → TextNormalizer → timestamp_song_mappings → songs
 * のエンドツーエンドフローを検証する統合テスト。
 *
 * モック方針は tests/Unit/Services/RefreshArchiveServiceTest.php を踏襲し、
 * YouTubeServiceのみモック化する（外部API呼び出しを避けるため）。
 */
class TimestampIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected RefreshArchiveService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // YouTubeServiceのみモック化（外部API呼び出しを避けるため）
        $this->youtubeService = Mockery::mock(YouTubeService::class);

        // 実際のサービスインスタンスを使用（DBテストのため）
        $changeListService = app(ChangeListService::class);
        $channelQueryService = app(ChannelQueryService::class);
        $videoAnalyzerService = app(VideoAnalyzerService::class);
        $coverSongTitleExtractorService = app(CoverSongTitleExtractorService::class);

        $this->service = new RefreshArchiveService(
            $this->youtubeService,
            $changeListService,
            $channelQueryService,
            $videoAnalyzerService,
            $coverSongTitleExtractorService,
            app(SubtitleFingerprintService::class)
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 一件のアーカイブデータを組み立てるヘルパー
     * (テキストと正規化テキストを指定してts_itemsを含むアーカイブ配列を返す)
     */
    private function buildArchiveData(string $channelId, string $videoId, string $text, string $normalizedText): array
    {
        return [
            [
                'id' => Str::uuid()->toString(),
                'video_id' => $videoId,
                'channel_id' => $channelId,
                'title' => 'Test Archive',
                'thumbnail' => 'https://example.com/thumb.jpg',
                'is_public' => true,
                'is_display' => true,
                'published_at' => now(),
                'comments_updated_at' => now(),
                'description' => '',
                'ts_items' => [
                    [
                        'id' => Str::uuid()->toString(),
                        'video_id' => $videoId,
                        'type' => '1',
                        'ts_text' => '1:00',
                        'ts_num' => 60,
                        'text' => $text,
                        'normalized_text' => $normalizedText,
                        'is_display' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * ts_items → timestamp_song_mappings → songs の3テーブルJOINで
     * video_idに紐づく楽曲情報を取得するヘルパー
     */
    private function fetchSongViaJoin(string $videoId): ?object
    {
        return DB::table('ts_items')
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->leftJoin('songs', 'timestamp_song_mappings.song_id', '=', 'songs.id')
            ->where('ts_items.video_id', $videoId)
            ->select('songs.id as song_id', 'songs.title as song_title', 'songs.artist as song_artist')
            ->first();
    }

    /**
     * YouTube API取得 → refreshArchives → 正規化されたts_items → マッピング経由での楽曲紐づけ
     * までの一連の流れを検証する
     */
    public function test_full_flow_from_api_to_normalized_mapping(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 全角スラッシュ区切りのテキスト（YouTubeService::getArchivesAndTsItems()が
        // TimestampExtractorService経由で正規化済みのnormalized_textを返す想定）
        $originalText = 'アーティスト／曲名';
        $normalizedText = TextNormalizer::normalize($originalText);

        // 正規化により全角スラッシュが半角に変換されていることを事前に確認
        $this->assertSame('アーティスト/曲名', $normalizedText);

        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->with($channel->channel_id, [])
            ->andReturn($this->buildArchiveData($channel->channel_id, 'video123', $originalText, $normalizedText));

        $count = $this->service->refreshArchives($channel);
        $this->assertEquals(1, $count);

        // ts_itemsが正しいnormalized_textで登録されていることを確認
        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'video123',
            'text' => $originalText,
            'normalized_text' => $normalizedText,
        ]);

        // マッピングと楽曲マスタを作成
        $song = Song::factory()->create([
            'title' => '曲名',
            'artist' => 'アーティスト',
        ]);
        TimestampSongMapping::factory()
            ->withSong($song)
            ->manual()
            ->withText($originalText)
            ->create();

        // 3テーブルJOIN(ts_items → timestamp_song_mappings → songs)で楽曲が引けることを確認
        $result = $this->fetchSongViaJoin('video123');

        $this->assertNotNull($result);
        $this->assertEquals($song->id, $result->song_id);
        $this->assertEquals('曲名', $result->song_title);
        $this->assertEquals('アーティスト', $result->song_artist);
    }

    /**
     * TextNormalizer::normalize()の正規化エッジケースを、
     * TsItemモデルのsavingイベント経由(実際の保存経路)で検証する
     */
    public function test_normalization_edge_cases(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'edgecase001',
        ]);

        $cases = [
            // 全角英数字 → 半角 + 小文字化
            ['Ｈｅｌｌｏ Ｗｏｒｌｄ', 'hello world'],
            // 波ダッシュ(U+301C) → 半角チルダ
            ['曲名〜remix〜', '曲名~remix~'],
            // ゼロ幅スペース(U+200B)の除去
            ["test\u{200B}text", 'testtext'],
            // 全角スラッシュ → 半角
            ['曲名／アーティスト', '曲名/アーティスト'],
            // 大文字 → 小文字
            ['HELLO WORLD', 'hello world'],
            // 全角スペース → 半角スペース1つに統一
            ['曲名　/　アーティスト', '曲名 / アーティスト'],
        ];

        foreach ($cases as $index => [$original, $expected]) {
            $tsItem = TsItem::create([
                'id' => (string) Str::ulid(),
                'video_id' => $archive->video_id,
                'type' => '1',
                'ts_text' => sprintf('0:%02d', $index + 1),
                'ts_num' => $index + 1,
                'text' => $original,
                'is_display' => true,
            ]);

            $actual = DB::table('ts_items')->where('id', $tsItem->id)->value('normalized_text');
            $this->assertSame($expected, $actual, "normalized_text mismatch for input: {$original}");
        }
    }

    /**
     * アーカイブ更新でts_item id が変わっても(DELETE→再INSERT)、
     * normalized_text経由のマッピングJOINが維持されることを検証する
     */
    public function test_mapping_survives_refresh(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        $originalText = 'アーティスト／曲名';
        $normalizedText = TextNormalizer::normalize($originalText);

        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->twice()
            ->with($channel->channel_id, [])
            ->andReturnUsing(fn () => $this->buildArchiveData($channel->channel_id, 'video123', $originalText, $normalizedText));

        // 1回目のrefresh
        $this->service->refreshArchives($channel);
        $firstTsItemId = TsItem::where('video_id', 'video123')->firstOrFail()->id;

        // 1回目のrefresh後にマッピングと楽曲を作成
        $song = Song::factory()->create();
        TimestampSongMapping::factory()
            ->withSong($song)
            ->manual()
            ->withText($originalText)
            ->create();

        // 2回目のrefresh(同一のアーカイブデータ。ts_item idは呼び出しごとに新規生成される)
        $this->service->refreshArchives($channel);
        $secondTsItem = TsItem::where('video_id', 'video123')->firstOrFail();

        // ts_item idはDELETE→再INSERTにより変わっている
        $this->assertNotEquals($firstTsItemId, $secondTsItem->id);
        // normalized_textは変わらない
        $this->assertSame($normalizedText, $secondTsItem->normalized_text);

        // 新しいts_itemに対しても、normalized_text経由でマッピングが引き続きJOINできることを確認
        $result = $this->fetchSongViaJoin('video123');

        $this->assertNotNull($result);
        $this->assertEquals($song->id, $result->song_id);
    }

    /**
     * タイムスタンプ表記ゆれ(1:23 / 01:23 / 1:23:45)のts_text・ts_numの保存を検証する
     */
    public function test_timestamp_format_variations(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'formattest1',
        ]);

        $cases = [
            // [ts_text, ts_num, text]
            ['1:23', 83, 'Short Format Song'],
            ['01:23', 83, 'Zero Padded Song'],
            ['1:23:45', 5025, 'Hour Format Song'],
        ];

        foreach ($cases as [$tsText, $tsNum, $text]) {
            $tsItem = TsItem::create([
                'id' => (string) Str::ulid(),
                'video_id' => $archive->video_id,
                'type' => '1',
                'ts_text' => $tsText,
                'ts_num' => $tsNum,
                'text' => $text,
                'is_display' => true,
            ]);

            $this->assertDatabaseHas('ts_items', [
                'id' => $tsItem->id,
                'ts_text' => $tsText,
                'ts_num' => $tsNum,
                'text' => $text,
                'normalized_text' => TextNormalizer::normalize($text),
            ]);
        }

        $this->assertEquals(3, TsItem::where('video_id', 'formattest1')->count());
    }
}

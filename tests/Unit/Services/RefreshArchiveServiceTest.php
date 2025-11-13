<?php

namespace Tests\Unit\Services;

use App\Models\Archive;
use App\Models\ChangeList;
use App\Models\Channel;
use App\Models\TsItem;
use App\Services\RefreshArchiveService;
use App\Services\YouTubeService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RefreshArchiveServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RefreshArchiveService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // YouTubeServiceをモック化
        $this->youtubeService = Mockery::mock(YouTubeService::class);
        $this->service = new RefreshArchiveService($this->youtubeService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * アーカイブとタイムスタンプの基本的な取得・登録
     */
    public function test_refresh_archives_basic_flow(): void
    {
        $channel = Channel::factory()->create([
            'channel_id' => 'UC123456789',
            'handle' => 'test-channel',
        ]);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->with($channel->channel_id)
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'video123',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Test Archive',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => 'This will be removed',
                    'ts_items' => [
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'video123',
                            'type' => '1',
                            'ts_text' => '1:00',
                            'ts_num' => 60,
                            'text' => 'Test Song',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $count = $this->service->refreshArchives($channel);

        // アーカイブが登録されていることを確認
        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('archives', [
            'video_id' => 'video123',
            'channel_id' => $channel->channel_id,
            'title' => 'Test Archive',
        ]);

        // タイムスタンプが登録されていることを確認
        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'video123',
            'type' => '1',
            'text' => 'Test Song',
        ]);

        // descriptionフィールドは$fillableに含まれておらず、DBに保存されないことを確認
        // (RefreshArchiveServiceでunset()されている)
        $archive = Archive::where('video_id', 'video123')->first();
        // descriptionがnullまたは存在しないことを確認
        $this->assertFalse(isset($archive->description));
    }

    /**
     * change_listの情報がts_itemsに正しく反映される
     */
    public function test_apply_change_list_to_ts_items(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 初期データ作成
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
            'is_display' => true,
        ]);

        $tsItem = TsItem::factory()->create([
            'video_id' => 'video123',
            'comment_id' => 'comment123',
            'type' => '2',
            'is_display' => true,
        ]);

        // change_listに非表示設定を追加
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
            'comment_id' => 'comment123',
            'is_display' => false,
        ]);

        // YouTubeServiceのモック設定（空のアーカイブを返す）
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => $archive->id,
                    'video_id' => 'video123',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Test Archive',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => $tsItem->id,
                            'video_id' => 'video123',
                            'comment_id' => 'comment123',
                            'type' => '2',
                            'ts_text' => '1:00',
                            'ts_num' => 60,
                            'text' => 'Test',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // ts_itemsのis_displayがfalseに更新されていることを確認
        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'video123',
            'comment_id' => 'comment123',
            'is_display' => false,
        ]);
    }

    /**
     * change_listの情報がarchivesに正しく反映される
     */
    public function test_apply_change_list_to_archives(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 初期データ作成
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
            'is_display' => true,
        ]);

        // change_listに非表示設定を追加（comment_id IS NULL = アーカイブ）
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
            'comment_id' => null,
            'is_display' => false,
        ]);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => $archive->id,
                    'video_id' => 'video123',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Test Archive',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // archivesのis_displayがfalseに更新されていることを確認
        $this->assertDatabaseHas('archives', [
            'video_id' => 'video123',
            'is_display' => false,
        ]);
    }

    /**
     * 不要なchange_listが削除される（タイムスタンプ）
     */
    public function test_delete_obsolete_change_lists_for_timestamps(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 存在するアーカイブ
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
        ]);

        // 存在しないタイムスタンプに紐づくchange_list
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
            'comment_id' => 'nonexistent_comment',
            'is_display' => false,
        ]);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => $archive->id,
                    'video_id' => 'video123',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Test Archive',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [],
                ],
            ]);

        // getTimeStampsFromCommentsのモック設定
        // change_listに存在するcomment_idに対して呼ばれる可能性があるため許可
        $this->youtubeService
            ->shouldReceive('getTimeStampsFromComments')
            ->andReturn([]);

        $this->service->refreshArchives($channel);

        // 不要なchange_listが削除されていることを確認
        $this->assertDatabaseMissing('change_list', [
            'video_id' => 'video123',
            'comment_id' => 'nonexistent_comment',
        ]);
    }

    /**
     * 不要なchange_listが削除される（アーカイブ）
     */
    public function test_delete_obsolete_change_lists_for_archives(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 存在しないアーカイブに紐づくchange_list
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'nonexistent_video',
            'comment_id' => null,
            'is_display' => false,
        ]);

        // YouTubeServiceのモック設定（空のアーカイブリストを返す）
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([]);

        $this->service->refreshArchives($channel);

        // 不要なchange_listが削除されていることを確認
        $this->assertDatabaseMissing('change_list', [
            'video_id' => 'nonexistent_video',
        ]);
    }

    /**
     * 必要なchange_listは削除されない
     */
    public function test_keep_necessary_change_lists(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
            'is_display' => true,
        ]);

        // 存在するアーカイブに紐づくchange_list
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
            'comment_id' => null,
            'is_display' => false,
        ]);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => $archive->id,
                    'video_id' => 'video123',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Test Archive',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // 必要なchange_listは残っていることを確認
        $this->assertDatabaseHas('change_list', [
            'video_id' => 'video123',
            'comment_id' => null,
        ]);
    }

    /**
     * YouTube API接続エラーのハンドリング
     */
    public function test_handle_youtube_api_error(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // YouTubeServiceがExceptionを投げるように設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andThrow(new Exception('YouTube API Error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('youtubeとの接続でエラーが発生しました');

        $this->service->refreshArchives($channel);
    }

    /**
     * 既存データの置き換え
     * refreshArchivesが既存のアーカイブを削除し、新しいデータで置き換えることを確認
     */
    public function test_refresh_archives_replaces_existing_data(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 既存のアーカイブを作成
        $existingArchive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'existing_video',
        ]);

        // 既存のタイムスタンプを作成
        TsItem::factory()->create([
            'video_id' => 'existing_video',
            'type' => '1',
            'text' => 'Old Song',
        ]);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'new_video',
                    'channel_id' => $channel->channel_id,
                    'title' => 'New Archive',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'new_video',
                            'type' => '1',
                            'ts_text' => '1:00',
                            'ts_num' => 60,
                            'text' => 'New Song',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // 既存のアーカイブとタイムスタンプが削除されることを確認
        $this->assertDatabaseMissing('archives', [
            'video_id' => 'existing_video',
        ]);
        $this->assertDatabaseMissing('ts_items', [
            'video_id' => 'existing_video',
            'text' => 'Old Song',
        ]);

        // 新しいアーカイブとタイムスタンプが登録されることを確認
        $this->assertDatabaseHas('archives', [
            'video_id' => 'new_video',
            'title' => 'New Archive',
        ]);
        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'new_video',
            'text' => 'New Song',
        ]);
    }

    /**
     * 複数のアーカイブとタイムスタンプの処理
     */
    public function test_handle_multiple_archives_and_timestamps(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'video1',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Archive 1',
                    'thumbnail' => 'https://example.com/thumb1.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'video1',
                            'type' => '1',
                            'ts_text' => '1:00',
                            'ts_num' => 60,
                            'text' => 'Song 1',
                            'is_display' => true,
                        ],
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'video1',
                            'type' => '1',
                            'ts_text' => '2:00',
                            'ts_num' => 120,
                            'text' => 'Song 2',
                            'is_display' => true,
                        ],
                    ],
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'video2',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Archive 2',
                    'thumbnail' => 'https://example.com/thumb2.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'video2',
                            'type' => '1',
                            'ts_text' => '1:30',
                            'ts_num' => 90,
                            'text' => 'Song 3',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $count = $this->service->refreshArchives($channel);

        $this->assertEquals(2, $count);

        // アーカイブが登録されていることを確認
        $this->assertDatabaseHas('archives', ['video_id' => 'video1']);
        $this->assertDatabaseHas('archives', ['video_id' => 'video2']);

        // タイムスタンプが登録されていることを確認
        $this->assertDatabaseHas('ts_items', ['video_id' => 'video1', 'text' => 'Song 1']);
        $this->assertDatabaseHas('ts_items', ['video_id' => 'video1', 'text' => 'Song 2']);
        $this->assertDatabaseHas('ts_items', ['video_id' => 'video2', 'text' => 'Song 3']);
    }

    /**
     * change_listの適用が正しく行われる（複雑なシナリオ）
     */
    public function test_complex_change_list_scenario(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 既存データ
        $archive1 = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video1',
            'is_display' => true,
        ]);

        $tsItem1 = TsItem::factory()->create([
            'video_id' => 'video1',
            'comment_id' => 'comment1',
            'type' => '2',
            'is_display' => true,
        ]);

        $tsItem2 = TsItem::factory()->create([
            'video_id' => 'video1',
            'comment_id' => 'comment2',
            'type' => '2',
            'is_display' => true,
        ]);

        // change_listの設定
        // - archive1を非表示
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video1',
            'comment_id' => null,
            'is_display' => false,
        ]);

        // - tsItem1を非表示
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video1',
            'comment_id' => 'comment1',
            'is_display' => false,
        ]);

        // - tsItem2は表示のまま（change_listなし）

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => $archive1->id,
                    'video_id' => 'video1',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Archive 1',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => $tsItem1->id,
                            'video_id' => 'video1',
                            'comment_id' => 'comment1',
                            'type' => '2',
                            'ts_text' => '1:00',
                            'ts_num' => 60,
                            'text' => 'Song 1',
                            'is_display' => true,
                        ],
                        [
                            'id' => $tsItem2->id,
                            'video_id' => 'video1',
                            'comment_id' => 'comment2',
                            'type' => '2',
                            'ts_text' => '2:00',
                            'ts_num' => 120,
                            'text' => 'Song 2',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // 結果確認
        $this->assertDatabaseHas('archives', [
            'video_id' => 'video1',
            'is_display' => false, // change_listにより非表示
        ]);

        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'video1',
            'comment_id' => 'comment1',
            'is_display' => false, // change_listにより非表示
        ]);

        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'video1',
            'comment_id' => 'comment2',
            'is_display' => true, // 表示のまま
        ]);
    }

    /**
     * refreshTimeStampsFromComments のテスト
     */
    public function test_refresh_timestamps_from_comments(): void
    {
        $videoId = 'video123';

        // アーカイブを先に作成（外部キー制約のため）
        $channel = Channel::factory()->create();
        Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => $videoId,
        ]);

        // 既存のtype=2のタイムスタンプ
        TsItem::factory()->create([
            'video_id' => $videoId,
            'comment_id' => 'old_comment',
            'type' => '2',
            'text' => 'Old Song',
        ]);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getTimeStampsFromComments')
            ->once()
            ->with($videoId)
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => $videoId,
                    'comment_id' => 'new_comment',
                    'type' => '2',
                    'ts_text' => '1:00',
                    'ts_num' => 60,
                    'text' => 'New Song',
                    'is_display' => true,
                ],
            ]);

        $this->service->refreshTimeStampsFromComments($videoId);

        // 古いタイムスタンプが削除されていることを確認
        $this->assertDatabaseMissing('ts_items', [
            'video_id' => $videoId,
            'comment_id' => 'old_comment',
        ]);

        // 新しいタイムスタンプが登録されていることを確認
        $this->assertDatabaseHas('ts_items', [
            'video_id' => $videoId,
            'comment_id' => 'new_comment',
            'text' => 'New Song',
        ]);
    }

    /**
     * refreshTimeStampsFromComments でAPIエラーが発生した場合
     */
    public function test_refresh_timestamps_from_comments_handles_api_error(): void
    {
        $videoId = 'video123';

        // YouTubeServiceがExceptionを投げるように設定
        $this->youtubeService
            ->shouldReceive('getTimeStampsFromComments')
            ->once()
            ->andThrow(new Exception('YouTube API Error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('youtubeとの接続でエラーが発生しました');

        $this->service->refreshTimeStampsFromComments($videoId);
    }
}

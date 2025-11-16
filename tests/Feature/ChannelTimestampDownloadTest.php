<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChannelTimestampDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    private Archive $archive;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // テスト用のユーザーを作成
        $this->user = User::factory()->create();

        // テスト用のチャンネルとアーカイブを作成
        $this->channel = Channel::create([
            'handle' => 'test-channel',
            'channel_id' => 'UC123456789',
            'title' => 'Test Channel',
            'thumbnail' => 'https://example.com/thumb.jpg',
            'user_id' => $this->user->id,
        ]);

        $this->archive = Archive::create([
            'id' => 'video123',
            'channel_id' => 'UC123456789',
            'video_id' => 'video123',
            'title' => 'Test Archive',
            'thumbnail' => 'https://example.com/video.jpg',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);
    }

    /**
     * 基本的なダウンロード機能のテスト
     */
    public function test_download_timestamps_returns_text_file(): void
    {
        // タイムスタンプを作成
        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'text' => 'Test Song',
            'is_display' => true,
        ]);

        $response = $this->get("/api/channels/{$this->channel->handle}/timestamps/download");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="timestamps_test-channel_'.date('Ymd').'.txt"');
    }

    /**
     * BOM-UTF-8エンコーディングのテスト
     */
    public function test_download_includes_bom_utf8(): void
    {
        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'text' => 'Test Song',
            'is_display' => true,
        ]);

        $response = $this->get("/api/channels/{$this->channel->handle}/timestamps/download");

        $content = $response->getContent();
        // BOM (EF BB BF) で始まることを確認
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
    }

    /**
     * 重複したタイムスタンプが除外されることをテスト
     */
    public function test_download_removes_duplicate_timestamps(): void
    {
        // 同じ楽曲の重複したタイムスタンプを作成
        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'text' => 'Same Song',
            'is_display' => true,
        ]);

        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '5:45',
            'ts_num' => 345,
            'text' => 'SAME SONG', // 大文字（正規化後は同じ）
            'is_display' => true,
        ]);

        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '10:00',
            'ts_num' => 600,
            'text' => 'Different Song',
            'is_display' => true,
        ]);

        $response = $this->get("/api/channels/{$this->channel->handle}/timestamps/download");

        $content = $response->getContent();
        // BOMを除去
        $content = substr($content, 3);
        $lines = explode("\n", trim($content));

        // 重複が除外され、2行のみであることを確認
        $this->assertCount(2, $lines);
        $this->assertContains('different song', $lines);
        $this->assertContains('same song', $lines);
    }

    /**
     * 「楽曲ではない」とマークされたアイテムが除外されることをテスト
     */
    public function test_download_excludes_not_song_items(): void
    {
        // 通常の楽曲
        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'text' => 'Valid Song',
            'is_display' => true,
        ]);

        // 「楽曲ではない」アイテム
        $notSongText = 'Not A Song';
        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '2:00',
            'ts_num' => 120,
            'text' => $notSongText,
            'is_display' => true,
        ]);

        // TimestampSongMappingで「楽曲ではない」とマーク
        TimestampSongMapping::create([
            'id' => Str::uuid(),
            'normalized_text' => \App\Helpers\TextNormalizer::normalize($notSongText),
            'song_id' => null,
            'is_not_song' => true,
            'is_manual' => true,
        ]);

        $response = $this->get("/api/channels/{$this->channel->handle}/timestamps/download");

        $content = $response->getContent();
        $content = substr($content, 3); // BOM除去
        $lines = explode("\n", trim($content));

        // 「楽曲ではない」が除外され、1行のみであることを確認
        $this->assertCount(1, $lines);
        $this->assertEquals('valid song', $lines[0]);
        $this->assertNotContains('not a song', $lines);
    }

    /**
     * コンテンツがソートされていることをテスト
     */
    public function test_download_content_is_sorted(): void
    {
        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'text' => 'Zebra Song',
            'is_display' => true,
        ]);

        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '2:00',
            'ts_num' => 120,
            'text' => 'Apple Song',
            'is_display' => true,
        ]);

        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '3:00',
            'ts_num' => 180,
            'text' => 'Middle Song',
            'is_display' => true,
        ]);

        $response = $this->get("/api/channels/{$this->channel->handle}/timestamps/download");

        $content = $response->getContent();
        $content = substr($content, 3); // BOM除去
        $lines = explode("\n", trim($content));

        // アルファベット順にソートされていることを確認
        $this->assertEquals('apple song', $lines[0]);
        $this->assertEquals('middle song', $lines[1]);
        $this->assertEquals('zebra song', $lines[2]);
    }

    /**
     * 非表示のアーカイブのタイムスタンプが除外されることをテスト
     */
    public function test_download_excludes_hidden_archives(): void
    {
        // 非表示のアーカイブ
        $hiddenArchive = Archive::create([
            'id' => 'video456',
            'channel_id' => 'UC123456789',
            'video_id' => 'video456',
            'title' => 'Hidden Archive',
            'thumbnail' => 'https://example.com/hidden.jpg',
            'is_public' => true,
            'is_display' => false, // 非表示
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'text' => 'Visible Song',
            'is_display' => true,
        ]);

        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video456',
            'type' => '1',
            'ts_text' => '2:00',
            'ts_num' => 120,
            'text' => 'Hidden Song',
            'is_display' => true,
        ]);

        $response = $this->get("/api/channels/{$this->channel->handle}/timestamps/download");

        $content = $response->getContent();
        $content = substr($content, 3); // BOM除去
        $lines = explode("\n", trim($content));

        // 表示されているアーカイブのタイムスタンプのみが含まれることを確認
        $this->assertCount(1, $lines);
        $this->assertEquals('visible song', $lines[0]);
    }

    /**
     * 空のテキストのタイムスタンプが除外されることをテスト
     */
    public function test_download_excludes_empty_text(): void
    {
        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'text' => 'Valid Song',
            'is_display' => true,
        ]);

        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '3:00',
            'ts_num' => 180,
            'text' => '',
            'is_display' => true,
        ]);

        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '4:00',
            'ts_num' => 240,
            'text' => '   ', // 空白のみ（正規化後は空文字）
            'is_display' => true,
        ]);

        $response = $this->get("/api/channels/{$this->channel->handle}/timestamps/download");

        $content = $response->getContent();
        $content = substr($content, 3); // BOM除去
        $lines = explode("\n", trim($content));

        // 有効なテキストのみが含まれることを確認
        $this->assertCount(1, $lines);
        $this->assertEquals('valid song', $lines[0]);
    }

    /**
     * 非表示のタイムスタンプが除外されることをテスト
     */
    public function test_download_excludes_hidden_timestamps(): void
    {
        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'text' => 'Visible Song',
            'is_display' => true,
        ]);

        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '2:00',
            'ts_num' => 120,
            'text' => 'Hidden Song',
            'is_display' => false,
        ]);

        $response = $this->get("/api/channels/{$this->channel->handle}/timestamps/download");

        $content = $response->getContent();
        $content = substr($content, 3); // BOM除去
        $lines = explode("\n", trim($content));

        // 表示されているタイムスタンプのみが含まれることを確認
        $this->assertCount(1, $lines);
        $this->assertEquals('visible song', $lines[0]);
    }

    /**
     * 存在しないチャンネルで404が返されることをテスト
     */
    public function test_download_returns_404_for_nonexistent_channel(): void
    {
        $response = $this->get('/api/channels/nonexistent-channel/timestamps/download');

        $response->assertStatus(404);
    }

    /**
     * タイムスタンプが存在しない場合は空のファイルが返されることをテスト
     */
    public function test_download_returns_empty_file_when_no_timestamps(): void
    {
        $response = $this->get("/api/channels/{$this->channel->handle}/timestamps/download");

        $response->assertStatus(200);
        $content = $response->getContent();
        // BOMのみが含まれていることを確認
        $this->assertEquals("\xEF\xBB\xBF", $content);
    }

    /**
     * レート制限が適用されていることをテスト
     */
    public function test_download_has_rate_limiting(): void
    {
        // レート制限の設定を確認するため、ルート情報を取得
        $route = app('router')->getRoutes()->getByName('channels.downloadTimestamps');

        $this->assertNotNull($route);

        // throttleミドルウェアが適用されていることを確認
        $middleware = $route->middleware();
        $hasThrottle = collect($middleware)->contains(function ($middleware) {
            return str_contains($middleware, 'throttle');
        });

        $this->assertTrue($hasThrottle, 'Route should have throttle middleware');
    }

    /**
     * Content-Lengthヘッダーが正しく設定されることをテスト
     */
    public function test_download_has_correct_content_length_header(): void
    {
        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'text' => 'Test Song',
            'is_display' => true,
        ]);

        $response = $this->get("/api/channels/{$this->channel->handle}/timestamps/download");

        $content = $response->getContent();
        $expectedLength = strlen($content);

        $response->assertHeader('Content-Length', (string) $expectedLength);
    }

    /**
     * 大量のタイムスタンプでもメモリエラーが発生しないことをテスト
     * （チャンク処理の動作確認）
     */
    public function test_download_handles_large_dataset(): void
    {
        // 2000件のタイムスタンプを作成（チャンクサイズ1000より多い）
        for ($i = 0; $i < 2000; $i++) {
            TsItem::create([
                'id' => Str::uuid(),
                'video_id' => 'video123',
                'type' => '1',
                'ts_text' => sprintf('%d:%02d', intdiv($i, 60), $i % 60),
                'ts_num' => $i * 10,
                'text' => "Song {$i}",
                'is_display' => true,
            ]);
        }

        $response = $this->get("/api/channels/{$this->channel->handle}/timestamps/download");

        $response->assertStatus(200);

        $content = $response->getContent();
        $content = substr($content, 3); // BOM除去
        $lines = explode("\n", trim($content));

        // 2000件全てが含まれていることを確認
        $this->assertCount(2000, $lines);
    }

    /**
     * 複数のアーカイブにまたがるタイムスタンプを正しく処理することをテスト
     */
    public function test_download_handles_multiple_archives(): void
    {
        // 2つ目のアーカイブを作成
        $archive2 = Archive::create([
            'id' => 'video789',
            'channel_id' => 'UC123456789',
            'video_id' => 'video789',
            'title' => 'Second Archive',
            'thumbnail' => 'https://example.com/video2.jpg',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        // 各アーカイブにタイムスタンプを作成
        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'text' => 'Song A',
            'is_display' => true,
        ]);

        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'video789',
            'type' => '1',
            'ts_text' => '2:00',
            'ts_num' => 120,
            'text' => 'Song B',
            'is_display' => true,
        ]);

        $response = $this->get("/api/channels/{$this->channel->handle}/timestamps/download");

        $content = $response->getContent();
        $content = substr($content, 3); // BOM除去
        $lines = explode("\n", trim($content));

        // 両方のアーカイブのタイムスタンプが含まれることを確認
        $this->assertCount(2, $lines);
        $this->assertContains('song a', $lines);
        $this->assertContains('song b', $lines);
    }

    /**
     * ファイル名に特殊文字を含むhandleが正しく処理されることをテスト
     */
    public function test_download_filename_handles_special_characters(): void
    {
        // @を含むhandleのチャンネルを作成
        $channel = Channel::create([
            'handle' => '@TestChannel',
            'channel_id' => 'UC987654321',
            'title' => 'Special Test Channel',
            'thumbnail' => 'https://example.com/special.jpg',
            'user_id' => $this->user->id,
        ]);

        Archive::create([
            'id' => 'videoSpecial',
            'channel_id' => 'UC987654321',
            'video_id' => 'videoSpecial',
            'title' => 'Special Archive',
            'thumbnail' => 'https://example.com/special-video.jpg',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'videoSpecial',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => 'Test',
            'is_display' => true,
        ]);

        $response = $this->get("/api/channels/{$channel->handle}/timestamps/download");

        // @が除去されたファイル名になることを確認
        $response->assertHeader('Content-Disposition', 'attachment; filename="timestamps_TestChannel_'.date('Ymd').'.txt"');
    }

    /**
     * ファイル名の長さが20文字に制限されることをテスト
     */
    public function test_download_filename_length_limit(): void
    {
        // 20文字を超えるhandleのチャンネルを作成
        $channel = Channel::create([
            'handle' => 'VeryLongChannelHandleNameThatExceeds20Characters',
            'channel_id' => 'UC111222333',
            'title' => 'Long Handle Channel',
            'thumbnail' => 'https://example.com/long.jpg',
            'user_id' => $this->user->id,
        ]);

        Archive::create([
            'id' => 'videoLong',
            'channel_id' => 'UC111222333',
            'video_id' => 'videoLong',
            'title' => 'Long Archive',
            'thumbnail' => 'https://example.com/long-video.jpg',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'videoLong',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => 'Test',
            'is_display' => true,
        ]);

        $response = $this->get("/api/channels/{$channel->handle}/timestamps/download");

        // 最初の20文字に切り詰められることを確認
        $response->assertHeader('Content-Disposition', 'attachment; filename="timestamps_VeryLongChannelHandl_'.date('Ymd').'.txt"');
    }

    /**
     * 特殊文字が完全に除去された場合にchannel_idが使用されることをテスト
     */
    public function test_download_filename_uses_channel_id_when_handle_becomes_empty(): void
    {
        // 特殊文字のみのhandleのチャンネルを作成
        $channel = Channel::create([
            'handle' => '@@@',
            'channel_id' => 'UC999888777',
            'title' => 'Special Only Channel',
            'thumbnail' => 'https://example.com/special-only.jpg',
            'user_id' => $this->user->id,
        ]);

        Archive::create([
            'id' => 'videoSpecialOnly',
            'channel_id' => 'UC999888777',
            'video_id' => 'videoSpecialOnly',
            'title' => 'Special Only Archive',
            'thumbnail' => 'https://example.com/special-only-video.jpg',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        TsItem::create([
            'id' => Str::uuid(),
            'video_id' => 'videoSpecialOnly',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => 'Test',
            'is_display' => true,
        ]);

        $response = $this->get("/api/channels/{$channel->handle}/timestamps/download");

        // 特殊文字が除去されると空になり、unknownが使用されることを確認
        $response->assertHeader('Content-Disposition', 'attachment; filename="timestamps_unknown_'.date('Ymd').'.txt"');
    }
}

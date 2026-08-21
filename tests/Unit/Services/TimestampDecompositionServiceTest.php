<?php

namespace Tests\Unit\Services;

use App\Helpers\TextNormalizer;
use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use App\Services\TimestampDecompositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TimestampDecompositionServiceTest extends TestCase
{
    use RefreshDatabase;

    private TimestampDecompositionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TimestampDecompositionService;
    }

    /**
     * saveSelectionが選別結果を保存することをテスト
     */
    public function test_save_selection_saves_derived_values(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $decomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('Ado / うっせぇわ'),
            'original_text' => 'Ado / うっせぇわ',
            'parts' => ['Ado', 'うっせぇわ'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
        ]);

        $result = $this->service->saveSelection($decomposition->id, [1], [0]);

        $source = $result['decomposition'];
        $this->assertEquals(TimestampDecomposition::STATUS_SELECTED, $source->status);
        $this->assertEquals('Ado', $source->derived_artist);
        $this->assertEquals('うっせぇわ', $source->derived_title);
    }

    /**
     * saveSelectionが他の分解待ちレコードに影響しないことをテスト
     *
     * 以前は確定したアーティスト名を同名の他のレコードにも自動的に転記していたが、
     * 曲名側は候補が複数残ると先頭を機械的に採用するだけの推測だったため廃止した。
     * 確定操作は常にその1件だけに適用する。
     */
    public function test_save_selection_does_not_affect_other_decompositions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $source = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('Ado / うっせぇわ'),
            'original_text' => 'Ado / うっせぇわ',
            'parts' => ['Ado', 'うっせぇわ'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
        ]);

        $other = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('Ado / 踊'),
            'original_text' => 'Ado / 踊',
            'parts' => ['Ado', '踊'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
        ]);

        $this->service->saveSelection($source->id, [1], [0]);

        $other->refresh();
        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $other->status);
        $this->assertNull($other->derived_artist);
        $this->assertNull($other->derived_title);
    }

    /**
     * 「楽曲でない」とマークされたタイムスタンプがスキャン対象から除外されることをテスト
     */
    public function test_scan_excludes_not_song_timestamps(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // チャンネルとアーカイブを作成
        $channel = Channel::create([
            'channel_id' => 'UC_test_channel',
            'handle' => '@test',
            'title' => 'Test Channel',
            'user_id' => $user->id,
        ]);

        $archive = Archive::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_1',
            'channel_id' => 'UC_test_channel',
            'title' => 'Test Video',
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        // 通常のタイムスタンプ（スキャン対象）
        $normalTs = TsItem::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_1',
            'comment_id' => 'test_video_1',
            'type' => '1',
            'ts_text' => '0:00',
            'ts_num' => 0,
            'text' => 'アーティスト / 曲名',
            'normalized_text' => TextNormalizer::normalize('アーティスト / 曲名'),
            'is_display' => true,
        ]);

        // 「楽曲でない」とマークされたタイムスタンプ（スキャン対象外）
        $notSongTs = TsItem::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_1',
            'comment_id' => 'test_video_1',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => 'MC / トーク',
            'normalized_text' => TextNormalizer::normalize('MC / トーク'),
            'is_display' => true,
        ]);

        // 「楽曲でない」としてマッピングを作成
        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('MC / トーク'),
            'is_not_song' => true,
            'status' => 'not_song',
        ]);

        // スキャン実行
        $count = $this->service->scanAndDecompose();

        // 1件のみスキャンされることを確認（not_songは除外）
        $this->assertEquals(1, $count);

        // 通常のタイムスタンプは分解されている
        $this->assertDatabaseHas('timestamp_decompositions', [
            'normalized_text' => TextNormalizer::normalize('アーティスト / 曲名'),
        ]);

        // 「楽曲でない」タイムスタンプは分解されていない
        $this->assertDatabaseMissing('timestamp_decompositions', [
            'normalized_text' => TextNormalizer::normalize('MC / トーク'),
        ]);
    }

    /**
     * 既にスキャン済みの「楽曲でない」タイムスタンプがgetNextPendingから除外されることをテスト
     */
    public function test_get_next_pending_excludes_not_song_timestamps(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // 通常のタイムスタンプ（表示対象）
        $normalDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('アーティスト / 曲名'),
            'original_text' => 'アーティスト / 曲名',
            'parts' => ['アーティスト', '曲名'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // スキャン済みだが「楽曲でない」とマークされたタイムスタンプ
        $notSongDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('MC / トーク'),
            'original_text' => 'MC / トーク',
            'parts' => ['MC', 'トーク'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // 「楽曲でない」としてマッピングを作成
        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('MC / トーク'),
            'is_not_song' => true,
            'status' => 'not_song',
        ]);

        // getNextPendingを実行
        $next = $this->service->getNextPending();

        // 通常のタイムスタンプのみが返される
        $this->assertNotNull($next);
        $this->assertEquals($normalDecomposition->id, $next->id);

        // 通常のタイムスタンプを処理済みにする
        $normalDecomposition->update(['status' => TimestampDecomposition::STATUS_SELECTED]);

        // 再度getNextPendingを実行すると、「楽曲でない」はスキップされてnullになる
        $next = $this->service->getNextPending();
        $this->assertNull($next);
    }

    /**
     * すでに楽曲マスタに紐付け済みのタイムスタンプがスキャン対象から除外されることをテスト
     */
    public function test_scan_excludes_linked_timestamps(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // チャンネルとアーカイブを作成
        $channel = Channel::create([
            'channel_id' => 'UC_test_channel_2',
            'handle' => '@test2',
            'title' => 'Test Channel 2',
            'user_id' => $user->id,
        ]);

        $archive = Archive::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_2',
            'channel_id' => 'UC_test_channel_2',
            'title' => 'Test Video 2',
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        // 通常のタイムスタンプ（スキャン対象）
        $normalTs = TsItem::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_2',
            'comment_id' => 'test_video_2',
            'type' => '1',
            'ts_text' => '0:00',
            'ts_num' => 0,
            'text' => '新規アーティスト / 新曲',
            'normalized_text' => TextNormalizer::normalize('新規アーティスト / 新曲'),
            'is_display' => true,
        ]);

        // すでに楽曲マスタに紐付け済みのタイムスタンプ（スキャン対象外）
        $linkedTs = TsItem::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_2',
            'comment_id' => 'test_video_2',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => '既存アーティスト / 既存曲',
            'normalized_text' => TextNormalizer::normalize('既存アーティスト / 既存曲'),
            'is_display' => true,
        ]);

        // 楽曲マスタを作成
        $song = \App\Models\Song::create([
            'id' => (string) Str::ulid(),
            'title' => '既存曲',
            'artist' => '既存アーティスト',
        ]);

        // マッピングを作成（楽曲マスタに紐付け済み）
        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('既存アーティスト / 既存曲'),
            'song_id' => $song->id,
            'is_not_song' => false,
            'is_manual' => true,
            'status' => 'linked',
        ]);

        // スキャン実行
        $count = $this->service->scanAndDecompose();

        // 1件のみスキャンされることを確認（紐付け済みは除外）
        $this->assertEquals(1, $count);

        // 通常のタイムスタンプは分解されている
        $this->assertDatabaseHas('timestamp_decompositions', [
            'normalized_text' => TextNormalizer::normalize('新規アーティスト / 新曲'),
        ]);

        // 紐付け済みのタイムスタンプは分解されていない
        $this->assertDatabaseMissing('timestamp_decompositions', [
            'normalized_text' => TextNormalizer::normalize('既存アーティスト / 既存曲'),
        ]);
    }

    /**
     * 自動紐付け済み（is_manual=false）のタイムスタンプがスキャン対象に含まれることをテスト
     */
    public function test_scan_includes_auto_linked_timestamps(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // チャンネルとアーカイブを作成
        $channel = Channel::create([
            'channel_id' => 'UC_test_channel_auto',
            'handle' => '@testauto',
            'title' => 'Test Channel Auto',
            'user_id' => $user->id,
        ]);

        $archive = Archive::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_auto',
            'channel_id' => 'UC_test_channel_auto',
            'title' => 'Test Video Auto',
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        // 自動紐付け済みのタイムスタンプ（TS分解で再処理可能）
        $autoLinkedTs = TsItem::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_auto',
            'comment_id' => 'test_video_auto',
            'type' => '1',
            'ts_text' => '0:00',
            'ts_num' => 0,
            'text' => '自動アーティスト / 自動曲',
            'normalized_text' => TextNormalizer::normalize('自動アーティスト / 自動曲'),
            'is_display' => true,
        ]);

        // 楽曲マスタを作成
        $song = \App\Models\Song::create([
            'id' => (string) Str::ulid(),
            'title' => '自動曲',
            'artist' => '自動アーティスト',
        ]);

        // 自動紐付けマッピングを作成（is_manual=false）
        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('自動アーティスト / 自動曲'),
            'song_id' => $song->id,
            'is_not_song' => false,
            'is_manual' => false,
            'status' => 'linked',
        ]);

        // スキャン実行
        $count = $this->service->scanAndDecompose();

        // 自動紐付け済みのタイムスタンプがスキャン対象に含まれる
        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('timestamp_decompositions', [
            'normalized_text' => TextNormalizer::normalize('自動アーティスト / 自動曲'),
        ]);
    }

    /**
     * すでに楽曲マスタに紐付け済みのタイムスタンプがgetNextPendingから除外されることをテスト
     */
    public function test_get_next_pending_excludes_linked_timestamps(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // 通常のタイムスタンプ（表示対象）
        $normalDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('未紐付けアーティスト / 未紐付け曲'),
            'original_text' => '未紐付けアーティスト / 未紐付け曲',
            'parts' => ['未紐付けアーティスト', '未紐付け曲'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // スキャン済みだがすでに楽曲マスタに紐付け済みのタイムスタンプ
        $linkedDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('紐付済アーティスト / 紐付済曲'),
            'original_text' => '紐付済アーティスト / 紐付済曲',
            'parts' => ['紐付済アーティスト', '紐付済曲'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // 楽曲マスタを作成
        $song = \App\Models\Song::create([
            'id' => (string) Str::ulid(),
            'title' => '紐付済曲',
            'artist' => '紐付済アーティスト',
        ]);

        // マッピングを作成（楽曲マスタに紐付け済み）
        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('紐付済アーティスト / 紐付済曲'),
            'song_id' => $song->id,
            'is_not_song' => false,
            'is_manual' => true,
            'status' => 'linked',
        ]);

        // getNextPendingを実行
        $next = $this->service->getNextPending();

        // 紐付け済みでないタイムスタンプのみが返される
        $this->assertNotNull($next);
        $this->assertEquals($normalDecomposition->id, $next->id);

        // 通常のタイムスタンプを処理済みにする
        $normalDecomposition->update(['status' => TimestampDecomposition::STATUS_SELECTED]);

        // 再度getNextPendingを実行すると、紐付け済みはスキップされてnullになる
        $next = $this->service->getNextPending();
        $this->assertNull($next);
    }

    /**
     * linkToSongが文字バリエーション（例: ' vs '）のある既存楽曲を正しく検出するテスト
     */
    public function test_link_to_song_detects_existing_song_with_character_variants(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // 既存楽曲を作成（シングルクォート ' U+0027 を使用）
        $existingSong = Song::create([
            'id' => (string) Str::ulid(),
            'title' => "Don't say \"lazy\"",
            'artist' => '桜高軽音部',
        ]);

        // 分解済みのタイムスタンプを作成（右シングルクォート ' U+2019 を使用）
        $decomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize("Don\xE2\x80\x99t say \xE2\x80\x9Clazy\xE2\x80\x9D / 桜高軽音部"),
            'original_text' => "Don\xE2\x80\x99t say \xE2\x80\x9Clazy\xE2\x80\x9D / 桜高軽音部",
            'parts' => ["Don\xE2\x80\x99t say \xE2\x80\x9Clazy\xE2\x80\x9D", '桜高軽音部'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_SELECTED,
            'confidence' => 1.0,
            'derived_title' => "Don\xE2\x80\x99t say \xE2\x80\x9Clazy\xE2\x80\x9D",
            'derived_artist' => '桜高軽音部',
            'title_part_index' => 0,
            'artist_part_index' => 1,
        ]);

        // linkToSongを実行
        $song = $this->service->linkToSong($decomposition);

        // 既存の楽曲が使用されること（新規作成されないこと）
        $this->assertNotNull($song);
        $this->assertEquals($existingSong->id, $song->id);
        $this->assertDatabaseCount('songs', 1);
    }

    /**
     * saveAsWholeTitleで全体を楽曲名として保存できることをテスト
     */
    public function test_save_as_whole_title(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // 分解済みのタイムスタンプを作成
        $decomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('Night of Fire'),
            'original_text' => 'Night of Fire',
            'parts' => ['Night', 'of', 'Fire'],
            'separator_count' => 2,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.3,
        ]);

        // 全体を楽曲名として保存
        $result = $this->service->saveAsWholeTitle($decomposition->id);

        // 結果を確認
        $saved = $result['decomposition'];
        $this->assertEquals(TimestampDecomposition::STATUS_SELECTED, $saved->status);
        $this->assertEquals('Night of Fire', $saved->derived_title);
        $this->assertNull($saved->derived_artist);
        $this->assertNull($saved->title_part_index);
        $this->assertNull($saved->artist_part_index);
    }

    /**
     * 無視キーワードを語の一部に含むアーティスト名が自動判定で捨てられないことをテスト
     *
     * "Official髭男dism" の "official" が無視キーワードとして部分一致すると、
     * アーティスト名なしで自動確定され、アーティスト名が空の楽曲マスタが作られてしまう
     */
    public function test_scan_does_not_auto_match_artist_containing_ignore_keyword(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Channel::create([
            'channel_id' => 'UC_test_channel',
            'handle' => '@test',
            'title' => 'Test Channel',
            'user_id' => $user->id,
        ]);

        Archive::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_1',
            'channel_id' => 'UC_test_channel',
            'title' => 'Test Video',
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        $text = 'ミックスナッツ / Official髭男dism';

        TsItem::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_1',
            'comment_id' => 'test_video_1',
            'type' => '1',
            'ts_text' => '0:00',
            'ts_num' => 0,
            'text' => $text,
            'normalized_text' => TextNormalizer::normalize($text),
            'is_display' => true,
        ]);

        $this->service->scanAndDecompose();

        $decomposition = TimestampDecomposition::where('normalized_text', TextNormalizer::normalize($text))->first();

        $this->assertNotNull($decomposition);
        // アーティスト名を捨てて自動確定せず、手動選別に回ること
        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $decomposition->status);
        $this->assertNull($decomposition->derived_title);
        $this->assertNull($decomposition->derived_artist);

        // アーティスト名が空の楽曲マスタが作られないこと
        $this->assertDatabaseCount('songs', 0);
    }

    /**
     * マスタに完全一致する楽曲がある場合、スキャン時にその場で自動紐付けされることをテスト
     */
    public function test_scan_auto_links_when_exact_match_exists_in_master(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $channel = Channel::create([
            'channel_id' => 'UC_exact_match',
            'handle' => '@exactmatch',
            'title' => 'Test Channel',
            'user_id' => $user->id,
        ]);

        Archive::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_exact',
            'channel_id' => 'UC_exact_match',
            'title' => 'Test Video',
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        $song = Song::create([
            'id' => (string) Str::ulid(),
            'title' => '夜に駆ける',
            'artist' => 'YOASOBI',
        ]);

        $text = 'YOASOBI / 夜に駆ける';

        TsItem::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_exact',
            'comment_id' => 'test_video_exact',
            'type' => '1',
            'ts_text' => '0:00',
            'ts_num' => 0,
            'text' => $text,
            'normalized_text' => TextNormalizer::normalize($text),
            'is_display' => true,
        ]);

        $this->service->scanAndDecompose();

        $decomposition = TimestampDecomposition::where('normalized_text', TextNormalizer::normalize($text))->first();

        $this->assertNotNull($decomposition);
        $this->assertEquals(TimestampDecomposition::STATUS_AUTO_MATCHED, $decomposition->status);
        $this->assertEquals($song->id, $decomposition->song_id);
        $this->assertEquals('夜に駆ける', $decomposition->derived_title);
        $this->assertEquals('YOASOBI', $decomposition->derived_artist);
        $this->assertEquals(1.0, $decomposition->confidence);

        // 既存の楽曲がそのまま使われ、新規作成されないこと
        $this->assertDatabaseCount('songs', 1);

        $this->assertDatabaseHas('timestamp_song_mappings', [
            'normalized_text' => TextNormalizer::normalize($text),
            'song_id' => $song->id,
        ]);
    }

    /**
     * マスタと完全一致する順序が判断できる場合のみ自動紐付けされることをテスト
     *
     * 曲名とアーティストのどちらが先かは分からないため両方の順序を試すが、
     * マスタに存在するのは片方の順序だけであることを確認する
     */
    public function test_scan_tries_both_orderings_against_master(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Channel::create([
            'channel_id' => 'UC_ordering',
            'handle' => '@ordering',
            'title' => 'Test Channel',
            'user_id' => $user->id,
        ]);

        Archive::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_ordering',
            'channel_id' => 'UC_ordering',
            'title' => 'Test Video',
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        $song = Song::create([
            'id' => (string) Str::ulid(),
            'title' => '夜に駆ける',
            'artist' => 'YOASOBI',
        ]);

        // マスタとは逆順（曲名 / アーティスト）のタイムスタンプ
        $text = '夜に駆ける / YOASOBI';

        TsItem::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_ordering',
            'comment_id' => 'test_video_ordering',
            'type' => '1',
            'ts_text' => '0:00',
            'ts_num' => 0,
            'text' => $text,
            'normalized_text' => TextNormalizer::normalize($text),
            'is_display' => true,
        ]);

        $this->service->scanAndDecompose();

        $decomposition = TimestampDecomposition::where('normalized_text', TextNormalizer::normalize($text))->first();

        $this->assertEquals(TimestampDecomposition::STATUS_AUTO_MATCHED, $decomposition->status);
        $this->assertEquals($song->id, $decomposition->song_id);
        $this->assertEquals('夜に駆ける', $decomposition->derived_title);
        $this->assertEquals('YOASOBI', $decomposition->derived_artist);
    }

    /**
     * マスタに完全一致する楽曲が無い場合は自動紐付けされないことをテスト
     */
    public function test_scan_does_not_auto_match_without_exact_master_match(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Channel::create([
            'channel_id' => 'UC_no_match',
            'handle' => '@nomatch',
            'title' => 'Test Channel',
            'user_id' => $user->id,
        ]);

        Archive::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_no_match',
            'channel_id' => 'UC_no_match',
            'title' => 'Test Video',
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        $text = 'YOASOBI / 夜に駆ける';

        TsItem::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_no_match',
            'comment_id' => 'test_video_no_match',
            'type' => '1',
            'ts_text' => '0:00',
            'ts_num' => 0,
            'text' => $text,
            'normalized_text' => TextNormalizer::normalize($text),
            'is_display' => true,
        ]);

        $this->service->scanAndDecompose();

        $decomposition = TimestampDecomposition::where('normalized_text', TextNormalizer::normalize($text))->first();

        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $decomposition->status);
        $this->assertNull($decomposition->song_id);
        $this->assertNull($decomposition->derived_title);
        $this->assertDatabaseCount('songs', 0);
    }

    /**
     * 候補が1つしか残らない場合は完全一致でも自動紐付けしないことをテスト
     *
     * 曲名かアーティストかを判断できないため、マスタに同名の完全一致があっても確定しない
     */
    public function test_scan_does_not_auto_match_when_only_one_candidate_remains(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Channel::create([
            'channel_id' => 'UC_single_candidate',
            'handle' => '@singlecandidate',
            'title' => 'Test Channel',
            'user_id' => $user->id,
        ]);

        Archive::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_single',
            'channel_id' => 'UC_single_candidate',
            'title' => 'Test Video',
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        Song::create([
            'id' => (string) Str::ulid(),
            'title' => '曲名',
            'artist' => '',
        ]);

        $text = '曲名 / cover';

        TsItem::create([
            'id' => (string) Str::ulid(),
            'video_id' => 'test_video_single',
            'comment_id' => 'test_video_single',
            'type' => '1',
            'ts_text' => '0:00',
            'ts_num' => 0,
            'text' => $text,
            'normalized_text' => TextNormalizer::normalize($text),
            'is_display' => true,
        ]);

        $this->service->scanAndDecompose();

        $decomposition = TimestampDecomposition::where('normalized_text', TextNormalizer::normalize($text))->first();

        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $decomposition->status);
        $this->assertNull($decomposition->song_id);
    }
}

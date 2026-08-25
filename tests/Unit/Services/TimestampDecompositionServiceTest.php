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
     * カスケード処理: 同じアーティストを持つpendingなタイムスタンプが処理されることをテスト
     */
    public function test_cascade_artist_selection_processes_matching_timestamps(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // ソースとなるタイムスタンプ（選別元）
        $sourceDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('星街すいせい / Stellar Stellar'),
            'original_text' => '星街すいせい / Stellar Stellar',
            'parts' => ['星街すいせい', 'Stellar Stellar'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード対象となるタイムスタンプ（同じアーティスト）
        $targetDecomposition1 = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('星街すいせい / GHOST'),
            'original_text' => '星街すいせい / GHOST',
            'parts' => ['星街すいせい', 'GHOST'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード対象となるタイムスタンプ（同じアーティスト、3パーツ）
        $targetDecomposition2 = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('星街すいせい / NEXT COLOR PLANET / cover'),
            'original_text' => '星街すいせい / NEXT COLOR PLANET / cover',
            'parts' => ['星街すいせい', 'NEXT COLOR PLANET', 'cover'],
            'separator_count' => 2,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.3,
        ]);

        // カスケード対象外のタイムスタンプ（異なるアーティスト）
        $otherDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('YOASOBI / 夜に駆ける'),
            'original_text' => 'YOASOBI / 夜に駆ける',
            'parts' => ['YOASOBI', '夜に駆ける'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード処理を実行
        $cascadedCount = $this->service->cascadeArtistSelection('星街すいせい', $sourceDecomposition->id);

        // 2件がカスケード処理されたことを確認
        $this->assertEquals(2, $cascadedCount);

        // target1が更新されたことを確認
        $target1 = $targetDecomposition1->fresh();
        $this->assertEquals(TimestampDecomposition::STATUS_AUTO_MATCHED, $target1->status);
        $this->assertEquals('星街すいせい', $target1->derived_artist);
        $this->assertEquals('GHOST', $target1->derived_title);
        $this->assertEquals(0, $target1->artist_part_index);
        $this->assertEquals(1, $target1->title_part_index);
        $this->assertEquals(0.9, $target1->confidence);

        // target2が更新されたことを確認（cover は無視キーワードなのでスキップ）
        $target2 = $targetDecomposition2->fresh();
        $this->assertEquals(TimestampDecomposition::STATUS_AUTO_MATCHED, $target2->status);
        $this->assertEquals('星街すいせい', $target2->derived_artist);
        $this->assertEquals('NEXT COLOR PLANET', $target2->derived_title);
        $this->assertEquals(0, $target2->artist_part_index);
        $this->assertEquals(1, $target2->title_part_index);

        // otherは更新されていないことを確認
        $other = $otherDecomposition->fresh();
        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $other->status);
        $this->assertNull($other->derived_artist);
        $this->assertNull($other->derived_title);
    }

    /**
     * saveSelectionでカスケード処理が実行されることをテスト
     */
    public function test_save_selection_triggers_cascade(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // ソースとなるタイムスタンプ
        $sourceDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('Ado / うっせぇわ'),
            'original_text' => 'Ado / うっせぇわ',
            'parts' => ['Ado', 'うっせぇわ'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード対象
        $targetDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('Ado / 踊'),
            'original_text' => 'Ado / 踊',
            'parts' => ['Ado', '踊'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // saveSelectionを実行（artistIndices=[0], titleIndices=[1]）
        $result = $this->service->saveSelection($sourceDecomposition->id, [1], [0]);

        // カスケード件数を確認
        $this->assertEquals(1, $result['cascaded_count']);

        // ソースが更新されたことを確認
        $source = $result['decomposition'];
        $this->assertEquals(TimestampDecomposition::STATUS_SELECTED, $source->status);
        $this->assertEquals('Ado', $source->derived_artist);
        $this->assertEquals('うっせぇわ', $source->derived_title);

        // ターゲットがカスケード処理されたことを確認
        $target = $targetDecomposition->fresh();
        $this->assertEquals(TimestampDecomposition::STATUS_AUTO_MATCHED, $target->status);
        $this->assertEquals('Ado', $target->derived_artist);
        $this->assertEquals('踊', $target->derived_title);
    }

    /**
     * saveSelectionでカスケード処理を無効化できることをテスト
     */
    public function test_save_selection_can_disable_cascade(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // ソースとなるタイムスタンプ
        $sourceDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('Ado / うっせぇわ'),
            'original_text' => 'Ado / うっせぇわ',
            'parts' => ['Ado', 'うっせぇわ'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード対象
        $targetDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('Ado / 踊'),
            'original_text' => 'Ado / 踊',
            'parts' => ['Ado', '踊'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // saveSelectionを実行（カスケード無効）
        $result = $this->service->saveSelection($sourceDecomposition->id, [1], [0], enableCascade: false);

        // カスケード件数が0であることを確認
        $this->assertEquals(0, $result['cascaded_count']);

        // ターゲットがカスケード処理されていないことを確認
        $target = $targetDecomposition->fresh();
        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $target->status);
        $this->assertNull($target->derived_artist);
    }

    /**
     * アーティストが設定されない場合はカスケード処理が実行されないことをテスト
     */
    public function test_save_selection_without_artist_does_not_cascade(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // ソースとなるタイムスタンプ
        $sourceDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('夜に駆ける / cover'),
            'original_text' => '夜に駆ける / cover',
            'parts' => ['夜に駆ける', 'cover'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // saveSelectionを実行（アーティストなし）
        $result = $this->service->saveSelection($sourceDecomposition->id, [0], []);

        // カスケード件数が0であることを確認
        $this->assertEquals(0, $result['cascaded_count']);
    }

    /**
     * 正規化されたアーティスト名でマッチングされることをテスト
     */
    public function test_cascade_matches_with_normalized_artist_name(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // ソース（全角のYOASOBI）
        $sourceDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('ＹＯＡＳＯＢＩ / 夜に駆ける'),
            'original_text' => 'ＹＯＡＳＯＢＩ / 夜に駆ける',
            'parts' => ['ＹＯＡＳＯＢＩ', '夜に駆ける'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード対象（半角のYOASOBI）
        $targetDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('YOASOBI / アイドル'),
            'original_text' => 'YOASOBI / アイドル',
            'parts' => ['YOASOBI', 'アイドル'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード処理を実行（全角のYOASOBIを指定）
        $cascadedCount = $this->service->cascadeArtistSelection('ＹＯＡＳＯＢＩ', $sourceDecomposition->id);

        // 正規化後にマッチするため1件が処理されることを確認
        $this->assertEquals(1, $cascadedCount);

        $target = $targetDecomposition->fresh();
        $this->assertEquals(TimestampDecomposition::STATUS_AUTO_MATCHED, $target->status);
        $this->assertEquals('ＹＯＡＳＯＢＩ', $target->derived_artist); // 元のアーティスト名が保持される
    }

    /**
     * カスケードの無視判定が分解画面と一致することをテスト
     *
     * 以前はキーワードとの完全一致で判定していたため、"cover2" のように
     * 分解画面ではノイズとして捨てるパーツが曲名として採用され、
     * そのまま楽曲マスタが作られていた。
     */
    public function test_cascade_ignores_parts_that_the_screen_ignores(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // 画面側では無視対象になるパーツ（キーワード＋連番）
        $this->assertTrue(TextNormalizer::isIgnorablePart('cover2'));

        // カスケードの起点（除外される側）
        $source = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('アイドル / YOASOBI'),
            'original_text' => 'アイドル / YOASOBI',
            'parts' => ['アイドル', 'YOASOBI'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        $target = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('YOASOBI / cover2'),
            'original_text' => 'YOASOBI / cover2',
            'parts' => ['YOASOBI', 'cover2'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        $this->service->cascadeArtistSelection('YOASOBI', $source->id);

        // 対象がカスケードで実際に処理されたことを確かめる
        // （処理されていなければ以下の assert は無条件に通ってしまう）
        $this->assertSame(TimestampDecomposition::STATUS_AUTO_MATCHED, $target->fresh()->status);

        $fresh = $target->fresh();

        // cover2 を曲名として採用してはいけない（採用すると
        // title=cover2 / artist=YOASOBI のゴミマスタが作られる）
        $this->assertNotEquals('cover2', $fresh->derived_title);
        $this->assertNull($fresh->title_part_index);
    }

    /**
     * 無視キーワードがアーティストとしてマッチしないことをテスト
     */
    public function test_cascade_ignores_ignore_keywords(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // ソース
        $sourceDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('cover / 夜に駆ける'),
            'original_text' => 'cover / 夜に駆ける',
            'parts' => ['cover', '夜に駆ける'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード対象（coverがパーツにある）
        $targetDecomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('cover / アイドル'),
            'original_text' => 'cover / アイドル',
            'parts' => ['cover', 'アイドル'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        // カスケード処理を実行（coverをアーティストとして指定）
        $cascadedCount = $this->service->cascadeArtistSelection('cover', $sourceDecomposition->id);

        // coverは無視キーワードなのでマッチしない
        $this->assertEquals(0, $cascadedCount);

        $target = $targetDecomposition->fresh();
        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $target->status);
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
        $this->service->bulkLinkAutoMatched();
        $this->assertDatabaseCount('songs', 0);
    }

    public function test_save_selection_preserves_trailing_separator(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $decomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('AAA -BBB-'),
            'original_text' => 'AAA -BBB-',
            'parts' => ['AAA', 'BBB'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
        ]);

        $result = $this->service->saveSelection($decomposition->id, [0, 1], []);

        $this->assertEquals('AAA -BBB-', $result['decomposition']->derived_title);
    }

    public function test_save_selection_preserves_leading_separator(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $decomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('-AAA - BBB'),
            'original_text' => '-AAA - BBB',
            'parts' => ['AAA', 'BBB'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
        ]);

        $result = $this->service->saveSelection($decomposition->id, [0, 1], []);

        $this->assertEquals('-AAA - BBB', $result['decomposition']->derived_title);
    }

    public function test_save_selection_single_part_preserves_trailing_separator(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $decomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('AAA - BBB-'),
            'original_text' => 'AAA - BBB-',
            'parts' => ['AAA', 'BBB'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
        ]);

        $result = $this->service->saveSelection($decomposition->id, [0], [1]);

        $this->assertEquals('BBB-', $result['decomposition']->derived_artist);
    }

    public function test_save_selection_middle_part_no_edge_separator(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $decomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('-AAA - BBB - CCC-'),
            'original_text' => '-AAA - BBB - CCC-',
            'parts' => ['AAA', 'BBB', 'CCC'],
            'separator_count' => 2,
            'status' => TimestampDecomposition::STATUS_PENDING,
        ]);

        $result = $this->service->saveSelection($decomposition->id, [1], [0, 2]);

        // 中間パーツにはエッジ区切り文字が付かない
        $this->assertEquals('BBB', $result['decomposition']->derived_title);
        // 先頭と末尾パーツにはエッジ区切り文字が付く（非連続なので ' / ' で結合）
        $this->assertEquals('-AAA / CCC-', $result['decomposition']->derived_artist);
    }

    /**
     * カスケードで曲名候補が複数ある場合は自動確定しない
     *
     * "RE: I AM / Aimer" のように区切り文字で曲名が割れるケースで、
     * 先頭パーツだけを曲名にしてしまう問題の防止
     */
    public function test_cascade_skips_multi_candidate_title(): void
    {
        $this->actingAs(User::factory()->create());

        // "RE: I AM / Aimer" → parts=["RE", "I AM", "Aimer"]
        $decomposition = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('RE: I AM / Aimer'),
            'original_text' => 'RE: I AM / Aimer',
            'parts' => ['RE', 'I AM', 'Aimer'],
            'separator_count' => 2,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.3,
        ]);

        // カスケード元（除外用）
        $source = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('Aimer / 残響散歌'),
            'original_text' => 'Aimer / 残響散歌',
            'parts' => ['Aimer', '残響散歌'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_SELECTED,
            'confidence' => 1.0,
        ]);

        $cascadedCount = $this->service->cascadeArtistSelection('Aimer', $source->id);

        // 曲名候補が複数あるためカスケード対象外（pendingのまま）
        $this->assertEquals(0, $cascadedCount);

        $result = $decomposition->fresh();
        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $result->status);
        $this->assertNull($result->derived_artist);
        $this->assertNull($result->derived_title);
        $this->assertNull($result->title_part_index);
    }
}

<?php

namespace Tests\Unit\Services;

use App\Models\Archive;
use App\Models\ChangeList;
use App\Models\Channel;
use App\Models\TsItem;
use App\Services\ChangeListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChangeListServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChangeListService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChangeListService;
    }

    /**
     * applyChangeListToTsItems: タイムスタンプ単位の設定がコメント単位より優先される
     */
    public function test_apply_change_list_to_ts_items_prioritizes_ts_item_level(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 同じコメント内に2つのタイムスタンプを作成（両方とも表示状態）
        $tsItem1 = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-priority-test',
            'is_display' => 1,
        ]);
        $tsItem2 = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-priority-test',
            'is_display' => 1,
        ]);

        // コメント単位で非表示に設定
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-priority-test',
            'ts_item_id' => null,
            'is_display' => 0,
        ]);

        // tsItem1だけタイムスタンプ単位で表示に設定（優先される）
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-priority-test',
            'ts_item_id' => $tsItem1->id,
            'is_display' => 1,
        ]);

        $this->service->applyChangeListToTsItems($channel->channel_id);

        // tsItem1はタイムスタンプ単位の設定（is_display=1）が優先される
        $this->assertDatabaseHas('ts_items', [
            'id' => $tsItem1->id,
            'is_display' => 1,
        ]);

        // tsItem2はコメント単位の設定（is_display=0）が適用される
        $this->assertDatabaseHas('ts_items', [
            'id' => $tsItem2->id,
            'is_display' => 0,
        ]);
    }

    /**
     * applyChangeListToTsItems: タイムスタンプ単位の設定のみが存在する場合
     */
    public function test_apply_change_list_to_ts_items_with_ts_item_level_only(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        $tsItem = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-ts-only',
            'is_display' => 1,
        ]);

        // タイムスタンプ単位で非表示に設定
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-ts-only',
            'ts_item_id' => $tsItem->id,
            'is_display' => 0,
        ]);

        $this->service->applyChangeListToTsItems($channel->channel_id);

        $this->assertDatabaseHas('ts_items', [
            'id' => $tsItem->id,
            'is_display' => 0,
        ]);
    }

    /**
     * applyChangeListToTsItems: コメント単位の設定のみが存在する場合
     */
    public function test_apply_change_list_to_ts_items_with_comment_level_only(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        $tsItem = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-comment-only',
            'is_display' => 1,
        ]);

        // コメント単位で非表示に設定
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-comment-only',
            'ts_item_id' => null,
            'is_display' => 0,
        ]);

        $this->service->applyChangeListToTsItems($channel->channel_id);

        $this->assertDatabaseHas('ts_items', [
            'id' => $tsItem->id,
            'is_display' => 0,
        ]);
    }

    /**
     * applyChangeListToArchives: アーカイブ単位の設定が適用される
     */
    public function test_apply_change_list_to_archives(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 1,
        ]);

        // アーカイブ単位で非表示に設定
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => null,
            'ts_item_id' => null,
            'is_display' => 0,
        ]);

        $this->service->applyChangeListToArchives($channel->channel_id);

        $this->assertDatabaseHas('archives', [
            'id' => $archive->id,
            'is_display' => 0,
        ]);
    }

    /**
     * deleteObsoleteChangeLists: タイムスタンプ単位の孤立レコードが削除される
     */
    public function test_delete_obsolete_change_lists_removes_orphan_ts_item_records(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 存在しないts_item_idを持つchange_listレコード
        $orphanChangeList = ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-orphan',
            'ts_item_id' => 'non-existent-ts-item-id-12345',
            'is_display' => 0,
        ]);

        $this->service->deleteObsoleteChangeLists($channel->channel_id);

        $this->assertDatabaseMissing('change_list', [
            'id' => $orphanChangeList->id,
        ]);
    }

    /**
     * deleteObsoleteChangeLists: コメント単位の孤立レコードが削除される
     */
    public function test_delete_obsolete_change_lists_removes_orphan_comment_records(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 存在しないcomment_idを持つchange_listレコード
        $orphanChangeList = ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => 'non-existent-comment-id',
            'ts_item_id' => null,
            'is_display' => 0,
        ]);

        $this->service->deleteObsoleteChangeLists($channel->channel_id);

        $this->assertDatabaseMissing('change_list', [
            'id' => $orphanChangeList->id,
        ]);
    }

    /**
     * deleteObsoleteChangeLists: 有効なレコードは削除されない
     */
    public function test_delete_obsolete_change_lists_keeps_valid_records(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        $tsItem = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'comment_id' => 'valid-comment',
        ]);

        // 有効なタイムスタンプ単位のchange_list
        $validTsItemChangeList = ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => 'valid-comment',
            'ts_item_id' => $tsItem->id,
            'is_display' => 0,
        ]);

        // 有効なコメント単位のchange_list
        $validCommentChangeList = ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => 'valid-comment',
            'ts_item_id' => null,
            'is_display' => 0,
        ]);

        // 有効なアーカイブ単位のchange_list
        $validArchiveChangeList = ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => null,
            'ts_item_id' => null,
            'is_display' => 0,
        ]);

        $this->service->deleteObsoleteChangeLists($channel->channel_id);

        // すべての有効なレコードが残っている
        $this->assertDatabaseHas('change_list', ['id' => $validTsItemChangeList->id]);
        $this->assertDatabaseHas('change_list', ['id' => $validCommentChangeList->id]);
        $this->assertDatabaseHas('change_list', ['id' => $validArchiveChangeList->id]);
    }
}

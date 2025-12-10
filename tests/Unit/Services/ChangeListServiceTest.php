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

    /**
     * updateTsItemIdsAfterRefresh: ts_text+ts_numで照合して新しいts_item_idに更新される
     */
    public function test_update_ts_item_ids_after_refresh_updates_matched_record(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 古いts_item（実際にはアーカイブ更新で削除される想定）
        $oldTsItemId = '01HTEST_OLD_TSITEM_ID_1234';

        // 新しいts_item（アーカイブ更新で再作成された想定）
        $newTsItem = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-refresh-test',
            'ts_text' => '1:23:45',
            'ts_num' => 5025,
        ]);

        // ts_text, ts_numを持つchange_listレコード（古いts_item_idを参照）
        $changeList = ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-refresh-test',
            'ts_item_id' => $oldTsItemId,
            'ts_text' => '1:23:45',
            'ts_num' => 5025,
            'is_display' => 0,
        ]);

        $this->service->updateTsItemIdsAfterRefresh($channel->channel_id);

        // change_listのts_item_idが新しいts_itemのIDに更新される
        $this->assertDatabaseHas('change_list', [
            'id' => $changeList->id,
            'ts_item_id' => $newTsItem->id,
        ]);
    }

    /**
     * updateTsItemIdsAfterRefresh: ts_text/ts_numが異なる場合は更新されない
     */
    public function test_update_ts_item_ids_after_refresh_does_not_update_unmatched_record(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        $oldTsItemId = '01HTEST_OLD_TSITEM_ID_5678';

        // 新しいts_item（ts_textが異なる）
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-unmatched-test',
            'ts_text' => '0:00:30',
            'ts_num' => 30,
        ]);

        // 古いts_textを持つchange_listレコード
        $changeList = ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-unmatched-test',
            'ts_item_id' => $oldTsItemId,
            'ts_text' => '0:00:25',  // 異なるts_text
            'ts_num' => 25,          // 異なるts_num
            'is_display' => 0,
        ]);

        $this->service->updateTsItemIdsAfterRefresh($channel->channel_id);

        // change_listのts_item_idは更新されない
        $this->assertDatabaseHas('change_list', [
            'id' => $changeList->id,
            'ts_item_id' => $oldTsItemId,
        ]);
    }

    /**
     * updateTsItemIdsAfterRefresh: ts_text/ts_numがNULLの場合はスキップされる
     */
    public function test_update_ts_item_ids_after_refresh_skips_records_without_ts_text_ts_num(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        $oldTsItemId = '01HTEST_OLD_TSITEM_ID_9999';

        // ts_text, ts_numがNULLのchange_listレコード
        $changeList = ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-null-test',
            'ts_item_id' => $oldTsItemId,
            'ts_text' => null,
            'ts_num' => null,
            'is_display' => 0,
        ]);

        $this->service->updateTsItemIdsAfterRefresh($channel->channel_id);

        // ts_item_idは変更されない
        $this->assertDatabaseHas('change_list', [
            'id' => $changeList->id,
            'ts_item_id' => $oldTsItemId,
        ]);
    }
}

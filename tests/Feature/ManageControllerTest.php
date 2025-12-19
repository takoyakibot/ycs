<?php

namespace Tests\Feature;

use App\Jobs\RefreshChannelArchivesJob;
use App\Models\Archive;
use App\Models\ChangeList;
use App\Models\Channel;
use App\Models\TsItem;
use App\Models\User;
use App\Services\RefreshArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    /**
     * スーパー管理者は全チャンネルを取得できる
     */
    public function test_fetch_channel_returns_all_channels_for_super_admin(): void
    {
        // 自分のチャンネルを作成
        $myChannels = Channel::factory()->count(3)->create(['user_id' => $this->user->id]);

        // 他のユーザーのチャンネルも作成
        $otherChannels = Channel::factory()->count(2)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/manage/channels');

        // スーパー管理者は全チャンネル（5件）を取得
        $response->assertStatus(200)
            ->assertJsonCount(5);
    }

    /**
     * 一般ユーザーは自分のチャンネルのみ取得できる
     */
    public function test_fetch_channel_returns_only_own_channels_for_regular_user(): void
    {
        $regularUser = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_USER,
        ]);

        // 自分のチャンネルを作成
        $myChannels = Channel::factory()->count(3)->create(['user_id' => $regularUser->id]);

        // 他のユーザーのチャンネルも作成（これは表示されないはず）
        Channel::factory()->count(2)->create();

        $response = $this->actingAs($regularUser)
            ->getJson('/api/manage/channels');

        $response->assertStatus(200)
            ->assertJsonCount(3) // 自分のチャンネルのみ3件
            ->assertJsonFragment(['handle' => $myChannels[0]->handle])
            ->assertJsonFragment(['handle' => $myChannels[1]->handle])
            ->assertJsonFragment(['handle' => $myChannels[2]->handle]);
    }

    /**
     * チャンネル一覧が空の場合も正常に動作する
     */
    public function test_fetch_channel_returns_empty_array_when_no_channels(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/manage/channels');

        $response->assertStatus(200)
            ->assertJsonCount(0);
    }

    /**
     * アーカイブの表示切り替えができる（表示→非表示）
     */
    public function test_toggle_display_from_visible_to_hidden(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson('/api/manage/archives/toggle-display', [
                'id' => $archive->id,
                'is_display' => '1',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('"0"', $response->getContent());

        $this->assertDatabaseHas('archives', [
            'id' => $archive->id,
            'is_display' => 0,
        ]);

        $this->assertDatabaseHas('change_list', [
            'channel_id' => $archive->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => null,
            'is_display' => 0,
        ]);
    }

    /**
     * アーカイブの表示切り替えができる（非表示→表示）
     */
    public function test_toggle_display_from_hidden_to_visible(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson('/api/manage/archives/toggle-display', [
                'id' => $archive->id,
                'is_display' => '0',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('"1"', $response->getContent());

        $this->assertDatabaseHas('archives', [
            'id' => $archive->id,
            'is_display' => 1,
        ]);

        $this->assertDatabaseHas('change_list', [
            'channel_id' => $archive->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => null,
            'is_display' => 1,
        ]);
    }

    /**
     * 存在しないアーカイブIDでエラーが発生する
     */
    public function test_toggle_display_fails_with_invalid_archive_id(): void
    {
        $response = $this->actingAs($this->user)
            ->patchJson('/api/manage/archives/toggle-display', [
                'id' => 'non-existent-id',
                'is_display' => '1',
            ]);

        $response->assertStatus(404);
    }

    /**
     * バリデーションエラー: idが必須
     */
    public function test_toggle_display_validation_id_required(): void
    {
        $response = $this->actingAs($this->user)
            ->patchJson('/api/manage/archives/toggle-display', [
                'is_display' => '1',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['id']);
    }

    /**
     * バリデーションエラー: is_displayが必須
     */
    public function test_toggle_display_validation_is_display_required(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        $response = $this->actingAs($this->user)
            ->patchJson('/api/manage/archives/toggle-display', [
                'id' => $archive->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['is_display']);
    }

    /**
     * バリデーションエラー: is_displayは0または1のみ
     */
    public function test_toggle_display_validation_is_display_must_be_0_or_1(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        $response = $this->actingAs($this->user)
            ->patchJson('/api/manage/archives/toggle-display', [
                'id' => $archive->id,
                'is_display' => '2',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['is_display']);
    }

    /**
     * タイムスタンプの編集ができる
     */
    public function test_edit_timestamps_updates_display_status(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        $tsItems = TsItem::factory()->count(3)->create([
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-123',
            'is_display' => 1,
        ]);

        $requestData = $tsItems->map(fn ($item) => [
            'id' => $item->id,
            'comment_id' => $item->comment_id,
            'is_display' => false,
        ])->toArray();

        $response = $this->actingAs($this->user)
            ->patchJson('/api/manage/archives/edit-timestamps', $requestData);

        $response->assertStatus(200)
            ->assertJson(['message' => 'タイムスタンプの編集が完了しました']);

        foreach ($tsItems as $item) {
            $this->assertDatabaseHas('ts_items', [
                'id' => $item->id,
                'is_display' => 0,
            ]);
        }

        $this->assertDatabaseHas('change_list', [
            'channel_id' => $archive->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-123',
            'is_display' => 0,
        ]);
    }

    /**
     * タイムスタンプ編集時に既存の変更リストが削除される
     */
    public function test_edit_timestamps_deletes_old_change_lists(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 既存の変更リストを作成
        ChangeList::create([
            'channel_id' => $archive->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => 'old-comment',
            'is_display' => 0,
        ]);

        // 動画の変更リスト（comment_id=null）は削除されない
        ChangeList::create([
            'channel_id' => $archive->channel_id,
            'video_id' => $archive->video_id,
            'comment_id' => null,
            'is_display' => 0,
        ]);

        $tsItems = TsItem::factory()->count(2)->create([
            'video_id' => $archive->video_id,
            'comment_id' => 'new-comment',
            'is_display' => 1,
        ]);

        $requestData = $tsItems->map(fn ($item) => [
            'id' => $item->id,
            'comment_id' => $item->comment_id,
            'is_display' => true,
        ])->toArray();

        $response = $this->actingAs($this->user)
            ->patchJson('/api/manage/archives/edit-timestamps', $requestData);

        $response->assertStatus(200);

        // 古い変更リスト（comment_id='old-comment'）が削除されている
        $this->assertDatabaseMissing('change_list', [
            'video_id' => $archive->video_id,
            'comment_id' => 'old-comment',
        ]);

        // 動画の変更リスト（comment_id=null）は残っている
        $this->assertDatabaseHas('change_list', [
            'video_id' => $archive->video_id,
            'comment_id' => null,
        ]);

        // 新しい変更リストが作成されている
        $this->assertDatabaseHas('change_list', [
            'video_id' => $archive->video_id,
            'comment_id' => 'new-comment',
        ]);
    }

    /**
     * バリデーションエラー: タイムスタンプIDが必須
     */
    public function test_edit_timestamps_validation_id_required(): void
    {
        $response = $this->actingAs($this->user)
            ->patchJson('/api/manage/archives/edit-timestamps', [
                [
                    'comment_id' => 'comment-123',
                    'is_display' => true,
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['0.id']);
    }

    /**
     * バリデーションエラー: comment_idが必須
     */
    public function test_edit_timestamps_validation_comment_id_required(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        $tsItem = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-123',
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson('/api/manage/archives/edit-timestamps', [
                [
                    'id' => $tsItem->id,
                    'is_display' => true,
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['0.comment_id']);
    }

    /**
     * バリデーションエラー: is_displayが必須
     */
    public function test_edit_timestamps_validation_is_display_required(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        $tsItem = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-123',
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson('/api/manage/archives/edit-timestamps', [
                [
                    'id' => $tsItem->id,
                    'comment_id' => $tsItem->comment_id,
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['0.is_display']);
    }

    /**
     * 一般ユーザーは他のユーザーのチャンネルへのアクセスが拒否される（show）
     */
    public function test_show_denies_access_to_other_users_channel(): void
    {
        $regularUser = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_USER,
            'api_key' => 'my-api-key',
        ]);
        $otherUser = User::factory()->create(['email_verified_at' => now(), 'api_key' => 'test-api-key']);
        $otherChannel = Channel::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($regularUser)
            ->get("/channels/manage/{$otherChannel->handle}");

        $response->assertStatus(403);
    }

    /**
     * スーパー管理者は他のユーザーのチャンネルにアクセスできる（show）
     */
    public function test_show_allows_super_admin_access_to_other_users_channel(): void
    {
        $otherUser = User::factory()->create(['email_verified_at' => now(), 'api_key' => 'test-api-key']);
        $otherChannel = Channel::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)
            ->get("/channels/manage/{$otherChannel->handle}");

        $response->assertStatus(200);
    }

    /**
     * 一般ユーザーは他のユーザーのチャンネルのアーカイブ追加が拒否される
     */
    public function test_add_archives_denies_access_to_other_users_channel(): void
    {
        $regularUser = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_USER,
        ]);
        $otherUser = User::factory()->create();
        $otherChannel = Channel::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($regularUser)
            ->postJson('/api/manage/archives', [
                'handle' => \Illuminate\Support\Facades\Crypt::encryptString($otherChannel->handle),
            ]);

        $response->assertStatus(403);
    }

    /**
     * スーパー管理者は他のユーザーのチャンネルのアーカイブを追加できる
     */
    public function test_add_archives_allows_super_admin_access_to_other_users_channel(): void
    {
        config(['queue.default' => 'sync']);

        $otherUser = User::factory()->create();
        $otherChannel = Channel::factory()->create(['user_id' => $otherUser->id]);

        $mockService = $this->mock(RefreshArchiveService::class);
        $mockService->shouldReceive('refreshArchives')
            ->once()
            ->andReturn(10);

        $response = $this->actingAs($this->user)
            ->postJson('/api/manage/archives', [
                'handle' => \Illuminate\Support\Facades\Crypt::encryptString($otherChannel->handle),
            ]);

        $response->assertStatus(200);
    }

    /**
     * sync設定では同期実行される
     */
    public function test_add_archives_runs_synchronously_when_queue_is_sync(): void
    {
        config(['queue.default' => 'sync']);

        $channel = Channel::factory()->create(['user_id' => $this->user->id]);

        $mockService = $this->mock(RefreshArchiveService::class);
        $mockService->shouldReceive('refreshArchives')
            ->once()
            ->andReturn(10);

        $response = $this->actingAs($this->user)
            ->postJson('/api/manage/archives', [
                'handle' => \Illuminate\Support\Facades\Crypt::encryptString($channel->handle),
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'アーカイブを登録しました',
                'async' => false,
            ]);
    }

    /**
     * database設定では非同期実行される（ジョブがキューにディスパッチされる）
     */
    public function test_add_archives_dispatches_job_when_queue_is_database(): void
    {
        // configを変更してからQueue::fake()を呼ぶ
        config(['queue.default' => 'database']);
        Queue::fake();

        $channel = Channel::factory()->create(['user_id' => $this->user->id]);

        // デバッグ: 設定値を確認
        $this->assertEquals('database', config('queue.default'), 'Config should be database');

        $response = $this->actingAs($this->user)
            ->postJson('/api/manage/archives', [
                'handle' => \Illuminate\Support\Facades\Crypt::encryptString($channel->handle),
            ]);

        // レスポンスの内容を確認
        $responseData = $response->json();

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'アーカイブの更新処理をキューに登録しました。完了までしばらくお待ちください。',
                'async' => true,
            ]);

        // シンプルにジョブがプッシュされたことを確認
        Queue::assertPushed(RefreshChannelArchivesJob::class);
    }

    /**
     * 同一コメント内のタイムスタンプに異なる表示設定をした場合、タイムスタンプ単位でchange_listが作成される
     */
    public function test_edit_timestamps_creates_ts_item_level_change_list(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 同じコメント内に3つのタイムスタンプを作成
        $tsItems = TsItem::factory()->count(3)->create([
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-123',
            'is_display' => 1,
        ]);

        // 最初の2つは表示、3つ目は非表示に設定（異なる設定を混在させる）
        $requestData = [
            ['id' => $tsItems[0]->id, 'comment_id' => $tsItems[0]->comment_id, 'is_display' => true],
            ['id' => $tsItems[1]->id, 'comment_id' => $tsItems[1]->comment_id, 'is_display' => true],
            ['id' => $tsItems[2]->id, 'comment_id' => $tsItems[2]->comment_id, 'is_display' => false],
        ];

        $response = $this->actingAs($this->user)
            ->patchJson('/api/manage/archives/edit-timestamps', $requestData);

        $response->assertStatus(200);

        // ts_itemsの表示状態が更新されている
        $this->assertDatabaseHas('ts_items', ['id' => $tsItems[0]->id, 'is_display' => 1]);
        $this->assertDatabaseHas('ts_items', ['id' => $tsItems[1]->id, 'is_display' => 1]);
        $this->assertDatabaseHas('ts_items', ['id' => $tsItems[2]->id, 'is_display' => 0]);

        // タイムスタンプ単位でchange_listが作成されている（ts_item_idが設定されている）
        $this->assertDatabaseHas('change_list', [
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-123',
            'ts_item_id' => $tsItems[0]->id,
            'is_display' => 1,
        ]);
        $this->assertDatabaseHas('change_list', [
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-123',
            'ts_item_id' => $tsItems[1]->id,
            'is_display' => 1,
        ]);
        $this->assertDatabaseHas('change_list', [
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-123',
            'ts_item_id' => $tsItems[2]->id,
            'is_display' => 0,
        ]);

        // コメント単位のchange_list（ts_item_id=null）が作成されていない
        $this->assertDatabaseMissing('change_list', [
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-123',
            'ts_item_id' => null,
        ]);
    }

    /**
     * 同一コメント内のタイムスタンプが全て同じ表示設定の場合、コメント単位でchange_listが作成される
     */
    public function test_edit_timestamps_creates_comment_level_change_list_when_all_same(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 同じコメント内に3つのタイムスタンプを作成
        $tsItems = TsItem::factory()->count(3)->create([
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-456',
            'is_display' => 1,
        ]);

        // 全て非表示に設定
        $requestData = $tsItems->map(fn ($item) => [
            'id' => $item->id,
            'comment_id' => $item->comment_id,
            'is_display' => false,
        ])->toArray();

        $response = $this->actingAs($this->user)
            ->patchJson('/api/manage/archives/edit-timestamps', $requestData);

        $response->assertStatus(200);

        // コメント単位でchange_listが作成されている（ts_item_id=null）
        $this->assertDatabaseHas('change_list', [
            'video_id' => $archive->video_id,
            'comment_id' => 'comment-456',
            'ts_item_id' => null,
            'is_display' => 0,
        ]);

        // タイムスタンプ単位のchange_list（ts_item_idが設定されている）が作成されていない
        $count = ChangeList::where('video_id', $archive->video_id)
            ->where('comment_id', 'comment-456')
            ->whereNotNull('ts_item_id')
            ->count();
        $this->assertEquals(0, $count);
    }
}

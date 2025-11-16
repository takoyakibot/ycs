<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\ChangeList;
use App\Models\Channel;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email_verified_at' => now()]);
    }

    /**
     * チャンネル一覧を取得できる（自分のチャンネルのみ）
     */
    public function test_fetch_channel_returns_all_channels(): void
    {
        // 自分のチャンネルを作成
        $channels = Channel::factory()->count(3)->create(['user_id' => $this->user->id]);

        // 他のユーザーのチャンネルも作成（これは表示されないはず）
        Channel::factory()->count(2)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/manage/channels');

        $response->assertStatus(200)
            ->assertJsonCount(3) // 自分のチャンネルのみ3件
            ->assertJsonFragment(['handle' => $channels[0]->handle])
            ->assertJsonFragment(['handle' => $channels[1]->handle])
            ->assertJsonFragment(['handle' => $channels[2]->handle]);
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
     * 他のユーザーのチャンネルへのアクセスは拒否される（show）
     */
    public function test_show_denies_access_to_other_users_channel(): void
    {
        $otherUser = User::factory()->create(['email_verified_at' => now(), 'api_key' => 'test-api-key']);
        $otherChannel = Channel::factory()->create(['user_id' => $otherUser->id]);

        // 自分のユーザーにもapi_keyを設定
        $this->user->api_key = 'my-api-key';
        $this->user->save();

        $response = $this->actingAs($this->user)
            ->get("/channels/manage/{$otherChannel->handle}");

        $response->assertStatus(403);
    }

    /**
     * 他のユーザーのチャンネルのアーカイブ追加は拒否される
     */
    public function test_add_archives_denies_access_to_other_users_channel(): void
    {
        $otherUser = User::factory()->create();
        $otherChannel = Channel::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/manage/archives', [
                'handle' => \Illuminate\Support\Facades\Crypt::encryptString($otherChannel->handle),
            ]);

        $response->assertStatus(403);
    }
}

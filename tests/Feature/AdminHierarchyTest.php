<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\NormalizationLog;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminHierarchyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 登録ユーザーは管理機能にアクセスできる
     *
     * Note: 登録ユーザーは全て管理者として扱う
     */
    public function test_registered_user_can_access_manage_routes(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $response = $this->actingAs($user)->get('/channels/manage');
        $response->assertStatus(200);

        $response = $this->actingAs($user)->get('/songs/normalize');
        $response->assertStatus(200);

        $response = $this->actingAs($user)->getJson('/api/manage/channels');
        $response->assertStatus(200);
    }

    /**
     * 登録ユーザーはスーパー管理者専用機能にアクセスできない（リダイレクトされる）
     */
    public function test_registered_user_cannot_access_super_admin_routes(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        // HTMLリクエストはトップページにリダイレクト
        $response = $this->actingAs($user)->get('/manage/logs');
        $response->assertRedirect(route('top'));
        $response->assertSessionHas('error');

        $response = $this->actingAs($user)->get('/manage/reports');
        $response->assertRedirect(route('top'));

        $response = $this->actingAs($user)->get('/manage/admins');
        $response->assertRedirect(route('top'));
    }

    /**
     * スーパー管理者は全機能にアクセスできる
     */
    public function test_super_admin_can_access_all_routes(): void
    {
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        // 一般管理機能
        $response = $this->actingAs($superAdmin)->get('/channels/manage');
        $response->assertStatus(200);

        $response = $this->actingAs($superAdmin)->get('/songs/normalize');
        $response->assertStatus(200);

        // スーパー管理者専用機能
        $response = $this->actingAs($superAdmin)->get('/manage/logs');
        $response->assertStatus(200);

        $response = $this->actingAs($superAdmin)->get('/manage/reports');
        $response->assertStatus(200);

        $response = $this->actingAs($superAdmin)->get('/manage/admins');
        $response->assertStatus(200);
    }

    /**
     * 環境変数で指定されたメールアドレスのユーザーはスーパー管理者として扱われる
     */
    public function test_user_with_super_admin_email_is_super_admin(): void
    {
        config(['auth.super_admin_email' => 'super@example.com']);

        $user = User::factory()->create([
            'email' => 'super@example.com',
            'role' => User::ROLE_USER,
        ]);

        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue($user->canAccessSuperAdminFeatures());

        // スーパー管理者専用機能にアクセスできる
        $response = $this->actingAs($user)->get('/manage/admins');
        $response->assertStatus(200);
    }

    /**
     * 管理者一覧APIのテスト
     */
    public function test_fetch_admins_returns_admin_users(): void
    {
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $admin1 = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $admin2 = User::factory()->create(['role' => User::ROLE_ADMIN]);
        User::factory()->create(['role' => User::ROLE_USER]);

        $response = $this->actingAs($superAdmin)->getJson('/api/manage/admins');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        $adminIds = collect($data)->pluck('id')->toArray();
        $this->assertContains($admin1->id, $adminIds);
        $this->assertContains($admin2->id, $adminIds);
    }

    /**
     * 管理者追加APIのテスト
     */
    public function test_store_admin_promotes_user_to_admin(): void
    {
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $response = $this->actingAs($superAdmin)->postJson('/api/manage/admins', [
            'email' => $user->email,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => User::ROLE_ADMIN,
        ]);
    }

    /**
     * 存在しないメールアドレスでは管理者追加できない
     */
    public function test_store_admin_fails_for_nonexistent_user(): void
    {
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($superAdmin)->postJson('/api/manage/admins', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(404);
    }

    /**
     * 既に管理者のユーザーは再追加できない
     */
    public function test_store_admin_fails_for_existing_admin(): void
    {
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($superAdmin)->postJson('/api/manage/admins', [
            'email' => $admin->email,
        ]);

        $response->assertStatus(422);
    }

    /**
     * 管理者権限削除APIのテスト
     */
    public function test_destroy_admin_demotes_admin_to_user(): void
    {
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($superAdmin)->deleteJson("/api/manage/admins/{$admin->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'role' => User::ROLE_USER,
        ]);
    }

    /**
     * スーパー管理者の権限は削除できない
     */
    public function test_destroy_admin_fails_for_super_admin(): void
    {
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $anotherSuperAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($superAdmin)->deleteJson("/api/manage/admins/{$anotherSuperAdmin->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('users', [
            'id' => $anotherSuperAdmin->id,
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    // ==========================================
    // 正規化操作ログのテスト
    // ==========================================

    /**
     * 楽曲作成時にログが記録される
     */
    public function test_song_creation_logs_action(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->assertDatabaseCount('normalization_logs', 0);

        $response = $this->actingAs($user)->postJson('/api/songs', [
            'title' => 'テスト楽曲',
            'artist' => 'テストアーティスト',
        ]);

        $response->assertStatus(201);

        // ログが記録されていることを確認
        $this->assertDatabaseCount('normalization_logs', 1);
        $this->assertDatabaseHas('normalization_logs', [
            'user_id' => $user->id,
            'action' => NormalizationLog::ACTION_CREATE_SONG,
            'target_type' => NormalizationLog::TARGET_SONG,
        ]);
    }

    /**
     * マッピング作成時にログが記録される（link）
     */
    public function test_link_timestamp_logs_action(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $song = Song::factory()->create();

        $this->assertDatabaseCount('normalization_logs', 0);

        $response = $this->actingAs($user)->postJson('/api/songs/link', [
            'normalized_text' => 'test song',
            'song_id' => $song->id,
        ]);

        $response->assertStatus(200);

        // ログが記録されていることを確認
        $this->assertDatabaseHas('normalization_logs', [
            'user_id' => $user->id,
            'action' => NormalizationLog::ACTION_LINK,
            'target_type' => NormalizationLog::TARGET_MAPPING,
        ]);
    }

    /**
     * マッピング解除時にログが記録される（unlink）
     */
    public function test_unlink_timestamp_logs_action(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $song = Song::factory()->create();
        $mapping = TimestampSongMapping::factory()
            ->withSong($song)
            ->withText('test song')
            ->create();

        $response = $this->actingAs($user)->deleteJson('/api/songs/unlink', [
            'normalized_text' => $mapping->normalized_text,
        ]);

        $response->assertStatus(200);

        // ログが記録されていることを確認
        $this->assertDatabaseHas('normalization_logs', [
            'user_id' => $user->id,
            'action' => NormalizationLog::ACTION_UNLINK,
            'target_type' => NormalizationLog::TARGET_MAPPING,
        ]);
    }

    /**
     * 「楽曲ではない」マーク時にログが記録される（mark_not_song）
     */
    public function test_mark_as_not_song_logs_action(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $response = $this->actingAs($user)->postJson('/api/songs/mark-not-song', [
            'normalized_text' => 'not a song',
        ]);

        $response->assertStatus(200);

        // ログが記録されていることを確認
        $this->assertDatabaseHas('normalization_logs', [
            'user_id' => $user->id,
            'action' => NormalizationLog::ACTION_MARK_NOT_SONG,
            'target_type' => NormalizationLog::TARGET_MAPPING,
        ]);
    }

    /**
     * ログのdetailsフィールドに適切なデータが保存される
     */
    public function test_log_details_contains_proper_data(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $song = Song::factory()->create();

        $this->actingAs($user)->postJson('/api/songs/link', [
            'normalized_text' => 'test song detail',
            'song_id' => $song->id,
        ]);

        $log = NormalizationLog::where('action', NormalizationLog::ACTION_LINK)->first();

        $this->assertNotNull($log->details);
        $this->assertEquals('test song detail', $log->details['normalized_text']);
        $this->assertEquals($song->id, $log->details['song_id']);
    }

    /**
     * ユーザー削除時にログも削除される（cascadeOnDelete）
     *
     * Note: UserモデルはSoftDeletesを使用しているため、forceDelete()で完全削除する必要がある
     * SQLiteではデフォルトで外部キー制約が無効のため、DB::statementで明示的に有効化
     */
    public function test_user_deletion_cascades_logs(): void
    {
        // SQLiteで外部キー制約を有効化
        if (config('database.default') === 'sqlite') {
            \DB::statement('PRAGMA foreign_keys = ON;');
        }

        $user = User::factory()->create(['role' => User::ROLE_USER]);

        // ログを作成
        NormalizationLog::log(
            $user->id,
            NormalizationLog::ACTION_LINK,
            NormalizationLog::TARGET_MAPPING,
            Str::ulid(),
            ['test' => 'data']
        );

        $this->assertDatabaseCount('normalization_logs', 1);

        // ユーザーを完全削除（SoftDeletesを使用しているためforceDelete）
        $user->forceDelete();

        // ログも削除されていることを確認（cascadeOnDelete）
        $this->assertDatabaseCount('normalization_logs', 0);
    }

    // ==========================================
    // Channel Admin権限分離のテスト
    // ==========================================

    /**
     * Super Adminは全チャンネルのタイムスタンプを表示できる
     */
    public function test_super_admin_can_view_all_channel_timestamps(): void
    {
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $otherUser = User::factory()->create(['role' => User::ROLE_USER]);

        // 他のユーザーのチャンネルとタイムスタンプを作成
        $channel = Channel::factory()->create(['user_id' => $otherUser->id]);
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => true,
        ]);
        $tsItem = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Other user song',
            'normalized_text' => 'other user song',
            'is_display' => true,
        ]);

        $response = $this->actingAs($superAdmin)->getJson('/api/songs/timestamps');

        $response->assertStatus(200);
        $this->assertTrue(
            collect($response->json('data'))->contains('id', $tsItem->id),
            'Super Admin should see timestamps from all channels'
        );
    }

    /**
     * Channel Adminは自分のチャンネルのタイムスタンプのみ表示できる
     */
    public function test_channel_admin_can_only_view_own_channel_timestamps(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        // 自分のチャンネルとタイムスタンプを作成
        $ownChannel = Channel::factory()->create(['user_id' => $user->id]);
        $ownArchive = Archive::factory()->create([
            'channel_id' => $ownChannel->channel_id,
            'is_display' => true,
        ]);
        $ownTsItem = TsItem::factory()->create([
            'video_id' => $ownArchive->video_id,
            'text' => 'Own song',
            'normalized_text' => 'own song',
            'is_display' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/songs/timestamps');

        $response->assertStatus(200);
        $this->assertTrue(
            collect($response->json('data'))->contains('id', $ownTsItem->id),
            'Channel Admin should see their own channel timestamps'
        );
    }

    /**
     * Channel Adminは他人のチャンネルのタイムスタンプを表示できない
     */
    public function test_channel_admin_cannot_view_other_user_timestamps(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $otherUser = User::factory()->create(['role' => User::ROLE_USER]);

        // 自分のチャンネルを作成（タイムスタンプなし）
        $ownChannel = Channel::factory()->create(['user_id' => $user->id]);

        // 他のユーザーのチャンネルとタイムスタンプを作成
        $otherChannel = Channel::factory()->create(['user_id' => $otherUser->id]);
        $otherArchive = Archive::factory()->create([
            'channel_id' => $otherChannel->channel_id,
            'is_display' => true,
        ]);
        $otherTsItem = TsItem::factory()->create([
            'video_id' => $otherArchive->video_id,
            'text' => 'Other user song',
            'normalized_text' => 'other user song',
            'is_display' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/songs/timestamps');

        $response->assertStatus(200);
        $this->assertFalse(
            collect($response->json('data'))->contains('id', $otherTsItem->id),
            'Channel Admin should NOT see other users timestamps'
        );
    }
}

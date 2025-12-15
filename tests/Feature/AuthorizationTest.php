<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 未認証ユーザーは管理画面にアクセスできない
     */
    public function test_guest_cannot_access_manage_pages(): void
    {
        $response = $this->get('/channels/manage');
        $response->assertRedirect('/login');

        $response = $this->get('/songs/normalize');
        $response->assertRedirect('/login');

        $response = $this->get('/manage/logs');
        $response->assertRedirect('/login');
    }

    /**
     * 未認証ユーザーは管理APIにアクセスできない
     */
    public function test_guest_cannot_access_manage_api(): void
    {
        // チャンネル管理API
        $response = $this->getJson('/api/manage/channels');
        $response->assertStatus(401);

        $response = $this->postJson('/api/manage/channels', ['handle' => 'test']);
        $response->assertStatus(401);

        $channel = Channel::factory()->create();
        $response = $this->getJson("/api/manage/channels/{$channel->handle}");
        $response->assertStatus(401);

        $response = $this->postJson('/api/manage/archives', ['channel_id' => 'test']);
        $response->assertStatus(401);

        $response = $this->patchJson('/api/manage/archives/toggle-display', [
            'channel_id' => 'test',
            'video_id' => 'test',
        ]);
        $response->assertStatus(401);

        // 楽曲マスタAPI
        $response = $this->getJson('/api/songs/timestamps');
        $response->assertStatus(401);

        $response = $this->getJson('/api/songs');
        $response->assertStatus(401);

        $response = $this->postJson('/api/songs', [
            'title' => 'Test',
            'artist' => 'Test',
        ]);
        $response->assertStatus(401);

        $response = $this->postJson('/api/songs/link', [
            'normalized_text' => 'test',
            'song_id' => 'test',
        ]);
        $response->assertStatus(401);

        $response = $this->deleteJson('/api/songs/unlink', [
            'normalized_text' => 'test',
        ]);
        $response->assertStatus(401);

        $response = $this->deleteJson('/api/songs/test-id');
        $response->assertStatus(401);
    }

    /**
     * メール未認証でも管理者権限があれば管理画面にアクセスできる
     *
     * Note: メール認証は不要としています（Issue #196でOption Bを選択）
     * 管理者権限（admin以上）で管理画面にアクセス可能です。
     */
    public function test_unverified_admin_can_access_manage_pages(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $response = $this->actingAs($user)->get('/channels/manage');
        $response->assertStatus(200);

        $response = $this->actingAs($user)->get('/manage/logs');
        $response->assertStatus(200);
    }

    /**
     * 一般ユーザーは管理画面にアクセスできない
     */
    public function test_regular_user_cannot_access_manage_pages(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->get('/channels/manage');
        $response->assertStatus(403);

        $response = $this->actingAs($user)->get('/manage/logs');
        $response->assertStatus(403);
    }

    /**
     * メール未認証でも管理者権限があれば管理APIにアクセスできる
     *
     * Note: メール認証は不要としています（Issue #196でOption Bを選択）
     */
    public function test_unverified_admin_can_access_manage_api(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $response = $this->actingAs($user)->getJson('/api/manage/channels');
        $response->assertStatus(200);

        $response = $this->actingAs($user)->getJson('/api/songs/timestamps');
        $response->assertStatus(200);
    }

    /**
     * 管理者権限を持つ認証済みユーザーは管理APIにアクセスできる
     */
    public function test_admin_can_access_manage_api(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);

        $response = $this->actingAs($user)->getJson('/api/manage/channels');
        $response->assertStatus(200);

        $response = $this->actingAs($user)->getJson('/api/songs/timestamps');
        $response->assertStatus(200);
    }

    /**
     * 一般ユーザーは管理APIにアクセスできない
     */
    public function test_regular_user_cannot_access_manage_api(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/manage/channels');
        $response->assertStatus(403);

        $response = $this->actingAs($user)->getJson('/api/songs/timestamps');
        $response->assertStatus(403);
    }

    /**
     * 未認証ユーザーでも公開ページにアクセスできる
     */
    public function test_guest_can_access_public_pages(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);

        $response = $this->get('/channels');
        $response->assertStatus(200);

        $channel = Channel::factory()->create();
        $response = $this->get("/channels/{$channel->handle}");
        $response->assertStatus(200);

        $response = $this->get('/terms');
        $response->assertStatus(200);
    }

    /**
     * 未認証ユーザーでも公開APIにアクセスできる
     */
    public function test_guest_can_access_public_api(): void
    {
        $channel = Channel::factory()->create();

        $response = $this->getJson("/api/channels/{$channel->handle}");
        $response->assertStatus(200);

        $response = $this->getJson("/api/channels/{$channel->handle}/timestamps");
        $response->assertStatus(200);
    }

    /**
     * プロフィールページは認証が必要だがメール認証は不要
     */
    public function test_profile_requires_auth_but_not_email_verification(): void
    {
        // 未認証ユーザー
        $response = $this->get('/profile');
        $response->assertRedirect('/login');

        // メール未認証ユーザー
        $unverifiedUser = User::factory()->create(['email_verified_at' => null]);
        $response = $this->actingAs($unverifiedUser)->get('/profile');
        $response->assertStatus(200); // アクセス可能

        // 認証済みユーザー
        $verifiedUser = User::factory()->create(['email_verified_at' => now()]);
        $response = $this->actingAs($verifiedUser)->get('/profile');
        $response->assertStatus(200);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

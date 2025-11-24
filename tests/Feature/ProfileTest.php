<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_api_key_can_be_updated(): void
    {
        $user = User::factory()->create();

        // AIza + 35文字 = 39文字（テスト用ダミーキー）
        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'api_key' => 'AIza_FAKE_KEY_FOR_TESTING_0000000000000',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertNotNull($user->api_key);
    }

    public function test_api_key_can_be_cleared_by_empty_string(): void
    {
        $user = User::factory()->create([
            'api_key' => 'AIza_FAKE_KEY_FOR_TESTING_0000000000000',
        ]);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'api_key' => '',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNull($user->refresh()->api_key);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertTrue($user->fresh()->trashed());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    public function test_user_with_google_token_can_delete_account(): void
    {
        // Google OAuthトークンを持つユーザーを作成
        $user = User::factory()->create([
            'google_token' => [
                'access_token' => 'test_access_token',
                'refresh_token' => 'test_refresh_token',
            ],
        ]);

        // アカウント削除を実行
        // 注: 実際のGoogle APIへの接続は行われない（無効なトークンのため）が、
        // エラーハンドリングにより削除処理は正常に完了する
        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertTrue($user->fresh()->trashed());
    }
}

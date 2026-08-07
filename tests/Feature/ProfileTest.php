<?php

namespace Tests\Feature;

use App\Http\Controllers\ProfileController;
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

    public function test_api_token_can_be_created(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post('/profile/api-token', ['token_name' => 'Chrome拡張']);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile')
            ->assertSessionHas('new_api_token');

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_creating_api_token_does_not_revoke_existing_tokens(): void
    {
        $user = User::factory()->create();
        $first = $user->createToken('1つ目')->accessToken;

        $this
            ->actingAs($user)
            ->post('/profile/api-token', ['token_name' => '2つ目'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $user->tokens()->count());
        $this->assertNotNull($user->tokens()->whereKey($first->id)->first());
    }

    public function test_api_token_cannot_exceed_the_limit(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= ProfileController::MAX_API_TOKENS; $i++) {
            $user->createToken("token{$i}");
        }

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->post('/profile/api-token', ['token_name' => '上限超過']);

        $response
            ->assertSessionHasErrors('token_name')
            ->assertRedirect('/profile');

        // 既存トークンは影響を受けない
        $this->assertSame(ProfileController::MAX_API_TOKENS, $user->tokens()->count());
    }

    public function test_api_token_can_be_revoked_individually(): void
    {
        $user = User::factory()->create();
        $keep = $user->createToken('残す')->accessToken;
        $revoke = $user->createToken('消す')->accessToken;

        $response = $this
            ->actingAs($user)
            ->delete('/profile/api-token/'.$revoke->id);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNull($user->tokens()->whereKey($revoke->id)->first());
        $this->assertNotNull($user->tokens()->whereKey($keep->id)->first());
    }

    public function test_user_cannot_revoke_another_users_api_token(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherToken = $other->createToken('他人のトークン')->accessToken;

        $response = $this
            ->actingAs($user)
            ->delete('/profile/api-token/'.$otherToken->id);

        $response->assertNotFound();

        $this->assertNotNull($other->tokens()->whereKey($otherToken->id)->first());
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

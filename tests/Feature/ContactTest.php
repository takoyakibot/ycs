<?php

namespace Tests\Feature;

use App\Mail\ContactFormMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // テスト時はメール送信をfake
        Mail::fake();
    }

    public function test_contact_page_is_displayed(): void
    {
        $response = $this->get('/contact');

        $response->assertOk();
        $response->assertViewIs('contact.index');
    }

    public function test_contact_form_can_be_submitted(): void
    {
        // reCAPTCHA検証をモック（設定がない場合はスキップされる）
        config(['services.recaptcha.secret_key' => null]);

        // メール送信先を設定
        config(['mail.admin_address' => 'admin@example.com']);

        $response = $this->post('/contact', [
            'name' => 'テストユーザー',
            'email' => 'test@example.com',
            'category' => 'general',
            'message' => 'これはテストメッセージです。お問い合わせ内容をここに記載します。',
            'recaptcha_token' => 'test_token',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/contact');

        // メールが送信されたことを確認
        Mail::assertSent(ContactFormMail::class, function ($mail) {
            return $mail->hasTo('admin@example.com')
                && $mail->contactData['email'] === 'test@example.com'
                && $mail->contactData['category'] === 'general';
        });
    }

    public function test_contact_form_without_name(): void
    {
        config(['services.recaptcha.secret_key' => null]);
        config(['mail.admin_address' => 'admin@example.com']);

        $response = $this->post('/contact', [
            'email' => 'test@example.com',
            'category' => 'bug',
            'message' => 'これは不具合報告のテストメッセージです。',
            'recaptcha_token' => 'test_token',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/contact');

        Mail::assertSent(ContactFormMail::class);
    }

    public function test_contact_form_requires_email(): void
    {
        config(['services.recaptcha.secret_key' => null]);

        $response = $this->post('/contact', [
            'name' => 'テストユーザー',
            'category' => 'general',
            'message' => 'これはテストメッセージです。',
            'recaptcha_token' => 'test_token',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_contact_form_requires_valid_email(): void
    {
        config(['services.recaptcha.secret_key' => null]);

        $response = $this->post('/contact', [
            'email' => 'invalid-email',
            'category' => 'general',
            'message' => 'これはテストメッセージです。',
            'recaptcha_token' => 'test_token',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_contact_form_requires_category(): void
    {
        config(['services.recaptcha.secret_key' => null]);

        $response = $this->post('/contact', [
            'email' => 'test@example.com',
            'message' => 'これはテストメッセージです。',
            'recaptcha_token' => 'test_token',
        ]);

        $response->assertSessionHasErrors('category');
    }

    public function test_contact_form_requires_valid_category(): void
    {
        config(['services.recaptcha.secret_key' => null]);

        $response = $this->post('/contact', [
            'email' => 'test@example.com',
            'category' => 'invalid_category',
            'message' => 'これはテストメッセージです。',
            'recaptcha_token' => 'test_token',
        ]);

        $response->assertSessionHasErrors('category');
    }

    public function test_contact_form_requires_message(): void
    {
        config(['services.recaptcha.secret_key' => null]);

        $response = $this->post('/contact', [
            'email' => 'test@example.com',
            'category' => 'general',
            'recaptcha_token' => 'test_token',
        ]);

        $response->assertSessionHasErrors('message');
    }

    public function test_contact_form_message_min_length(): void
    {
        config(['services.recaptcha.secret_key' => null]);

        $response = $this->post('/contact', [
            'email' => 'test@example.com',
            'category' => 'general',
            'message' => '短い',
            'recaptcha_token' => 'test_token',
        ]);

        $response->assertSessionHasErrors('message');
    }

    public function test_contact_form_requires_recaptcha_token_when_configured(): void
    {
        // reCAPTCHAが設定されている場合はトークンが必須
        $this->app['config']->set('services.recaptcha.secret_key', 'test_secret');

        $response = $this->post('/contact', [
            'email' => 'test@example.com',
            'category' => 'general',
            'message' => 'これはテストメッセージです。',
        ]);

        $response->assertSessionHasErrors('recaptcha_token');
    }

    public function test_contact_form_works_without_recaptcha_when_not_configured(): void
    {
        // reCAPTCHAが設定されていない場合はトークン不要
        $this->app['config']->set('services.recaptcha.secret_key', null);
        $this->app['config']->set('mail.admin_address', 'admin@example.com');

        $response = $this->post('/contact', [
            'email' => 'test@example.com',
            'category' => 'general',
            'message' => 'これはテストメッセージです。reCAPTCHAなし。',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/contact');

        Mail::assertSent(ContactFormMail::class);
    }

    public function test_contact_form_recaptcha_verification(): void
    {
        // reCAPTCHA設定を有効化
        $this->app['config']->set('services.recaptcha.secret_key', 'test_secret');
        $this->app['config']->set('mail.admin_address', 'admin@example.com');

        // reCAPTCHA API レスポンスをモック
        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'score' => 0.9,
            ], 200),
        ]);

        $response = $this->post('/contact', [
            'email' => 'test@example.com',
            'category' => 'general',
            'message' => 'これはテストメッセージです。reCAPTCHA検証付き。',
            'recaptcha_token' => 'valid_token',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/contact');

        Mail::assertSent(ContactFormMail::class);
    }

    public function test_contact_form_recaptcha_fails_low_score(): void
    {
        // configを先に設定してからfakeを設定
        $this->app['config']->set('services.recaptcha.secret_key', 'test_secret');

        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'score' => 0.2, // 低スコア（ボット判定）
            ], 200),
        ]);

        $response = $this->post('/contact', [
            'email' => 'test@example.com',
            'category' => 'general',
            'message' => 'これはテストメッセージです。',
            'recaptcha_token' => 'low_score_token',
        ]);

        $response->assertSessionHasErrors('recaptcha_token');
    }

    public function test_contact_form_recaptcha_fails_invalid_response(): void
    {
        $this->app['config']->set('services.recaptcha.secret_key', 'test_secret');

        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => false,
            ], 200),
        ]);

        $response = $this->post('/contact', [
            'email' => 'test@example.com',
            'category' => 'general',
            'message' => 'これはテストメッセージです。',
            'recaptcha_token' => 'invalid_token',
        ]);

        $response->assertSessionHasErrors('recaptcha_token');
    }

    public function test_contact_form_no_mail_sent_without_admin_address(): void
    {
        config(['services.recaptcha.secret_key' => null]);
        config(['mail.admin_address' => null]);

        $response = $this->post('/contact', [
            'email' => 'test@example.com',
            'category' => 'general',
            'message' => 'これはテストメッセージです。管理者メールなし。',
            'recaptcha_token' => 'test_token',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/contact');

        // メールは送信されない
        Mail::assertNothingSent();
    }

    public function test_contact_form_all_categories(): void
    {
        config(['services.recaptcha.secret_key' => null]);
        config(['mail.admin_address' => 'admin@example.com']);

        $categories = ['general', 'bug', 'feature', 'other'];

        foreach ($categories as $category) {
            Mail::fake();

            $response = $this->post('/contact', [
                'email' => 'test@example.com',
                'category' => $category,
                'message' => "これは{$category}カテゴリのテストメッセージです。",
                'recaptcha_token' => 'test_token',
            ]);

            $response->assertSessionHasNoErrors();
            Mail::assertSent(ContactFormMail::class);
        }
    }
}

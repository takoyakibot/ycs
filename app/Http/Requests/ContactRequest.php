<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Http;

class ContactRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // reCAPTCHAが設定されている場合のみトークンを必須にする
        $recaptchaRules = config('services.recaptcha.secret_key')
            ? ['required', 'string']
            : ['nullable', 'string'];

        return [
            'name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'category' => ['required', 'string', 'in:general,bug,feature,other'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'recaptcha_token' => $recaptchaRules,
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'name' => 'お名前',
            'email' => 'メールアドレス',
            'category' => 'お問い合わせ種別',
            'message' => 'お問い合わせ内容',
            'recaptcha_token' => 'reCAPTCHA',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'email.required' => 'メールアドレスを入力してください。',
            'email.email' => '有効なメールアドレスを入力してください。',
            'category.required' => 'お問い合わせ種別を選択してください。',
            'category.in' => '有効なお問い合わせ種別を選択してください。',
            'message.required' => 'お問い合わせ内容を入力してください。',
            'message.min' => 'お問い合わせ内容は10文字以上で入力してください。',
            'message.max' => 'お問い合わせ内容は5000文字以内で入力してください。',
            'recaptcha_token.required' => 'reCAPTCHA認証が必要です。ページを再読み込みしてください。',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->verifyRecaptcha()) {
                $validator->errors()->add('recaptcha_token', 'reCAPTCHA認証に失敗しました。もう一度お試しください。');
            }
        });
    }

    /**
     * Verify reCAPTCHA token with Google.
     */
    protected function verifyRecaptcha(): bool
    {
        $token = $this->input('recaptcha_token');
        $secret = config('services.recaptcha.secret_key');

        // 設定がない場合はスキップ（開発環境など）
        if (empty($secret)) {
            return true;
        }

        try {
            $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $this->ip(),
            ]);

            $result = $response->json();

            // reCAPTCHA v3のスコアを確認（0.5以上で通過）
            return ($result['success'] ?? false)
                && (($result['score'] ?? 0) >= 0.5);
        } catch (\Exception $e) {
            // API接続エラーの場合はログを残して通過させる
            \Log::warning('reCAPTCHA verification failed', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }
}

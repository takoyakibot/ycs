<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'api_key' => ['nullable', 'string', 'regex:/^AIza[0-9A-Za-z_-]{35}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'api_key.regex' => 'YouTube API キーの形式が正しくありません。APIキーは "AIza" で始まる39文字の文字列である必要があります。',
        ];
    }
}

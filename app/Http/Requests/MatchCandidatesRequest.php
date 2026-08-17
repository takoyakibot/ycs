<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 楽曲マスタの照合候補を取得するリクエスト
 */
class MatchCandidatesRequest extends FormRequest
{
    /**
     * 1リクエストで照合できるテキスト数の上限
     *
     * 正規化画面の1ページ分（50件）を一度に処理できる余裕を持たせている。
     */
    public const MAX_TEXTS = 100;

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
        return [
            'normalized_texts' => 'required|array|min:1|max:'.self::MAX_TEXTS,
            'normalized_texts.*' => 'required|string|max:1000',
        ];
    }
}

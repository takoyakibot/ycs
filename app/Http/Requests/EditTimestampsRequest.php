<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EditTimestampsRequest extends FormRequest
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
        return [
            '*.id' => 'required|string|exists:ts_items,id',
            '*.comment_id' => 'required|string|exists:ts_items,comment_id',
            '*.is_display' => 'required|boolean',
        ];
    }
}

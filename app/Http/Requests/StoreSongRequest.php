<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSongRequest extends FormRequest
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
            'title' => 'required|string|max:255',
            'artist' => 'required|string|max:255',
            'spotify_track_id' => 'nullable|string|max:22|regex:/^[a-zA-Z0-9]+$/',
            'spotify_data' => 'nullable|array',
            'force_create' => 'nullable|boolean',
            'use_existing_id' => 'nullable|string|exists:songs,id',
        ];
    }
}

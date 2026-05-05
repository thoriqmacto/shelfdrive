<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreEbookNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'cfi' => ['sometimes', 'nullable', 'string', 'max:512'],
            'chm_topic' => ['sometimes', 'nullable', 'string', 'max:512'],
            'selection_text' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'color' => ['sometimes', 'nullable', 'string', 'max:16'],
        ];
    }
}

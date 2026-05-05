<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProgressRequest extends FormRequest
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
            'percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}

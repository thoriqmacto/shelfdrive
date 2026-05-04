<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEbookListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('ebook_lists', 'name')->where(fn ($q) => $q->where('user_id', $this->user()->id)),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

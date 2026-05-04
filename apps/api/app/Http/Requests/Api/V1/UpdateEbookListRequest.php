<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEbookListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $listId = $this->route('list')?->id;

        return [
            'name' => [
                'sometimes',
                'string',
                'max:120',
                Rule::unique('ebook_lists', 'name')
                    ->where(fn ($q) => $q->where('user_id', $this->user()->id))
                    ->ignore($listId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'cover_drive_file_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}

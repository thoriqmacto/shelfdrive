<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ResolveDuplicateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The drive_file id the user wants to keep. Must be a member
            // of this group; the controller enforces that.
            'canonical_drive_file_id' => ['required', 'integer'],
            // If true, mark the non-canonical members as removed from
            // the app library (does NOT touch Drive — that flow lives
            // in Phase 8 with typed-confirm).
            'remove_others_from_library' => ['sometimes', 'boolean'],
        ];
    }
}

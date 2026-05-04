<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddEbookListItemRequest;
use App\Models\DriveFile;
use App\Models\EbookList;
use App\Models\EbookListItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Add / remove ebooks from a list. Append-at-end semantics: the new
 * item's position is one past the current max in that list. Reorder
 * is not exposed yet — the Phase 7 "lists detail" page will add a
 * batch position update once we have a viewer to wire bookmarks too.
 */
class EbookListItemController extends Controller
{
    public function store(AddEbookListItemRequest $request, EbookList $list): JsonResponse
    {
        abort_unless($list->user_id === $request->user()->id, 404);

        $driveFileId = (int) $request->validated('drive_file_id');

        // The drive_file must belong to the same user.
        $exists = DriveFile::where('id', $driveFileId)
            ->where('user_id', $request->user()->id)
            ->exists();
        if (! $exists) {
            return response()->json([
                'message' => 'drive_file_id must reference one of your indexed files.',
                'errors' => ['drive_file_id' => ['Not in your library.']],
            ], 422);
        }

        // Idempotent add — if the file is already on the list, return
        // the existing row instead of erroring on the unique index.
        $existing = $list->items()->where('drive_file_id', $driveFileId)->first();
        if ($existing) {
            return response()->json(['data' => $this->present($existing)], 200);
        }

        $maxPosition = (int) $list->items()->max('position');
        $item = $list->items()->create([
            'drive_file_id' => $driveFileId,
            'position' => $maxPosition + 1,
            'added_at' => now(),
        ]);

        return response()->json(['data' => $this->present($item)], 201);
    }

    public function destroy(Request $request, EbookList $list, EbookListItem $item): JsonResponse
    {
        abort_unless($list->user_id === $request->user()->id, 404);
        abort_unless($item->ebook_list_id === $list->id, 404);

        $item->delete();

        return response()->json(null, 204);
    }

    private function present(EbookListItem $item): array
    {
        return [
            'id' => $item->id,
            'ebook_list_id' => $item->ebook_list_id,
            'drive_file_id' => $item->drive_file_id,
            'position' => $item->position,
            'added_at' => $item->added_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEbookListRequest;
use App\Http\Requests\Api\V1\UpdateEbookListRequest;
use App\Models\EbookList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Custom ebook lists / playlists. Stored in the app DB only — Drive
 * has no concept of these. Each list belongs to exactly one user;
 * sharing comes later via signed share tokens (Phase 7).
 *
 * Follows the starter's owner-scoping rule: foreign reads/writes
 * return 404, never 403.
 */
class EbookListController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $lists = $request->user()
            ->ebookLists()
            ->withCount('items')
            ->orderByDesc('updated_at')
            ->get(['id', 'name', 'description', 'cover_drive_file_id', 'updated_at']);

        return response()->json([
            'data' => $lists->map(fn (EbookList $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'description' => $l->description,
                'cover_drive_file_id' => $l->cover_drive_file_id,
                'item_count' => (int) $l->items_count,
                'updated_at' => $l->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(StoreEbookListRequest $request): JsonResponse
    {
        $list = $request->user()->ebookLists()->create($request->validated());

        return response()->json(['data' => $this->present($list)], 201);
    }

    public function show(Request $request, EbookList $list): JsonResponse
    {
        abort_unless($list->user_id === $request->user()->id, 404);

        $list->load([
            'items' => fn ($q) => $q->orderBy('position')->orderBy('id'),
            'items.driveFile' => fn ($q) => $q->select([
                'id',
                'connected_account_id',
                'name',
                'mime_type',
                'size_bytes',
                'format',
                'cover_thumb_url',
                'web_view_link',
                'drive_modified_time',
            ]),
        ]);

        return response()->json(['data' => array_merge(
            $this->present($list),
            [
                'items' => $list->items->map(fn ($item) => [
                    'id' => $item->id,
                    'position' => $item->position,
                    'added_at' => $item->added_at?->toIso8601String(),
                    'drive_file' => $item->driveFile,
                ]),
            ],
        )]);
    }

    public function update(UpdateEbookListRequest $request, EbookList $list): JsonResponse
    {
        abort_unless($list->user_id === $request->user()->id, 404);

        $list->fill($request->validated())->save();

        return response()->json(['data' => $this->present($list)]);
    }

    public function destroy(Request $request, EbookList $list): JsonResponse
    {
        abort_unless($list->user_id === $request->user()->id, 404);

        // Items cascade via FK on delete; the underlying DriveFile rows
        // are untouched.
        $list->delete();

        return response()->json(null, 204);
    }

    private function present(EbookList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'description' => $list->description,
            'cover_drive_file_id' => $list->cover_drive_file_id,
            'created_at' => $list->created_at?->toIso8601String(),
            'updated_at' => $list->updated_at?->toIso8601String(),
        ];
    }
}

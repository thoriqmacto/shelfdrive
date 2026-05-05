<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBookmarkRequest;
use App\Models\DriveFile;
use App\Models\EbookBookmark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bookmarks across the user's library. Two read shapes:
 *
 *   - GET /api/v1/bookmarks            — global list, latest first.
 *     Powers the /bookmarks page so the user can jump back into any
 *     ebook from one place per the Phase 5 design note.
 *   - GET /api/v1/library/{file}/bookmarks — only this book's bookmarks.
 *     Used by the viewer drawer in Phase 7b.
 *
 * Bookmarks are private per user. Sharing comes via the share_tokens
 * resource in the share PR.
 */
class BookmarkController extends Controller
{
    public function indexGlobal(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 50)));

        $page = $request->user()
            ->ebookBookmarks()
            ->with(['driveFile' => fn ($q) => $q->select(['id', 'name', 'format', 'connected_account_id'])])
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map(fn (EbookBookmark $b) => $this->present($b)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function indexForFile(Request $request, DriveFile $file): JsonResponse
    {
        abort_unless($file->user_id === $request->user()->id, 404);

        $bookmarks = $request->user()
            ->ebookBookmarks()
            ->where('drive_file_id', $file->id)
            ->orderBy('page')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $bookmarks->map(fn (EbookBookmark $b) => $this->present($b, false)),
        ]);
    }

    public function store(StoreBookmarkRequest $request, DriveFile $file): JsonResponse
    {
        abort_unless($file->user_id === $request->user()->id, 404);

        $payload = $request->validated();
        $payload['format'] = $file->format;

        $bookmark = new EbookBookmark($payload);
        $bookmark->user_id = $request->user()->id;
        $bookmark->drive_file_id = $file->id;
        $bookmark->save();

        return response()->json(['data' => $this->present($bookmark, false)], 201);
    }

    public function destroy(Request $request, EbookBookmark $bookmark): JsonResponse
    {
        abort_unless($bookmark->user_id === $request->user()->id, 404);

        $bookmark->delete();

        return response()->json(null, 204);
    }

    private function present(EbookBookmark $b, bool $withFile = true): array
    {
        $base = [
            'id' => $b->id,
            'drive_file_id' => $b->drive_file_id,
            'format' => $b->format,
            'page' => $b->page,
            'cfi' => $b->cfi,
            'chm_topic' => $b->chm_topic,
            'label' => $b->label,
            'created_at' => $b->created_at?->toIso8601String(),
        ];
        if ($withFile && $b->relationLoaded('driveFile') && $b->driveFile) {
            $base['drive_file'] = [
                'id' => $b->driveFile->id,
                'name' => $b->driveFile->name,
                'format' => $b->driveFile->format,
                'connected_account_id' => $b->driveFile->connected_account_id,
            ];
        }

        return $base;
    }
}

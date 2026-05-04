<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DriveFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only listing of the user's indexed ebook files. Phase 4 ships
 * basic search + filter; the per-book detail / progress / bookmark
 * endpoints arrive in Phase 7.
 */
class LibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 50)));

        $query = DriveFile::visible()
            ->where('user_id', $request->user()->id);

        if ($search = $request->query('q')) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $search).'%';
            $query->where('name', 'like', $like);
        }

        if ($format = $request->query('format')) {
            $query->where('format', $format);
        }

        if ($accountId = $request->query('account_id')) {
            $query->where('connected_account_id', (int) $accountId);
        }

        $page = $query
            ->orderByDesc('drive_modified_time')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map(fn (DriveFile $f) => $this->present($f)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    private function present(DriveFile $f): array
    {
        return [
            'id' => $f->id,
            'name' => $f->name,
            'mime_type' => $f->mime_type,
            'size_bytes' => $f->size_bytes,
            'format' => $f->format,
            'connected_account_id' => $f->connected_account_id,
            'web_view_link' => $f->web_view_link,
            'cover_thumb_url' => $f->cover_thumb_url,
            'drive_modified_time' => $f->drive_modified_time?->toIso8601String(),
        ];
    }
}

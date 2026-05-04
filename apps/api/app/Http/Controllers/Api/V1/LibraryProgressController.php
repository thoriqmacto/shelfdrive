<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProgressRequest;
use App\Models\DriveFile;
use App\Models\ReadingProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user, per-DriveFile reading progress. The viewer (Phase 7b) calls
 * PATCH every couple of seconds while reading; the detail page reads
 * via the embedded `progress` field on /library/{id}, so a separate
 * GET endpoint is provided here for completeness/integration only.
 */
class LibraryProgressController extends Controller
{
    public function show(Request $request, DriveFile $file): JsonResponse
    {
        abort_unless($file->user_id === $request->user()->id, 404);

        $progress = $request->user()
            ->readingProgress()
            ->where('drive_file_id', $file->id)
            ->first();

        return response()->json(['data' => $progress ? [
            'page' => $progress->page,
            'cfi' => $progress->cfi,
            'chm_topic' => $progress->chm_topic,
            'percent' => (float) $progress->percent,
            'format' => $progress->format,
            'last_read_at' => $progress->last_read_at?->toIso8601String(),
        ] : null]);
    }

    public function update(UpdateProgressRequest $request, DriveFile $file): JsonResponse
    {
        abort_unless($file->user_id === $request->user()->id, 404);

        $payload = $request->validated();
        $payload['format'] = $file->format;
        $payload['last_read_at'] = now();

        $progress = $request->user()
            ->readingProgress()
            ->where('drive_file_id', $file->id)
            ->first();

        if (! $progress) {
            $progress = new ReadingProgress($payload);
            $progress->user_id = $request->user()->id;
            $progress->drive_file_id = $file->id;
            $progress->save();
        } else {
            $progress->fill($payload)->save();
        }

        return response()->json(['data' => [
            'page' => $progress->page,
            'cfi' => $progress->cfi,
            'percent' => (float) $progress->percent,
            'format' => $progress->format,
            'last_read_at' => $progress->last_read_at?->toIso8601String(),
        ]]);
    }
}

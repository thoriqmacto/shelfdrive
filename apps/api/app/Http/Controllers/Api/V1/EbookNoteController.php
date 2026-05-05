<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEbookNoteRequest;
use App\Models\DriveFile;
use App\Models\EbookNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EbookNoteController extends Controller
{
    public function indexGlobal(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 50)));

        $page = $request->user()
            ->ebookNotes()
            ->with(['driveFile' => fn ($q) => $q->select(['id', 'name', 'format'])])
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map(fn (EbookNote $n) => $this->present($n, true)),
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

        $notes = $request->user()
            ->ebookNotes()
            ->where('drive_file_id', $file->id)
            ->orderBy('page')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $notes->map(fn ($n) => $this->present($n, false))]);
    }

    public function store(StoreEbookNoteRequest $request, DriveFile $file): JsonResponse
    {
        abort_unless($file->user_id === $request->user()->id, 404);

        $payload = $request->validated();
        $payload['format'] = $file->format;

        $note = new EbookNote($payload);
        $note->user_id = $request->user()->id;
        $note->drive_file_id = $file->id;
        $note->save();

        return response()->json(['data' => $this->present($note, false)], 201);
    }

    public function update(StoreEbookNoteRequest $request, EbookNote $note): JsonResponse
    {
        abort_unless($note->user_id === $request->user()->id, 404);

        $note->fill($request->validated())->save();

        return response()->json(['data' => $this->present($note, false)]);
    }

    public function destroy(Request $request, EbookNote $note): JsonResponse
    {
        abort_unless($note->user_id === $request->user()->id, 404);

        $note->delete();

        return response()->json(null, 204);
    }

    private function present(EbookNote $n, bool $withFile): array
    {
        $base = [
            'id' => $n->id,
            'drive_file_id' => $n->drive_file_id,
            'format' => $n->format,
            'page' => $n->page,
            'cfi' => $n->cfi,
            'chm_topic' => $n->chm_topic,
            'selection_text' => $n->selection_text,
            'body' => $n->body,
            'color' => $n->color,
            'created_at' => $n->created_at?->toIso8601String(),
            'updated_at' => $n->updated_at?->toIso8601String(),
        ];
        if ($withFile && $n->relationLoaded('driveFile') && $n->driveFile) {
            $base['drive_file'] = [
                'id' => $n->driveFile->id,
                'name' => $n->driveFile->name,
                'format' => $n->driveFile->format,
            ];
        }

        return $base;
    }
}

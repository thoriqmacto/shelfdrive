<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResolveDuplicateRequest;
use App\Models\DuplicateGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read + resolve duplicate groups. Detection runs in the background
 * via DetectDuplicates after every successful scan; this controller
 * never re-triggers detection — it just presents what's there and
 * records the user's keeper choice.
 *
 * Per the plan: no auto-delete. The remove-non-canonical action only
 * sets `removed_from_library_at` on the DriveFile rows. Moving files
 * to Google Drive trash lives in the LibraryController DELETE flow
 * (Phase 8) behind typed-name confirmation.
 */
class DuplicateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $groups = $request->user()
            ->duplicateGroups()
            ->unresolved()
            ->with([
                'members.driveFile' => function ($q) {
                    $q->select([
                        'id',
                        'connected_account_id',
                        'name',
                        'mime_type',
                        'size_bytes',
                        'md5_checksum',
                        'parent_folder_path',
                        'web_view_link',
                        'format',
                        'drive_modified_time',
                    ]);
                },
            ])
            // Account-scoped first, then cross-account, then by confidence
            // (exact > likely > possible).
            ->orderByRaw("CASE scope WHEN 'account' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE confidence WHEN 'exact' THEN 0 WHEN 'likely' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $groups->map(fn (DuplicateGroup $g) => [
                'id' => $g->id,
                'match_strategy' => $g->match_strategy,
                'confidence' => $g->confidence,
                'scope' => $g->scope,
                'members' => $g->members->map(fn ($m) => [
                    'id' => $m->id,
                    'drive_file' => $m->driveFile,
                ]),
            ]),
        ]);
    }

    public function resolve(ResolveDuplicateRequest $request, DuplicateGroup $group): JsonResponse
    {
        abort_unless($group->user_id === $request->user()->id, 404);
        abort_if($group->resolved_at !== null, 422, 'This group has already been resolved.');

        $canonicalId = (int) $request->validated('canonical_drive_file_id');
        $memberFileIds = $group->members()->pluck('drive_file_id')->all();

        if (! in_array($canonicalId, $memberFileIds, true)) {
            return response()->json([
                'message' => 'canonical_drive_file_id must be a member of this duplicate group.',
                'errors' => ['canonical_drive_file_id' => ['Not a member of this group.']],
            ], 422);
        }

        DB::transaction(function () use ($request, $group, $canonicalId, $memberFileIds) {
            $group->forceFill([
                'canonical_drive_file_id' => $canonicalId,
                'resolved_at' => now(),
            ])->save();

            if ($request->boolean('remove_others_from_library')) {
                $request->user()
                    ->driveFiles()
                    ->whereIn('id', $memberFileIds)
                    ->where('id', '!=', $canonicalId)
                    ->update(['removed_from_library_at' => now()]);
            }
        });

        return response()->json(['message' => 'Resolved.']);
    }
}

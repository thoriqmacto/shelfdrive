<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\Drive\ScanDriveAccount;
use App\Models\ConnectedGoogleAccount;
use App\Models\SyncRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manual scan triggers and sync run history. Long-running scans go
 * through Laravel Queue (`sync` driver in dev, redis/database in prod
 * via `QUEUE_CONNECTION`).
 */
class SyncController extends Controller
{
    /**
     * The 50 most recent sync runs across all of the user's connected
     * Drive accounts. Owner-scoped via the relation join.
     */
    public function index(Request $request): JsonResponse
    {
        $accountIds = $request->user()
            ->connectedGoogleAccounts()
            ->pluck('id');

        $runs = SyncRun::whereIn('connected_account_id', $accountIds)
            ->orderByDesc('id')
            ->limit(50)
            ->get([
                'id',
                'connected_account_id',
                'kind',
                'status',
                'files_seen',
                'files_added',
                'files_updated',
                'files_removed',
                'error',
                'started_at',
                'finished_at',
            ]);

        return response()->json([
            'data' => $runs->map(fn (SyncRun $r) => $this->present($r)),
        ]);
    }

    /**
     * Dispatch a manual scan for a specific connected drive account.
     * Returns the queued sync_run id so the UI can poll its status.
     */
    public function run(Request $request, ConnectedGoogleAccount $account): JsonResponse
    {
        abort_unless($account->user_id === $request->user()->id, 404);
        abort_if(
            $account->purpose !== ConnectedGoogleAccount::PURPOSE_DRIVE,
            422,
            'Only drive-purpose accounts can be scanned.',
        );
        abort_if(
            $account->status !== ConnectedGoogleAccount::STATUS_ACTIVE,
            422,
            'This account is not active. Reconnect it to scan.',
        );

        ScanDriveAccount::dispatch($account->id, SyncRun::KIND_MANUAL);

        return response()->json([
            'message' => 'Scan dispatched.',
            'account_id' => $account->id,
        ], 202);
    }

    private function present(SyncRun $r): array
    {
        return [
            'id' => $r->id,
            'connected_account_id' => $r->connected_account_id,
            'kind' => $r->kind,
            'status' => $r->status,
            'files_seen' => $r->files_seen,
            'files_added' => $r->files_added,
            'files_updated' => $r->files_updated,
            'files_removed' => $r->files_removed,
            'error' => $r->error,
            'started_at' => $r->started_at?->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Jobs\Drive;

use App\Models\ConnectedGoogleAccount;
use App\Models\DriveFile;
use App\Models\SyncRun;
use App\Services\Google\FileLister;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Full-scan job: pages through every ebook on a connected Drive and
 * upserts a `drive_files` row for each. Anything previously indexed
 * but not seen this pass is marked `trashed=true` so the library list
 * naturally hides it (we never call DELETE on Drive).
 *
 * Phase 4 keeps it simple — full scan only. The incremental
 * `changes.list` path lives in the IncrementalSync job (Phase 4b/later).
 */
class ScanDriveAccount implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $connectedAccountId,
        public string $kind = SyncRun::KIND_MANUAL,
    ) {
    }

    /**
     * The Bus deduplication key. Multiple "scan now" clicks on the same
     * account converge to a single in-flight job (Phase 9 hardening will
     * actually wire this through ShouldBeUnique; for now it's documented).
     */
    public function uniqueId(): string
    {
        return "scan-drive:{$this->connectedAccountId}";
    }

    public function handle(FileLister $lister): void
    {
        $account = ConnectedGoogleAccount::find($this->connectedAccountId);
        if (! $account || $account->status !== ConnectedGoogleAccount::STATUS_ACTIVE) {
            return;
        }

        $run = $account->syncRuns()->create([
            'kind' => $this->kind,
            'status' => SyncRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $seen = $added = $updated = 0;
        $seenDriveIds = [];

        try {
            foreach ($lister->ebooks($account) as $file) {
                $seen++;

                $driveId = (string) ($file['id'] ?? '');
                if ($driveId === '') {
                    continue;
                }
                $seenDriveIds[] = $driveId;

                $existing = $account->driveFiles()
                    ->where('drive_file_id', $driveId)
                    ->first();

                $payload = [
                    'name' => (string) ($file['name'] ?? ''),
                    'mime_type' => (string) ($file['mimeType'] ?? ''),
                    'size_bytes' => isset($file['size']) ? (int) $file['size'] : null,
                    'md5_checksum' => $file['md5Checksum'] ?? null,
                    'parent_folder_id' => isset($file['parents'][0]) ? (string) $file['parents'][0] : null,
                    'web_view_link' => $file['webViewLink'] ?? null,
                    'cover_thumb_url' => $file['thumbnailLink'] ?? null,
                    'drive_modified_time' => isset($file['modifiedTime'])
                        ? \Carbon\Carbon::parse($file['modifiedTime'])
                        : null,
                    'trashed' => (bool) ($file['trashed'] ?? false),
                    'format' => DriveFile::normalizeFormat(
                        (string) ($file['mimeType'] ?? ''),
                        (string) ($file['name'] ?? ''),
                    ),
                ];

                if ($existing) {
                    $existing->fill($payload);
                    if ($existing->isDirty()) {
                        $existing->save();
                        $updated++;
                    }
                    continue;
                }

                $df = new DriveFile($payload);
                $df->drive_file_id = $driveId;
                $df->user_id = $account->user_id;
                $df->connected_account_id = $account->id;
                $df->save();
                $added++;
            }

            // Anything previously indexed for this account but not in this
            // pass has been trashed at Drive (or moved out of an ebook
            // mime type). Mark trashed so it disappears from the library
            // without us issuing a DELETE.
            $removed = 0;
            if (! empty($seenDriveIds)) {
                $removed = $account->driveFiles()
                    ->whereNotIn('drive_file_id', $seenDriveIds)
                    ->where('trashed', false)
                    ->update(['trashed' => true, 'updated_at' => now()]);
            }

            $run->forceFill([
                'status' => SyncRun::STATUS_SUCCESS,
                'files_seen' => $seen,
                'files_added' => $added,
                'files_updated' => $updated,
                'files_removed' => $removed,
                'finished_at' => now(),
            ])->save();

            $account->forceFill(['last_full_scan_at' => now()])->save();
        } catch (Throwable $e) {
            Log::warning('ScanDriveAccount failed', [
                'account_id' => $account->id,
                'sync_run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            $run->forceFill([
                'status' => SyncRun::STATUS_ERROR,
                'files_seen' => $seen,
                'files_added' => $added,
                'files_updated' => $updated,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            // Re-throw so the queue worker registers the failure.
            throw $e;
        }
    }
}

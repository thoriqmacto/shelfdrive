<?php

use App\Jobs\Drive\ScanDriveAccount;
use App\Models\ConnectedGoogleAccount;
use App\Models\SyncRun;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ShelfDrive: periodic Drive scans.
//
// Cadence is env-driven via DRIVE_SYNC_INTERVAL_MINUTES. The default is
// 15 min — short enough to feel live, long enough to stay well under
// the 1k-requests-per-100s/per-user Drive quota for most libraries.
//
// Phase 4 dispatches a full scan on each tick (incremental sync via
// changes.list lands in a follow-up phase). Disabled in `testing` env
// so PHPUnit runs don't fan out into queue work.
Schedule::call(function () {
    $every = max(5, (int) env('DRIVE_SYNC_INTERVAL_MINUTES', 15));
    $threshold = now()->subMinutes($every);

    ConnectedGoogleAccount::query()
        ->where('purpose', ConnectedGoogleAccount::PURPOSE_DRIVE)
        ->where('status', ConnectedGoogleAccount::STATUS_ACTIVE)
        ->where(function ($q) use ($threshold) {
            $q->whereNull('last_full_scan_at')
              ->orWhere('last_full_scan_at', '<=', $threshold);
        })
        ->pluck('id')
        ->each(fn (int $id) => ScanDriveAccount::dispatch($id, SyncRun::KIND_INCREMENTAL));
})
    ->name('shelfdrive:scan-due-accounts')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->skip(fn () => app()->environment('testing'));

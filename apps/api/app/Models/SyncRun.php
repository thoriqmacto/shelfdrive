<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per Drive scan attempt. Acts as both a ledger for the UI's
 * sync history page and a debug record when a scan partially fails.
 */
class SyncRun extends Model
{
    public const KIND_FULL = 'full';
    public const KIND_INCREMENTAL = 'incremental';
    public const KIND_MANUAL = 'manual';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
    public const STATUS_PARTIAL = 'partial';

    protected $fillable = [
        'kind',
        'status',
        'files_seen',
        'files_added',
        'files_updated',
        'files_removed',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'files_seen' => 'integer',
            'files_added' => 'integer',
            'files_updated' => 'integer',
            'files_removed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ConnectedGoogleAccount, $this>
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedGoogleAccount::class, 'connected_account_id');
    }
}

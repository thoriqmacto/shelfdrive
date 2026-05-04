<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Google account a user has connected to ShelfDrive.
 *
 * Two purposes:
 *  - login: the user's primary identity. Exactly one per user.
 *  - drive: an additional Drive account scanned for ebooks.
 *
 * Tokens are AES-encrypted at rest via the `encrypted` cast (uses APP_KEY).
 * Never serialize them; never log them.
 */
class ConnectedGoogleAccount extends Model
{
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_DRIVE = 'drive';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'google_sub',
        'email',
        'display_name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'purpose',
        'status',
        'start_page_token',
        'upload_folder_drive_id',
        'last_full_scan_at',
        'last_incremental_sync_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'last_full_scan_at' => 'datetime',
            'last_incremental_sync_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

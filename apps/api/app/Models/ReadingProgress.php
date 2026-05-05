<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user, per-DriveFile reading position. Locator interpretation
 * varies by format:
 *   - pdf / djvu : `page` (1-indexed)
 *   - epub       : `cfi` (EPUB CFI string)
 *   - chm        : `chm_topic` (topic path)
 *
 * `percent` is a UI hint (0-100) — never relied on for navigation.
 *
 * Lives in its own model class (not as a column on drive_files) so
 * progress survives across re-scans and so multi-user sharing in a
 * future phase doesn't have to fork a row.
 */
class ReadingProgress extends Model
{
    protected $table = 'reading_progress';

    protected $fillable = [
        'drive_file_id',
        'format',
        'page',
        'cfi',
        'chm_topic',
        'percent',
        'last_read_at',
    ];

    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'percent' => 'decimal:2',
            'last_read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<DriveFile, $this>
     */
    public function driveFile(): BelongsTo
    {
        return $this->belongsTo(DriveFile::class);
    }
}

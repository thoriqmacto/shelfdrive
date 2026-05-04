<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user-defined or auto-derived category for grouping ebook files.
 * `source=auto` rows are derived from Drive folder names during scans;
 * `source=manual` rows are user-created.
 */
class EbookCategory extends Model
{
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = ['name', 'source'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<DriveFile, $this>
     */
    public function driveFiles(): HasMany
    {
        return $this->hasMany(DriveFile::class, 'category_id');
    }
}

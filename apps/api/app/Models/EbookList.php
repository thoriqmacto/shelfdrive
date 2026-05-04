<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user-curated set of ebooks. Equivalent to a music playlist:
 * "Favourite Cardiology Books", "To Read", etc.
 *
 * Ordering is explicit via `EbookListItem.position`. Lists live in the
 * app DB only — Google Drive has no concept of them.
 */
class EbookList extends Model
{
    protected $fillable = ['name', 'description', 'cover_drive_file_id'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<EbookListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(EbookListItem::class);
    }

    /**
     * @return BelongsTo<DriveFile, $this>
     */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(DriveFile::class, 'cover_drive_file_id');
    }
}

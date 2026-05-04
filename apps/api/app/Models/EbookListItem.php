<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EbookListItem extends Model
{
    protected $fillable = ['drive_file_id', 'position', 'added_at'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'added_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EbookList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(EbookList::class, 'ebook_list_id');
    }

    /**
     * @return BelongsTo<DriveFile, $this>
     */
    public function driveFile(): BelongsTo
    {
        return $this->belongsTo(DriveFile::class);
    }
}

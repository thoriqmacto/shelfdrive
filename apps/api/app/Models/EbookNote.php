<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EbookNote extends Model
{
    protected $fillable = [
        'drive_file_id',
        'format',
        'page',
        'cfi',
        'chm_topic',
        'selection_text',
        'body',
        'color',
    ];

    protected function casts(): array
    {
        return ['page' => 'integer'];
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

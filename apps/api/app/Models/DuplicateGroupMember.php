<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateGroupMember extends Model
{
    protected $fillable = ['drive_file_id'];

    /**
     * @return BelongsTo<DuplicateGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(DuplicateGroup::class, 'duplicate_group_id');
    }

    /**
     * @return BelongsTo<DriveFile, $this>
     */
    public function driveFile(): BelongsTo
    {
        return $this->belongsTo(DriveFile::class);
    }
}

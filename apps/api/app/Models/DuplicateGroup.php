<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One DuplicateGroup represents a set of DriveFile rows that match
 * each other under a given strategy. The group is `resolved` only when
 * the user has explicitly accepted/dismissed it — we never auto-delete.
 */
class DuplicateGroup extends Model
{
    public const STRATEGY_MD5 = 'md5';
    public const STRATEGY_NAME_SIZE_MIME = 'name_size_mime';
    public const STRATEGY_NAME_ONLY = 'name_only';

    public const CONFIDENCE_EXACT = 'exact';
    public const CONFIDENCE_LIKELY = 'likely';
    public const CONFIDENCE_POSSIBLE = 'possible';

    public const SCOPE_ACCOUNT = 'account';
    public const SCOPE_CROSS_ACCOUNT = 'cross_account';

    protected $fillable = [
        'match_strategy',
        'confidence',
        'scope',
        'canonical_drive_file_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<DuplicateGroupMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(DuplicateGroupMember::class);
    }

    /**
     * @return BelongsTo<DriveFile, $this>
     */
    public function canonical(): BelongsTo
    {
        return $this->belongsTo(DriveFile::class, 'canonical_drive_file_id');
    }

    /**
     * Confidence label tied to the matching strategy.
     */
    public static function confidenceFor(string $strategy): string
    {
        return match ($strategy) {
            self::STRATEGY_MD5 => self::CONFIDENCE_EXACT,
            self::STRATEGY_NAME_SIZE_MIME => self::CONFIDENCE_LIKELY,
            self::STRATEGY_NAME_ONLY => self::CONFIDENCE_POSSIBLE,
            default => self::CONFIDENCE_POSSIBLE,
        };
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}

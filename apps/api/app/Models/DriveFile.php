<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Indexed metadata for a single ebook file on a connected Google Drive.
 * The bytes themselves are never stored; the streaming proxy fetches
 * them on demand in Phase 7.
 *
 * `removed_from_library_at` is a soft "remove from library only" marker
 * — the row stays so list memberships, bookmarks, etc. don't cascade
 * away on what is effectively a presentation-layer hide.
 */
class DriveFile extends Model
{
    public const FORMAT_PDF = 'pdf';
    public const FORMAT_EPUB = 'epub';
    public const FORMAT_CHM = 'chm';
    public const FORMAT_DJVU = 'djvu';
    public const FORMAT_OTHER = 'other';

    /**
     * Ebook MIME types we index. Extension-based detection in
     * normalizeFormat() catches files whose MIME type is the generic
     * application/octet-stream (common for Drive uploads).
     */
    public const EBOOK_MIME_TYPES = [
        'application/pdf',
        'application/epub+zip',
        'application/x-mobipocket-ebook',
        'application/vnd.ms-htmlhelp',
        'image/vnd.djvu',
        'image/x-djvu',
    ];

    public const EBOOK_EXTENSIONS = ['pdf', 'epub', 'chm', 'djvu', 'djv'];

    protected $fillable = [
        'drive_file_id',
        'name',
        'mime_type',
        'size_bytes',
        'md5_checksum',
        'parent_folder_id',
        'parent_folder_path',
        'web_view_link',
        'cover_thumb_url',
        'drive_modified_time',
        'trashed',
        'format',
        'category_id',
        'removed_from_library_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'trashed' => 'boolean',
            'drive_modified_time' => 'datetime',
            'removed_from_library_at' => 'datetime',
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
     * @return BelongsTo<ConnectedGoogleAccount, $this>
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedGoogleAccount::class, 'connected_account_id');
    }

    /**
     * @return BelongsTo<EbookCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EbookCategory::class, 'category_id');
    }

    /**
     * Hide rows that were trashed at Drive or removed from library.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->where('trashed', false)
            ->whereNull('removed_from_library_at');
    }

    /**
     * Map a Drive `files.list` response entry to one of our format
     * constants. Returns `other` for anything we won't try to render —
     * the row is still indexed so duplicate detection can find it.
     */
    public static function normalizeFormat(string $mimeType, string $name): string
    {
        $mime = strtolower($mimeType);
        if ($mime === 'application/pdf') return self::FORMAT_PDF;
        if ($mime === 'application/epub+zip') return self::FORMAT_EPUB;
        if ($mime === 'application/vnd.ms-htmlhelp') return self::FORMAT_CHM;
        if ($mime === 'image/vnd.djvu' || $mime === 'image/x-djvu') return self::FORMAT_DJVU;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf' => self::FORMAT_PDF,
            'epub' => self::FORMAT_EPUB,
            'chm' => self::FORMAT_CHM,
            'djvu', 'djv' => self::FORMAT_DJVU,
            default => self::FORMAT_OTHER,
        };
    }

    /**
     * Drive `files.list` query for ebook MIME types + extensions.
     * Built once and reused across scan jobs.
     */
    public static function ebookSearchQuery(): string
    {
        $mimes = array_map(
            fn ($m) => "mimeType='".addslashes($m)."'",
            self::EBOOK_MIME_TYPES,
        );
        $exts = array_map(
            fn ($e) => "fileExtension='".$e."'",
            self::EBOOK_EXTENSIONS,
        );

        return '('.implode(' or ', array_merge($mimes, $exts)).") and trashed=false";
    }
}

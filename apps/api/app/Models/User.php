<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_sub',
        'avatar_url',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<ConnectedGoogleAccount, $this>
     */
    public function connectedGoogleAccounts(): HasMany
    {
        return $this->hasMany(ConnectedGoogleAccount::class);
    }

    /**
     * @return HasMany<DriveFile, $this>
     */
    public function driveFiles(): HasMany
    {
        return $this->hasMany(DriveFile::class);
    }

    /**
     * @return HasMany<EbookCategory, $this>
     */
    public function ebookCategories(): HasMany
    {
        return $this->hasMany(EbookCategory::class);
    }

    /**
     * @return HasMany<DuplicateGroup, $this>
     */
    public function duplicateGroups(): HasMany
    {
        return $this->hasMany(DuplicateGroup::class);
    }

    /**
     * @return HasMany<EbookList, $this>
     */
    public function ebookLists(): HasMany
    {
        return $this->hasMany(EbookList::class);
    }
}

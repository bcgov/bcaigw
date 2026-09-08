<?php

namespace App\Models;

use Database\Factories\MachineCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MachineCredential extends Model
{
    /** @use HasFactory<MachineCredentialFactory> */
    use HasFactory;

    public const PORTAL_COLUMNS = [
        'public_id',
        'name',
        'client_identifier',
        'abilities',
        'expires_at',
        'revoked_at',
        'rotation_overlap_ends_at',
        'last_used_at',
        'created_at',
    ];

    protected $fillable = [
        'application_id',
        'name',
        'client_identifier',
        'secret_hash',
        'abilities',
        'version',
        'expires_at',
        'revoked_at',
        'rotation_overlap_ends_at',
        'rotated_from_id',
        'last_used_at',
        'last_used_ip_hash',
        'created_by',
    ];

    protected $hidden = ['secret_hash'];

    protected static function booted(): void
    {
        static::creating(function (self $credential): void {
            $credential->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'rotation_overlap_ends_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(MachineAccessToken::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->rotation_overlap_ends_at === null || $this->rotation_overlap_ends_at->isFuture());
    }
}

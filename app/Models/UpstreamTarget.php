<?php

namespace App\Models;

use Database\Factories\UpstreamTargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class UpstreamTarget extends Model
{
    /** @use HasFactory<UpstreamTargetFactory> */
    use HasFactory;

    protected $guarded = ['id', 'public_id', 'health_status', 'last_health_checked_at'];

    protected static function booted(): void
    {
        static::creating(function (self $target): void {
            $target->public_id ??= (string) Str::ulid();
        });
        static::deleting(function (): never {
            throw new LogicException('Upstream targets must be retired, not deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'connection_settings' => 'array',
            'last_health_checked_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class, 'provider_account_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(PublicModelAlias::class, 'active_target_id');
    }
}

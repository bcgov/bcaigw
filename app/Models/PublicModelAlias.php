<?php

namespace App\Models;

use App\Enums\ControlPlaneStatus;
use Database\Factories\PublicModelAliasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class PublicModelAlias extends Model
{
    /** @use HasFactory<PublicModelAliasFactory> */
    use HasFactory;

    protected $guarded = ['id', 'public_id'];

    protected static function booted(): void
    {
        static::creating(function (self $alias): void {
            $alias->public_id ??= (string) Str::ulid();
        });
        static::deleting(function (): never {
            throw new LogicException('Public model aliases must be retired, not deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'status' => ControlPlaneStatus::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function activeTarget(): BelongsTo
    {
        return $this->belongsTo(UpstreamTarget::class, 'active_target_id');
    }

    public function pricingVersions(): HasMany
    {
        return $this->hasMany(ModelPricingVersion::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(ApplicationModelGrant::class);
    }

    public function mappingVersions(): HasMany
    {
        return $this->hasMany(ModelAliasTargetVersion::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\ProviderAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class ProviderAccount extends Model
{
    /** @use HasFactory<ProviderAccountFactory> */
    use HasFactory;

    protected $guarded = ['id', 'public_id'];

    protected $hidden = ['sensitive_configuration'];

    protected static function booted(): void
    {
        static::creating(function (self $provider): void {
            $provider->public_id ??= (string) Str::ulid();
        });
        static::deleting(function (): never {
            throw new LogicException('Provider accounts must be retired, not deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'sensitive_configuration' => 'encrypted:array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function targets(): HasMany
    {
        return $this->hasMany(UpstreamTarget::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\ModelPricingVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class ModelPricingVersion extends Model
{
    /** @use HasFactory<ModelPricingVersionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'public_id'];

    protected static function booted(): void
    {
        static::creating(function (self $pricing): void {
            $pricing->public_id ??= (string) Str::ulid();
        });
        static::updating(function (): never {
            throw new LogicException('Model pricing versions are append-only.');
        });
        static::deleting(function (): never {
            throw new LogicException('Model pricing versions are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'effective_at' => 'immutable_datetime',
            'input_cost_per_million_tokens' => 'decimal:8',
            'output_cost_per_million_tokens' => 'decimal:8',
            'cached_input_cost_per_million_tokens' => 'decimal:8',
        ];
    }

    public function alias(): BelongsTo
    {
        return $this->belongsTo(PublicModelAlias::class, 'public_model_alias_id');
    }
}

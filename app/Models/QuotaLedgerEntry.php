<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class QuotaLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'budget_snapshot' => 'array',
            'usage_missing' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new LogicException('Quota ledger entries are append-only.');
        });
        self::deleting(function (): never {
            throw new LogicException('Quota ledger entries cannot be deleted.');
        });
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function modelPricingVersion(): BelongsTo
    {
        return $this->belongsTo(ModelPricingVersion::class);
    }
}

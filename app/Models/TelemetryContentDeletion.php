<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

final class TelemetryContentDeletion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'request_ids' => 'array',
            'range_from' => 'immutable_datetime',
            'range_to' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $deletion): void {
            $deletion->public_id ??= (string) Str::ulid();
        });
        self::updating(function (): never {
            throw new LogicException('Telemetry content deletion records are append-only.');
        });
        self::deleting(function (): never {
            throw new LogicException('Telemetry content deletion records cannot be deleted.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class ApplicationLifecycleHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'application_id',
        'from_status',
        'to_status',
        'actor_user_id',
        'note',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Lifecycle history is append-only.'));
        self::deleting(fn () => throw new LogicException('Lifecycle history cannot be deleted.'));
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

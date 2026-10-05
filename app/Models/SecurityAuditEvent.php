<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class SecurityAuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_type',
        'outcome',
        'actor_user_id',
        'subject_user_id',
        'ip_address',
        'user_agent_hash',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new LogicException('Security audit events are append-only.');
        });

        self::deleting(function (): never {
            throw new LogicException('Security audit events cannot be deleted.');
        });
    }
}

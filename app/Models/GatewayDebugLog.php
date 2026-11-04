<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Full request/response capture for gateway calls made in an environment with
 * debug_enabled. Admin-only; payloads are encrypted at rest and expire after
 * gateway.debug.retention_days.
 */
class GatewayDebugLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['request_payload', 'response_payload'];

    protected function casts(): array
    {
        return [
            'request_payload' => 'encrypted:array',
            'response_payload' => 'encrypted:array',
            'attachments' => 'array',
            'has_image' => 'boolean',
            'has_file' => 'boolean',
            'request_truncated' => 'boolean',
            'response_truncated' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}

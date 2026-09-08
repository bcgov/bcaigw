<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GatewayCallContent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'request_truncated' => 'boolean',
            'response_truncated' => 'boolean',
            'finalized_at' => 'immutable_datetime',
        ];
    }

    /**
     * Content ciphertext must never be serialised to Inertia props, JSON
     * responses, exports, logs or exception reports.
     *
     * @var list<string>
     */
    protected $hidden = [
        'wrapped_data_key',
        'wrap_iv',
        'wrap_tag',
        'request_iv',
        'request_tag',
        'request_ciphertext',
        'response_iv',
        'response_tag',
        'response_ciphertext',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(GatewayCallAttempt::class, 'gateway_call_attempt_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}

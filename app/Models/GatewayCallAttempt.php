<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GatewayCallAttempt extends Model
{
    protected $guarded = ['id'];

    /**
     * Never exposed to portal users or exports.
     *
     * @var list<string>
     */
    protected $hidden = ['idempotency_key_hash'];

    protected function casts(): array
    {
        return [
            'streaming' => 'boolean',
            'content_retention_enabled' => 'boolean',
            'client_cancelled' => 'boolean',
            'partial_response' => 'boolean',
            'client_metadata' => 'array',
            'started_at' => 'immutable_datetime',
            'first_byte_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'content_deleted_at' => 'immutable_datetime',
            'details_redacted_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'request_id';
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function alias(): BelongsTo
    {
        return $this->belongsTo(PublicModelAlias::class, 'public_model_alias_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(UpstreamTarget::class, 'upstream_target_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class, 'provider_account_id');
    }

    public function pricingVersion(): BelongsTo
    {
        return $this->belongsTo(ModelPricingVersion::class, 'model_pricing_version_id');
    }

    public function content(): HasOne
    {
        return $this->hasOne(GatewayCallContent::class);
    }

    public function contentDeletion(): BelongsTo
    {
        return $this->belongsTo(TelemetryContentDeletion::class, 'telemetry_content_deletion_id');
    }
}

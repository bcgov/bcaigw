<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GatewayUsageRollup extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'bucket_date' => 'immutable_date',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function alias(): BelongsTo
    {
        return $this->belongsTo(PublicModelAlias::class, 'public_model_alias_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class, 'provider_account_id');
    }
}

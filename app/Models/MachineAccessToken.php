<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineAccessToken extends Model
{
    protected $fillable = [
        'application_id',
        'machine_credential_id',
        'token_hash',
        'abilities',
        'credential_version',
        'application_status_version',
        'expires_at',
        'revoked_at',
        'last_used_at',
        'last_used_ip_hash',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(MachineCredential::class, 'machine_credential_id');
    }
}

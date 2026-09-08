<?php

namespace App\Models;

use App\Enums\ApplicationMemberRole;
use App\Enums\ApplicationStatus;
use App\Enums\DataClassification;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory;

    protected $guarded = [
        'id',
        'public_id',
        'status',
        'status_version',
        'prompt_response_retention_enabled',
        'retention_max_content_bytes',
        'rate_limit_per_minute',
        'token_rate_per_minute',
        'token_budget_daily',
        'token_budget_monthly',
        'cost_budget_daily',
        'cost_budget_monthly',
        'budget_currency',
        'configuration_ready',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $application): void {
            $application->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'data_classification' => DataClassification::class,
            'environments' => 'array',
            'requested_models' => 'array',
            'requested_capabilities' => 'array',
            'prompt_response_retention_enabled' => 'boolean',
            'configuration_ready' => 'boolean',
            'cost_budget_monthly' => 'decimal:2',
            'cost_budget_daily' => 'decimal:2',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'application_user')
            ->withPivot(['role', 'created_by'])
            ->withTimestamps();
    }

    public function lifecycleHistory(): HasMany
    {
        return $this->hasMany(ApplicationLifecycleHistory::class);
    }

    public function machineCredentials(): HasMany
    {
        return $this->hasMany(MachineCredential::class);
    }

    public function modelGrants(): HasMany
    {
        return $this->hasMany(ApplicationModelGrant::class);
    }

    public function isOwner(User $user): bool
    {
        return $this->users()
            ->whereKey($user->getKey())
            ->wherePivot('role', ApplicationMemberRole::Owner->value)
            ->exists();
    }

    public function hasMember(User $user): bool
    {
        return $this->users()->whereKey($user->getKey())->exists();
    }
}

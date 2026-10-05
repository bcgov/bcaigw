<?php

namespace App\Models;

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

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    public const ROLE_OWNER = 'owner';
    public const ROLE_MEMBER = 'member';

    protected $guarded = [
        'id',
        'public_id',
        'gateway_key',
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
            $application->gateway_key ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
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

    public function modelGrants(): HasMany
    {
        return $this->hasMany(ApplicationModelGrant::class);
    }

    public function applicationEnvironments(): HasMany
    {
        return $this->hasMany(ApplicationEnvironment::class);
    }

    public function environment(string $environment): ?ApplicationEnvironment
    {
        return $this->applicationEnvironments()->where('environment', $environment)->first();
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(ApplicationEnvironmentPromotion::class);
    }

    public function isOwner(User $user): bool
    {
        return $this->users()
            ->whereKey($user->getKey())
            ->wherePivot('role', self::ROLE_OWNER)
            ->exists();
    }

    public function hasMember(User $user): bool
    {
        return $this->users()->whereKey($user->getKey())->exists();
    }

    public function ownerCanEdit(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ApplicationEnvironment extends Model
{
    public const ENV_DEVELOPMENT = 'development';
    public const ENV_TEST = 'test';
    public const ENV_PRODUCTION = 'production';

    // Operational (running) status. Independent of any pending promotion review.
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Ordered promotion pipeline: each environment promotes to the next.
     *
     * @var array<string, string>
     */
    public const NEXT_ENVIRONMENT = [
        self::ENV_DEVELOPMENT => self::ENV_TEST,
        self::ENV_TEST => self::ENV_PRODUCTION,
    ];

    protected $guarded = ['id', 'public_id'];

    protected static function booted(): void
    {
        static::creating(function (self $environment): void {
            $environment->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'cost_budget_daily' => 'decimal:2',
            'cost_budget_monthly' => 'decimal:2',
            'approved_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(ApplicationModelGrant::class, 'application_id', 'application_id')
            ->where('application_model_grants.environment', $this->environment);
    }

    public function isServing(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function nextEnvironment(): ?string
    {
        return self::NEXT_ENVIRONMENT[$this->environment] ?? null;
    }
}

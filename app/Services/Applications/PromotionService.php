<?php

namespace App\Services\Applications;

use App\Models\Application;
use App\Models\ApplicationEnvironment;
use App\Models\ApplicationEnvironmentPromotion;
use App\Models\ApplicationModelGrant;
use App\Models\PublicModelAlias;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns the environment promotion pipeline (development -> test -> production).
 *
 * A promotion captures a snapshot of the source environment's enabled model
 * grants and its budgets, then waits for admin approval. Approving applies the
 * snapshot to the target environment and activates it; a pending promotion never
 * disrupts an environment that is already serving traffic.
 */
class PromotionService
{
    /**
     * The budget columns copied from one environment to another on promotion.
     *
     * @var list<string>
     */
    private const BUDGET_FIELDS = [
        'rate_limit_per_minute',
        'token_rate_per_minute',
        'token_budget_daily',
        'token_budget_monthly',
        'cost_budget_daily',
        'cost_budget_monthly',
        'budget_currency',
    ];

    /**
     * Ensure the development environment exists and is active. Called when an
     * application is activated: development is the default, self-serve sandbox.
     */
    public function ensureDevelopmentEnvironment(Application $application, User $actor): ApplicationEnvironment
    {
        return DB::transaction(function () use ($application, $actor): ApplicationEnvironment {
            $environment = $application->applicationEnvironments()
                ->where('environment', ApplicationEnvironment::ENV_DEVELOPMENT)
                ->lockForUpdate()
                ->first();

            if ($environment === null) {
                $environment = new ApplicationEnvironment([
                    'environment' => ApplicationEnvironment::ENV_DEVELOPMENT,
                    'rate_limit_per_minute' => $application->rate_limit_per_minute,
                    'token_rate_per_minute' => $application->token_rate_per_minute,
                    'token_budget_daily' => $application->token_budget_daily,
                    'token_budget_monthly' => $application->token_budget_monthly,
                    'cost_budget_daily' => $application->cost_budget_daily,
                    'cost_budget_monthly' => $application->cost_budget_monthly,
                    'budget_currency' => $application->budget_currency ?: 'CAD',
                    'created_by' => $actor->id,
                ]);
                $environment->application_id = $application->id;
                $environment->configuration_version = 1;
            }

            $environment->status = ApplicationEnvironment::STATUS_ACTIVE;
            $environment->approved_by = $actor->id;
            $environment->approved_at = Carbon::now();
            $environment->save();

            return $environment;
        });
    }

    /**
     * Request promotion of an application from one environment to the next. The
     * current source-environment model grants and budgets are snapshotted; any
     * earlier pending promotion to the same target is superseded. The target
     * environment keeps running its previously approved configuration until this
     * promotion is approved.
     */
    public function requestPromotion(Application $application, string $fromEnvironment, User $actor): ApplicationEnvironmentPromotion
    {
        $toEnvironment = ApplicationEnvironment::NEXT_ENVIRONMENT[$fromEnvironment] ?? null;

        if ($toEnvironment === null) {
            throw new RuntimeException('This environment cannot be promoted further.');
        }

        $source = $application->environment($fromEnvironment);

        if ($source === null || $source->status !== ApplicationEnvironment::STATUS_ACTIVE) {
            throw new RuntimeException('The source environment must be active before it can be promoted.');
        }

        return DB::transaction(function () use ($application, $source, $fromEnvironment, $toEnvironment, $actor): ApplicationEnvironmentPromotion {
            // Ensure a target environment row exists so it can be reviewed/tracked.
            $target = $application->applicationEnvironments()
                ->where('environment', $toEnvironment)
                ->lockForUpdate()
                ->first();

            if ($target === null) {
                $target = new ApplicationEnvironment([
                    'environment' => $toEnvironment,
                    'status' => ApplicationEnvironment::STATUS_PENDING,
                    'promoted_from' => $fromEnvironment,
                    'budget_currency' => $source->budget_currency ?: 'CAD',
                    'created_by' => $actor->id,
                ]);
                $target->application_id = $application->id;
                $target->configuration_version = 0;
                $target->save();
            }

            // Supersede any earlier pending promotion to the same target.
            $application->promotions()
                ->where('to_environment', $toEnvironment)
                ->where('status', ApplicationEnvironmentPromotion::STATUS_PENDING)
                ->update([
                    'status' => ApplicationEnvironmentPromotion::STATUS_SUPERSEDED,
                    'reviewed_at' => Carbon::now(),
                ]);

            $environment = $target;
            $environment->submitted_at = Carbon::now();
            $environment->save();

            return $application->promotions()->create([
                'from_environment' => $fromEnvironment,
                'to_environment' => $toEnvironment,
                'status' => ApplicationEnvironmentPromotion::STATUS_PENDING,
                'models_snapshot' => $this->snapshotGrants($application, $fromEnvironment),
                'proposed_budgets' => $this->budgetSnapshot($source),
                'requested_by' => $actor->id,
                'requested_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Request a change to the development environment's granted models. Recorded
     * as a same-environment (development -> development) request that keeps the
     * environment serving its current grants until an admin approves. Any earlier
     * pending change request for development is superseded.
     *
     * @param  array<int, array{alias_public_id: string, capabilities?: array<int, string>}>  $selections
     */
    public function requestModelChange(Application $application, array $selections, User $actor): ApplicationEnvironmentPromotion
    {
        $environment = ApplicationEnvironment::ENV_DEVELOPMENT;
        $source = $application->environment($environment);

        if ($source === null || $source->status !== ApplicationEnvironment::STATUS_ACTIVE) {
            throw new RuntimeException('The development environment must be active before its models can be changed.');
        }

        $snapshot = $this->normalizeSelections($selections);

        if ($snapshot === []) {
            throw new RuntimeException('Select at least one available model.');
        }

        return DB::transaction(function () use ($application, $source, $environment, $snapshot, $actor): ApplicationEnvironmentPromotion {
            $application->promotions()
                ->where('from_environment', $environment)
                ->where('to_environment', $environment)
                ->where('status', ApplicationEnvironmentPromotion::STATUS_PENDING)
                ->update([
                    'status' => ApplicationEnvironmentPromotion::STATUS_SUPERSEDED,
                    'reviewed_at' => Carbon::now(),
                ]);

            return $application->promotions()->create([
                'from_environment' => $environment,
                'to_environment' => $environment,
                'status' => ApplicationEnvironmentPromotion::STATUS_PENDING,
                'models_snapshot' => $snapshot,
                'proposed_budgets' => $this->budgetSnapshot($source),
                'requested_by' => $actor->id,
                'requested_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Resolve developer model selections (alias public ids + requested
     * capabilities) into a snapshot of active aliases, clamping capabilities to
     * those each alias advertises.
     *
     * @param  array<int, array{alias_public_id?: string, capabilities?: array<int, string>}>  $selections
     * @return array<int, array{public_model_alias_id: int, model_id: ?string, capabilities: array<int, string>}>
     */
    private function normalizeSelections(array $selections): array
    {
        $aliasPublicIds = array_values(array_filter(array_map(
            fn ($selection) => $selection['alias_public_id'] ?? null,
            $selections,
        )));

        if ($aliasPublicIds === []) {
            return [];
        }

        $aliases = PublicModelAlias::query()
            ->whereIn('public_id', $aliasPublicIds)
            ->where('status', 'active')
            ->get(['id', 'public_id', 'model_id', 'capabilities'])
            ->keyBy('public_id');

        $snapshot = [];

        foreach ($selections as $selection) {
            $alias = $aliases->get($selection['alias_public_id'] ?? null);

            if ($alias === null) {
                continue;
            }

            $allowed = (array) ($alias->capabilities ?? []);
            $requested = array_values(array_filter((array) ($selection['capabilities'] ?? [])));
            $capabilities = array_values(array_intersect($requested, $allowed));

            if ($capabilities === []) {
                $capabilities = $allowed;
            }

            $snapshot[] = [
                'public_model_alias_id' => (int) $alias->id,
                'model_id' => $alias->model_id,
                'capabilities' => $capabilities,
            ];
        }

        return $snapshot;
    }

    /**
     * Approve a pending promotion: apply the model snapshot and (possibly
     * admin-adjusted) budgets to the target environment and activate it.
     *
     * @param  array<string, mixed>  $budgetOverrides
     */
    public function approve(ApplicationEnvironmentPromotion $promotion, User $actor, array $budgetOverrides = [], ?string $note = null): ApplicationEnvironment
    {
        if (! $promotion->isPending()) {
            throw new RuntimeException('Only a pending promotion can be approved.');
        }

        return DB::transaction(function () use ($promotion, $actor, $budgetOverrides, $note): ApplicationEnvironment {
            $application = $promotion->application;
            $environment = $application->environment($promotion->to_environment);

            if ($environment === null) {
                throw new RuntimeException('The target environment no longer exists.');
            }

            $this->applyGrants($application, $promotion->to_environment, (array) $promotion->models_snapshot, $actor);

            $budgets = array_merge((array) $promotion->proposed_budgets, $this->filterBudgets($budgetOverrides));
            foreach (self::BUDGET_FIELDS as $field) {
                if (array_key_exists($field, $budgets)) {
                    $environment->{$field} = $budgets[$field];
                }
            }

            $environment->status = ApplicationEnvironment::STATUS_ACTIVE;
            $environment->promoted_from = $promotion->from_environment;
            $environment->approved_by = $actor->id;
            $environment->approved_at = Carbon::now();
            $environment->configuration_version = (int) $environment->configuration_version + 1;
            $environment->status_version = (int) $environment->status_version + 1;
            $environment->save();

            $promotion->status = ApplicationEnvironmentPromotion::STATUS_APPROVED;
            $promotion->reviewed_by = $actor->id;
            $promotion->reviewed_at = Carbon::now();
            $promotion->review_note = $note;
            $promotion->save();

            return $environment;
        });
    }

    /**
     * Reject a pending promotion. A currently-running target environment keeps
     * serving its previously approved configuration unchanged.
     */
    public function reject(ApplicationEnvironmentPromotion $promotion, User $actor, ?string $note = null): void
    {
        if (! $promotion->isPending()) {
            throw new RuntimeException('Only a pending promotion can be rejected.');
        }

        $promotion->status = ApplicationEnvironmentPromotion::STATUS_REJECTED;
        $promotion->reviewed_by = $actor->id;
        $promotion->reviewed_at = Carbon::now();
        $promotion->review_note = $note;
        $promotion->save();
    }

    /**
     * Capture the enabled model grants of an environment as a portable snapshot.
     *
     * @return array<int, array{public_model_alias_id: int, model_id: ?string, capabilities: array<int, string>}>
     */
    public function snapshotGrants(Application $application, string $environment): array
    {
        return $application->modelGrants()
            ->where('environment', $environment)
            ->where('enabled', true)
            ->with('alias:id,model_id')
            ->get()
            ->map(fn (ApplicationModelGrant $grant) => [
                'public_model_alias_id' => (int) $grant->public_model_alias_id,
                'model_id' => $grant->alias?->model_id,
                'capabilities' => array_values((array) $grant->capabilities),
            ])
            ->all();
    }

    /**
     * Apply a model snapshot to a target environment: enable the snapshot models
     * and disable any grants no longer present (grants are never deleted).
     *
     * @param  array<int, array<string, mixed>>  $snapshot
     */
    private function applyGrants(Application $application, string $environment, array $snapshot, User $actor): void
    {
        $keepAliasIds = [];

        foreach ($snapshot as $entry) {
            $aliasId = (int) ($entry['public_model_alias_id'] ?? 0);

            if ($aliasId === 0) {
                continue;
            }

            $keepAliasIds[] = $aliasId;

            $application->modelGrants()->updateOrCreate(
                ['environment' => $environment, 'public_model_alias_id' => $aliasId],
                [
                    'capabilities' => array_values((array) ($entry['capabilities'] ?? [])),
                    'enabled' => true,
                    'granted_by' => $actor->id,
                ],
            );
        }

        $application->modelGrants()
            ->where('environment', $environment)
            ->where('enabled', true)
            ->when($keepAliasIds !== [], fn ($query) => $query->whereNotIn('public_model_alias_id', $keepAliasIds))
            ->update(['enabled' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetSnapshot(ApplicationEnvironment $environment): array
    {
        $snapshot = [];

        foreach (self::BUDGET_FIELDS as $field) {
            $snapshot[$field] = $environment->{$field};
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function filterBudgets(array $values): array
    {
        return array_intersect_key($values, array_flip(self::BUDGET_FIELDS));
    }
}

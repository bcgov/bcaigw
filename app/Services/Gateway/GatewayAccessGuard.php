<?php

namespace App\Services\Gateway;

use App\Models\Application;
use App\Models\ApplicationEnvironment;
use App\Models\ApplicationModelGrant;
use App\Models\GatewayUsageRollup;
use App\Models\ModelPricingVersion;
use App\Models\PublicModelAlias;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Enforces the conditions under which an application is allowed to invoke a model:
 * active status, a granted+enabled model with the required capability, an approved
 * environment, and usage within the per-minute rate limit and monthly token budget.
 *
 * @phpstan-type ResolvedInvocation array{alias: PublicModelAlias, target: \App\Models\UpstreamTarget, grant: ApplicationModelGrant, pricing: ?ModelPricingVersion, environment: ApplicationEnvironment}
 */
class GatewayAccessGuard
{
    /**
     * @return ResolvedInvocation
     *
     * @throws GatewayException
     */
    public function authorize(Application $application, string $modelId, string $capability, ?string $environment): array
    {
        if ($application->status !== Application::STATUS_ACTIVE) {
            throw new GatewayException('The application is not active.', 403, 'application_inactive');
        }

        $environmentRecord = $this->resolveEnvironment($application, $environment);

        $grant = $this->resolveGrant($application, $modelId, $capability, $environmentRecord->environment);

        /** @var PublicModelAlias $alias */
        $alias = $grant->alias;
        $target = $alias->activeTarget;

        if ($target === null || $target->status !== 'active') {
            throw new GatewayException('The requested model has no active upstream target.', 503, 'model_unavailable');
        }

        $this->assertWithinMonthlyBudget($application, $environmentRecord);
        $this->assertWithinDailyTokenBudget($application, $environmentRecord);
        $this->assertWithinCostBudgets($application, $environmentRecord);
        // Last, so a request rejected on budget does not consume a rate-limit slot.
        $this->assertWithinRateLimit($application, $environmentRecord);

        return [
            'alias' => $alias,
            'target' => $target,
            'grant' => $grant,
            'pricing' => $this->latestPricing($alias),
            'environment' => $environmentRecord,
        ];
    }

    private function resolveEnvironment(Application $application, ?string $environment): ApplicationEnvironment
    {
        if ($environment === null || $environment === '') {
            throw new GatewayException('A target environment must be supplied.', 400, 'environment_required');
        }

        $record = $application->environment($environment);

        if ($record === null) {
            throw new GatewayException('The environment is not approved for this application.', 403, 'environment_not_approved');
        }

        if ($record->status !== ApplicationEnvironment::STATUS_ACTIVE) {
            throw new GatewayException('The environment is not active for this application.', 403, 'environment_inactive');
        }

        return $record;
    }

    private function resolveGrant(Application $application, string $modelId, string $capability, string $environment): ApplicationModelGrant
    {
        /** @var ApplicationModelGrant|null $grant */
        $grant = $application->modelGrants()
            ->where('enabled', true)
            ->where('environment', $environment)
            ->whereHas('alias', fn ($query) => $query->where('model_id', $modelId)->where('status', 'active'))
            ->with('alias.activeTarget')
            ->first();

        if ($grant === null) {
            throw new GatewayException('The model is not granted to this application.', 403, 'model_not_granted');
        }

        if (! in_array($capability, (array) $grant->capabilities, true)) {
            $aliasCapabilities = (array) ($grant->alias?->capabilities ?? []);

            // An embeddings model produces vectors, not chat text — steer the caller
            // to a chat-capable model instead of a generic capability error.
            if ($capability === 'chat'
                && in_array('embedding', $aliasCapabilities, true)
                && ! in_array('chat', $aliasCapabilities, true)) {
                throw new GatewayException(
                    'This is an embeddings model and cannot be used with the chat completions endpoint. Use a chat-capable model.',
                    422,
                    'embeddings_model_unsupported',
                );
            }

            throw new GatewayException('The model is not granted for this capability.', 403, 'capability_not_granted');
        }

        return $grant;
    }

    private function assertWithinRateLimit(Application $application, ApplicationEnvironment $environment): void
    {
        $limit = $environment->rate_limit_per_minute
            ?? $application->rate_limit_per_minute
            ?? $application->expected_requests_per_minute;

        if ($limit === null || $limit <= 0) {
            return;
        }

        $key = 'gateway:rate:'.$application->id.':'.$environment->environment.':'.Carbon::now()->format('YmdHi');
        $used = (int) Cache::get($key, 0);

        if ($used >= $limit) {
            throw new GatewayException('The per-minute request limit has been reached.', 429, 'rate_limit_exceeded');
        }

        Cache::put($key, $used + 1, 60);
    }

    private function assertWithinMonthlyBudget(Application $application, ApplicationEnvironment $environment): void
    {
        // The environment's own monthly token budget is enforced, falling back to
        // the application-level budget (or approved expected tokens) when unset.
        $budget = $environment->token_budget_monthly
            ?? $application->token_budget_monthly
            ?? $application->expected_tokens_per_month;

        if ($budget === null || $budget <= 0) {
            return;
        }

        $used = (int) GatewayUsageRollup::query()
            ->where('application_id', $application->id)
            ->where('environment', $environment->environment)
            ->where('bucket_date', '>=', Carbon::now()->startOfMonth()->toDateString())
            ->sum(DB::raw('input_tokens + output_tokens'));

        if ($used >= $budget) {
            throw new GatewayException('The monthly token budget has been exhausted.', 429, 'monthly_budget_exceeded');
        }
    }

    private function assertWithinDailyTokenBudget(Application $application, ApplicationEnvironment $environment): void
    {
        $budget = $environment->token_budget_daily ?? $application->token_budget_daily;

        if ($budget === null || $budget <= 0) {
            return;
        }

        $used = (int) $this->usageSince($application, $environment, Carbon::now()->toDateString())
            ->sum(DB::raw('input_tokens + output_tokens'));

        if ($used >= $budget) {
            throw new GatewayException('The daily token budget has been exhausted.', 429, 'daily_budget_exceeded');
        }
    }

    private function assertWithinCostBudgets(Application $application, ApplicationEnvironment $environment): void
    {
        $windows = [
            ['daily', $environment->cost_budget_daily ?? $application->cost_budget_daily, Carbon::now()->toDateString()],
            ['monthly', $environment->cost_budget_monthly ?? $application->cost_budget_monthly, Carbon::now()->startOfMonth()->toDateString()],
        ];

        foreach ($windows as [$label, $budget, $from]) {
            if ($budget === null || (float) $budget <= 0) {
                continue;
            }

            // Rollup cost is stored in micro-units of the pricing currency.
            $spent = ((int) $this->usageSince($application, $environment, $from)->sum('cost_microunits')) / 1_000_000;

            if ($spent >= (float) $budget) {
                $currency = $environment->budget_currency ?: (string) config('gateway.default_budget_currency', 'USD');

                throw new GatewayException(
                    "The {$label} cost budget of {$budget} {$currency} has been exhausted.",
                    429,
                    "{$label}_cost_budget_exceeded",
                );
            }
        }
    }

    private function usageSince(Application $application, ApplicationEnvironment $environment, string $from): \Illuminate\Database\Eloquent\Builder
    {
        return GatewayUsageRollup::query()
            ->where('application_id', $application->id)
            ->where('environment', $environment->environment)
            ->where('bucket_date', '>=', $from);
    }

    private function latestPricing(PublicModelAlias $alias): ?ModelPricingVersion
    {
        return $alias->pricingVersions()
            ->orderByDesc('effective_at')
            ->first();
    }
}

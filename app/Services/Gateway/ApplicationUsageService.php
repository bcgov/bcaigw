<?php

namespace App\Services\Gateway;

use App\Models\Application;
use App\Models\ApplicationEnvironment;
use App\Models\GatewayUsageRollup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates GatewayUsageRollup rows into per-application usage summaries used by
 * the admin monitoring views. Rollups are stored one row per day/model/provider/outcome.
 */
class ApplicationUsageService
{
    /**
     * Month-to-date and today usage for many applications, keyed by application id.
     *
     * @param  array<int, int>  $applicationIds
     * @return array<int, array{month: array<string, mixed>, today: array<string, mixed>}>
     */
    public function listSummary(array $applicationIds): array
    {
        if ($applicationIds === []) {
            return [];
        }

        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $today = Carbon::now()->toDateString();

        $month = $this->aggregateByApplication($applicationIds, $monthStart);
        $todayAgg = $this->aggregateByApplication($applicationIds, $today, $today);

        $summary = [];
        foreach ($applicationIds as $id) {
            $summary[$id] = [
                'month' => $month[$id] ?? $this->emptyBucket(),
                'today' => $todayAgg[$id] ?? $this->emptyBucket(),
            ];
        }

        return $summary;
    }

    /**
     * Full usage + limits detail for a single application.
     *
     * @return array<string, mixed>
     */
    public function detail(Application $application): array
    {
        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $today = Carbon::now()->toDateString();

        return [
            'currency' => $application->budget_currency ?: 'CAD',
            'limits' => $this->limits($application),
            'today' => $this->aggregateForApplication($application->id, $today, $today),
            'month' => $this->aggregateForApplication($application->id, $monthStart),
            'total' => $this->aggregateForApplication($application->id, null),
            'by_model' => $this->byModel($application->id, $monthStart),
        ];
    }

    /**
     * Full usage + limits detail for a single environment of an application.
     *
     * @return array<string, mixed>
     */
    public function environmentDetail(Application $application, ApplicationEnvironment $environment): array
    {
        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $today = Carbon::now()->toDateString();
        $name = $environment->environment;

        return [
            'currency' => $environment->budget_currency ?: 'CAD',
            'limits' => $this->environmentLimits($environment),
            'today' => $this->aggregateForApplication($application->id, $today, $today, $name),
            'month' => $this->aggregateForApplication($application->id, $monthStart, null, $name),
            'total' => $this->aggregateForApplication($application->id, null, null, $name),
            'by_model' => $this->byModel($application->id, $monthStart, $name),
        ];
    }

    /**
     * Fleet-wide metrics for the admin dashboard: current-month and today totals
     * across all applications, plus the top consuming applications and models.
     *
     * @return array<string, mixed>
     */
    public function dashboard(int $topLimit = 5): array
    {
        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $today = Carbon::now()->toDateString();

        return [
            'currency' => 'CAD',
            'month' => $this->fleetTotals($monthStart),
            'today' => $this->fleetTotals($today, $today),
            'top_applications' => $this->topApplications($monthStart, $topLimit),
            'top_models' => $this->topModels($monthStart, $topLimit),
            'active_applications' => $this->activeApplicationCount($monthStart),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fleetTotals(string $from, ?string $to = null): array
    {
        $query = GatewayUsageRollup::query()->where('bucket_date', '>=', $from);

        if ($to !== null) {
            $query->where('bucket_date', '<=', $to);
        }

        return $this->bucketFromRow($query->get($this->aggregateColumns())->first());
    }

    /**
     * Applications that made at least one gateway call since the given date.
     */
    private function activeApplicationCount(string $from): int
    {
        return (int) GatewayUsageRollup::query()
            ->where('bucket_date', '>=', $from)
            ->distinct()
            ->count('application_id');
    }

    /**
     * Highest token-consuming applications since the given date.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topApplications(string $from, int $limit): array
    {
        return GatewayUsageRollup::query()
            ->where('bucket_date', '>=', $from)
            ->join('applications', 'applications.id', '=', 'gateway_usage_rollups.application_id')
            ->groupBy('applications.id', 'applications.public_id', 'applications.name')
            ->orderByRaw('SUM(total_tokens) DESC')
            ->limit($limit)
            ->get([
                'applications.public_id as public_id',
                'applications.name as name',
                DB::raw('SUM(requests) as requests'),
                DB::raw('SUM(failures) as failures'),
                DB::raw('SUM(total_tokens) as total_tokens'),
                DB::raw('SUM(cost_microunits) as cost_microunits'),
            ])
            ->map(fn ($row) => [
                'public_id' => $row->public_id,
                'name' => $row->name,
                'requests' => (int) $row->requests,
                'failures' => (int) $row->failures,
                'total_tokens' => (int) $row->total_tokens,
                'cost' => round(((int) $row->cost_microunits) / 1_000_000, 2),
            ])
            ->all();
    }

    /**
     * Highest token-consuming models since the given date (across all applications).
     *
     * @return array<int, array<string, mixed>>
     */
    private function topModels(string $from, int $limit): array
    {
        return GatewayUsageRollup::query()
            ->where('bucket_date', '>=', $from)
            ->leftJoin('public_model_aliases', 'public_model_aliases.id', '=', 'gateway_usage_rollups.public_model_alias_id')
            ->groupBy('public_model_aliases.model_id', 'public_model_aliases.display_name')
            ->orderByRaw('SUM(total_tokens) DESC')
            ->limit($limit)
            ->get([
                'public_model_aliases.model_id as model_id',
                'public_model_aliases.display_name as display_name',
                DB::raw('SUM(requests) as requests'),
                DB::raw('SUM(total_tokens) as total_tokens'),
                DB::raw('SUM(cost_microunits) as cost_microunits'),
            ])
            ->map(fn ($row) => [
                'model_id' => $row->model_id,
                'display_name' => $row->display_name,
                'requests' => (int) $row->requests,
                'total_tokens' => (int) $row->total_tokens,
                'cost' => round(((int) $row->cost_microunits) / 1_000_000, 2),
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $applicationIds
     * @return array<int, array<string, mixed>>
     */
    private function aggregateByApplication(array $applicationIds, string $from, ?string $to = null): array
    {
        $query = GatewayUsageRollup::query()
            ->whereIn('application_id', $applicationIds)
            ->where('bucket_date', '>=', $from);

        if ($to !== null) {
            $query->where('bucket_date', '<=', $to);
        }

        return $query
            ->groupBy('application_id')
            ->get(array_merge(['application_id'], $this->aggregateColumns()))
            ->keyBy('application_id')
            ->map(fn ($row) => $this->bucketFromRow($row))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregateForApplication(int $applicationId, ?string $from, ?string $to = null, ?string $environment = null): array
    {
        $query = GatewayUsageRollup::query()->where('application_id', $applicationId);

        if ($environment !== null) {
            $query->where('environment', $environment);
        }

        if ($from !== null) {
            $query->where('bucket_date', '>=', $from);
        }

        if ($to !== null) {
            $query->where('bucket_date', '<=', $to);
        }

        $row = $query->get($this->aggregateColumns())->first();

        return $this->bucketFromRow($row);
    }

    /**
     * Month-to-date usage grouped by model alias.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byModel(int $applicationId, string $from, ?string $environment = null): array
    {
        return GatewayUsageRollup::query()
            ->where('gateway_usage_rollups.application_id', $applicationId)
            ->where('bucket_date', '>=', $from)
            ->when($environment !== null, fn ($query) => $query->where('gateway_usage_rollups.environment', $environment))
            ->leftJoin('public_model_aliases', 'public_model_aliases.id', '=', 'gateway_usage_rollups.public_model_alias_id')
            ->groupBy('public_model_aliases.model_id', 'public_model_aliases.display_name')
            ->orderByRaw('SUM(total_tokens) DESC')
            ->get([
                'public_model_aliases.model_id as model_id',
                'public_model_aliases.display_name as display_name',
                DB::raw('SUM(requests) as requests'),
                DB::raw('SUM(failures) as failures'),
                DB::raw('SUM(total_tokens) as total_tokens'),
                DB::raw('SUM(cost_microunits) as cost_microunits'),
            ])
            ->map(fn ($row) => [
                'model_id' => $row->model_id,
                'display_name' => $row->display_name,
                'requests' => (int) $row->requests,
                'failures' => (int) $row->failures,
                'total_tokens' => (int) $row->total_tokens,
                'cost' => round(((int) $row->cost_microunits) / 1_000_000, 2),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function limits(Application $application): array
    {
        // The approved expected values are the enforced limits unless an explicit override is configured.
        $effectiveRate = $application->rate_limit_per_minute ?? $application->expected_requests_per_minute;
        $effectiveMonthlyTokens = $application->token_budget_monthly ?? $application->expected_tokens_per_month;
        $derivedCostMonthly = $this->derivedMonthlyCost($application, $effectiveMonthlyTokens);

        return [
            'rate_limit_per_minute' => $application->rate_limit_per_minute,
            'expected_requests_per_minute' => $application->expected_requests_per_minute,
            'effective_rate_limit_per_minute' => $effectiveRate,
            'token_rate_per_minute' => $application->token_rate_per_minute,
            'token_budget_daily' => $application->token_budget_daily,
            'token_budget_monthly' => $application->token_budget_monthly,
            'expected_tokens_per_month' => $application->expected_tokens_per_month,
            'effective_token_budget_monthly' => $effectiveMonthlyTokens,
            'cost_budget_daily' => $application->cost_budget_daily,
            'cost_budget_monthly' => $application->cost_budget_monthly,
            'derived_cost_budget_monthly' => $derivedCostMonthly,
            'effective_cost_budget_monthly' => $application->cost_budget_monthly !== null
                ? (float) $application->cost_budget_monthly
                : $derivedCostMonthly,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function environmentLimits(ApplicationEnvironment $environment): array
    {
        return [
            'rate_limit_per_minute' => $environment->rate_limit_per_minute,
            'effective_rate_limit_per_minute' => $environment->rate_limit_per_minute,
            'token_rate_per_minute' => $environment->token_rate_per_minute,
            'token_budget_daily' => $environment->token_budget_daily,
            'token_budget_monthly' => $environment->token_budget_monthly,
            'effective_token_budget_monthly' => $environment->token_budget_monthly,
            'cost_budget_daily' => $environment->cost_budget_daily !== null ? (float) $environment->cost_budget_daily : null,
            'cost_budget_monthly' => $environment->cost_budget_monthly !== null ? (float) $environment->cost_budget_monthly : null,
            'effective_cost_budget_monthly' => $environment->cost_budget_monthly !== null ? (float) $environment->cost_budget_monthly : null,
        ];
    }

    /**
     * Estimate the monthly cost implied by the approved token volume, using the most
     * expensive enabled granted model's blended price as a conservative ceiling.
     */
    private function derivedMonthlyCost(Application $application, ?int $expectedTokens): ?float
    {
        if ($expectedTokens === null || $expectedTokens <= 0) {
            return null;
        }

        $perMillion = $this->maxBlendedPricePerMillion($application);

        if ($perMillion === null) {
            return null;
        }

        return round(($expectedTokens / 1_000_000) * $perMillion, 2);
    }

    /**
     * Highest blended (input+output average) price per million tokens across the
     * application's enabled granted models, or null when no priced model is granted.
     */
    private function maxBlendedPricePerMillion(Application $application): ?float
    {
        $grants = $application->modelGrants()
            ->where('enabled', true)
            ->with(['alias.pricingVersions' => fn ($query) => $query->orderByDesc('effective_at')])
            ->get();

        $max = null;

        foreach ($grants as $grant) {
            $pricing = $grant->alias?->pricingVersions->first();

            if ($pricing === null) {
                continue;
            }

            $blended = ((float) $pricing->input_cost_per_million_tokens + (float) $pricing->output_cost_per_million_tokens) / 2;
            $max = $max === null ? $blended : max($max, $blended);
        }

        return $max;
    }

    /**
     * @return array<int, \Illuminate\Contracts\Database\Query\Expression|string>
     */
    private function aggregateColumns(): array
    {
        return [
            DB::raw('SUM(requests) as requests'),
            DB::raw('SUM(failures) as failures'),
            DB::raw('SUM(input_tokens) as input_tokens'),
            DB::raw('SUM(output_tokens) as output_tokens'),
            DB::raw('SUM(total_tokens) as total_tokens'),
            DB::raw('SUM(cost_microunits) as cost_microunits'),
            DB::raw('SUM(latency_ms_sum) as latency_ms_sum'),
            DB::raw('MAX(latency_ms_max) as latency_ms_max'),
        ];
    }

    /**
     * @param  \App\Models\GatewayUsageRollup|null  $row
     * @return array<string, mixed>
     */
    private function bucketFromRow($row): array
    {
        if ($row === null) {
            return $this->emptyBucket();
        }

        $requests = (int) $row->requests;
        $latencySum = (int) $row->latency_ms_sum;

        return [
            'requests' => $requests,
            'failures' => (int) $row->failures,
            'input_tokens' => (int) $row->input_tokens,
            'output_tokens' => (int) $row->output_tokens,
            'total_tokens' => (int) $row->total_tokens,
            'cost' => round(((int) $row->cost_microunits) / 1_000_000, 2),
            'avg_latency_ms' => $requests > 0 ? (int) round($latencySum / $requests) : 0,
            'max_latency_ms' => (int) $row->latency_ms_max,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBucket(): array
    {
        return [
            'requests' => 0,
            'failures' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cost' => 0.0,
            'avg_latency_ms' => 0,
            'max_latency_ms' => 0,
        ];
    }
}

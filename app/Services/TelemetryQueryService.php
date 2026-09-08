<?php

namespace App\Services;

use App\Models\Application;
use App\Models\GatewayCallAttempt;
use App\Models\GatewayUsageRollup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Tenant-scoped querying of call telemetry.
 *
 * Every query is scoped to the applications the actor may see, so an owner can
 * never observe another tenant's traffic. Content is never selected here: only
 * indexed metadata columns are read, and reveals go through the dedicated,
 * audited retention service.
 */
final readonly class TelemetryQueryService
{
    /** Indexed metadata safe to render in lists and exports. */
    public const LIST_COLUMNS = [
        'gateway_call_attempts.id',
        'gateway_call_attempts.request_id',
        'gateway_call_attempts.application_id',
        'gateway_call_attempts.machine_credential_id',
        'gateway_call_attempts.public_model_alias_id',
        'gateway_call_attempts.provider_account_id',
        'gateway_call_attempts.operation',
        'gateway_call_attempts.requested_model',
        'gateway_call_attempts.resolved_model_alias',
        'gateway_call_attempts.streaming',
        'gateway_call_attempts.status',
        'gateway_call_attempts.http_status',
        'gateway_call_attempts.error_code',
        'gateway_call_attempts.error_category',
        'gateway_call_attempts.prompt_tokens',
        'gateway_call_attempts.completion_tokens',
        'gateway_call_attempts.cached_input_tokens',
        'gateway_call_attempts.total_tokens',
        'gateway_call_attempts.cost_microunits',
        'gateway_call_attempts.cost_currency',
        'gateway_call_attempts.queue_latency_ms',
        'gateway_call_attempts.upstream_latency_ms',
        'gateway_call_attempts.total_latency_ms',
        'gateway_call_attempts.time_to_first_token_ms',
        'gateway_call_attempts.retry_count',
        'gateway_call_attempts.client_cancelled',
        'gateway_call_attempts.partial_response',
        'gateway_call_attempts.content_state',
        'gateway_call_attempts.content_retention_enabled',
        'gateway_call_attempts.content_deleted_at',
        'gateway_call_attempts.details_redacted_at',
        'gateway_call_attempts.started_at',
        'gateway_call_attempts.completed_at',
    ];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(User $actor, array $filters): Builder
    {
        $query = GatewayCallAttempt::query()->select(self::LIST_COLUMNS);

        if (! $actor->isAdministrator()) {
            $query->whereIn(
                'gateway_call_attempts.application_id',
                $actor->applications()->select('applications.id'),
            );
        }

        if (($filters['application'] ?? null) !== null) {
            $query->whereIn(
                'gateway_call_attempts.application_id',
                Application::query()
                    ->select('id')
                    ->where('public_id', $filters['application']),
            );
        }
        if (($filters['status'] ?? null) !== null) {
            $query->where('gateway_call_attempts.status', $filters['status']);
        }
        if (($filters['operation'] ?? null) !== null) {
            $query->where('gateway_call_attempts.operation', $filters['operation']);
        }
        if (($filters['model'] ?? null) !== null) {
            $query->where('gateway_call_attempts.resolved_model_alias', $filters['model']);
        }
        if (($filters['error_category'] ?? null) !== null) {
            $query->where('gateway_call_attempts.error_category', $filters['error_category']);
        }
        if (($filters['request_id'] ?? null) !== null) {
            $query->where('gateway_call_attempts.request_id', $filters['request_id']);
        }
        if (($filters['from'] ?? null) !== null) {
            $query->where('gateway_call_attempts.started_at', '>=', CarbonImmutable::parse($filters['from'], 'UTC'));
        }
        if (($filters['to'] ?? null) !== null) {
            $query->where('gateway_call_attempts.started_at', '<=', CarbonImmutable::parse($filters['to'], 'UTC'));
        }

        return $query->orderByDesc('gateway_call_attempts.started_at')
            ->orderByDesc('gateway_call_attempts.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, GatewayCallAttempt>
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->query($actor, $filters)
            ->with([
                'application:id,public_id,name',
                'credential:id,public_id,name',
                'provider:id,public_id,type',
            ])
            ->paginate((int) config('telemetry.dashboard.page_size'))
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, GatewayCallAttempt>
     */
    public function exportRows(User $actor, array $filters): Collection
    {
        return $this->query($actor, $filters)
            ->with([
                'application:id,public_id,name',
                'credential:id,public_id,name',
                'provider:id,public_id,type',
            ])
            ->limit((int) config('telemetry.dashboard.export_max_rows'))
            ->get();
    }

    /**
     * Dashboard aggregates read pre-computed daily rollups so the summary never
     * performs an unbounded scan of the attempt table.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(User $actor, array $filters): array
    {
        $maxDays = (int) config('telemetry.rollup.summary_max_days');
        $to = isset($filters['to'])
            ? CarbonImmutable::parse($filters['to'], 'UTC')->toDateString()
            : CarbonImmutable::now('UTC')->toDateString();
        $from = isset($filters['from'])
            ? CarbonImmutable::parse($filters['from'], 'UTC')->toDateString()
            : CarbonImmutable::parse($to, 'UTC')->subDays(29)->toDateString();
        if (CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC')) > $maxDays) {
            $from = CarbonImmutable::parse($to, 'UTC')->subDays($maxDays)->toDateString();
        }

        $query = GatewayUsageRollup::query()
            ->whereBetween('bucket_date', [$from, $to]);

        if (! $actor->isAdministrator()) {
            $query->whereIn(
                'gateway_usage_rollups.application_id',
                $actor->applications()->select('applications.id'),
            );
        }
        if (($filters['application'] ?? null) !== null) {
            $query->whereIn(
                'gateway_usage_rollups.application_id',
                Application::query()->select('id')->where('public_id', $filters['application']),
            );
        }

        $rows = (clone $query)
            ->selectRaw('bucket_date, outcome, sum(requests) as requests, sum(failures) as failures,'
                .' sum(total_tokens) as total_tokens, sum(cost_microunits) as cost_microunits,'
                .' sum(latency_ms_sum) as latency_ms_sum, max(latency_ms_max) as latency_ms_max,'
                .' sum(ttft_ms_sum) as ttft_ms_sum, sum(ttft_samples) as ttft_samples')
            ->groupBy('bucket_date', 'outcome')
            ->orderBy('bucket_date')
            ->get();

        $byModel = (clone $query)
            ->selectRaw('public_model_alias_id, sum(requests) as requests, sum(total_tokens) as total_tokens,'
                .' sum(cost_microunits) as cost_microunits')
            ->with('alias:id,model_id')
            ->groupBy('public_model_alias_id')
            ->orderByRaw('sum(requests) desc')
            ->limit(20)
            ->get();

        $byProvider = (clone $query)
            ->selectRaw('provider_account_id, sum(requests) as requests, sum(cost_microunits) as cost_microunits')
            ->with('provider:id,public_id,type')
            ->groupBy('provider_account_id')
            ->orderByRaw('sum(requests) desc')
            ->limit(20)
            ->get();

        $requests = (int) $rows->sum('requests');
        $latencySum = (int) $rows->sum('latency_ms_sum');
        $ttftSamples = (int) $rows->sum('ttft_samples');

        return [
            'from' => $from,
            'to' => $to,
            'totals' => [
                'requests' => $requests,
                'failures' => (int) $rows->sum('failures'),
                'total_tokens' => (int) $rows->sum('total_tokens'),
                'cost_microunits' => (int) $rows->sum('cost_microunits'),
                'average_latency_ms' => $requests > 0 ? (int) round($latencySum / $requests) : 0,
                'max_latency_ms' => (int) $rows->max('latency_ms_max'),
                'average_ttft_ms' => $ttftSamples > 0
                    ? (int) round(((int) $rows->sum('ttft_ms_sum')) / $ttftSamples)
                    : null,
            ],
            'daily' => $rows->groupBy(fn ($row) => (string) $row->bucket_date)
                ->map(fn (Collection $group, string $date) => [
                    'date' => $date,
                    'requests' => (int) $group->sum('requests'),
                    'failures' => (int) $group->sum('failures'),
                    'total_tokens' => (int) $group->sum('total_tokens'),
                    'cost_microunits' => (int) $group->sum('cost_microunits'),
                ])->values(),
            'by_outcome' => $rows->groupBy('outcome')
                ->map(fn (Collection $group, string $outcome) => [
                    'outcome' => $outcome,
                    'requests' => (int) $group->sum('requests'),
                ])->values(),
            'by_model' => $byModel->map(fn ($row) => [
                'model_id' => $row->alias?->model_id,
                'requests' => (int) $row->requests,
                'total_tokens' => (int) $row->total_tokens,
                'cost_microunits' => (int) $row->cost_microunits,
            ])->values(),
            'by_provider' => $byProvider->map(fn ($row) => [
                'provider_public_id' => $row->provider?->public_id,
                'provider_type' => $row->provider?->type?->value,
                'requests' => (int) $row->requests,
                'cost_microunits' => (int) $row->cost_microunits,
            ])->values(),
        ];
    }
}

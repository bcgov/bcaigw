<?php

namespace App\Services;

use App\Models\GatewayCallAttempt;
use App\Models\GatewayUsageRollup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Materialises daily usage aggregates so dashboards never scan the raw attempt
 * table. Rollups are recomputed for a bounded lookback window, which makes the
 * job idempotent and tolerant of late-completing streaming calls.
 *
 * Buckets are keyed on the UTC calendar day of `started_at`, matching the
 * timezone used by quota windows.
 */
final class TelemetryRollupService
{
    public function rebuild(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): int
    {
        $to ??= CarbonImmutable::now('UTC')->endOfDay();
        $from ??= $to->subDays(max(0, (int) config('telemetry.rollup.lookback_days')))->startOfDay();

        $rows = GatewayCallAttempt::query()
            ->selectRaw($this->bucketExpression().' as bucket_date')
            ->selectRaw('application_id, public_model_alias_id, provider_account_id, status as outcome')
            ->selectRaw('count(*) as requests')
            ->selectRaw("sum(case when status in ('failed','cancelled') then 1 else 0 end) as failures")
            ->selectRaw('coalesce(sum(prompt_tokens), 0) as input_tokens')
            ->selectRaw('coalesce(sum(completion_tokens), 0) as output_tokens')
            ->selectRaw('coalesce(sum(cached_input_tokens), 0) as cached_input_tokens')
            ->selectRaw('coalesce(sum(total_tokens), 0) as total_tokens')
            ->selectRaw('coalesce(sum(cost_microunits), 0) as cost_microunits')
            ->selectRaw('max(cost_currency) as cost_currency')
            ->selectRaw('coalesce(sum(total_latency_ms), 0) as latency_ms_sum')
            ->selectRaw('coalesce(max(total_latency_ms), 0) as latency_ms_max')
            ->selectRaw('coalesce(sum(time_to_first_token_ms), 0) as ttft_ms_sum')
            ->selectRaw('sum(case when time_to_first_token_ms is null then 0 else 1 end) as ttft_samples')
            ->whereNotNull('provider_account_id')
            ->whereBetween('started_at', [$from, $to])
            ->groupByRaw($this->bucketExpression())
            ->groupBy('application_id', 'public_model_alias_id', 'provider_account_id', 'status')
            ->get();

        DB::transaction(function () use ($rows, $from, $to): void {
            GatewayUsageRollup::query()
                ->whereBetween('bucket_date', [$from->toDateString(), $to->toDateString()])
                ->delete();

            $rows->chunk(500)->each(function ($chunk): void {
                GatewayUsageRollup::query()->insert(
                    $chunk->map(fn ($row): array => [
                        'bucket_date' => (string) $row->bucket_date,
                        'application_id' => (int) $row->application_id,
                        'public_model_alias_id' => (int) $row->public_model_alias_id,
                        'provider_account_id' => (int) $row->provider_account_id,
                        'outcome' => (string) $row->outcome,
                        'requests' => (int) $row->requests,
                        'failures' => (int) $row->failures,
                        'input_tokens' => (int) $row->input_tokens,
                        'output_tokens' => (int) $row->output_tokens,
                        'cached_input_tokens' => (int) $row->cached_input_tokens,
                        'total_tokens' => (int) $row->total_tokens,
                        'cost_microunits' => (int) $row->cost_microunits,
                        'cost_currency' => $row->cost_currency,
                        'latency_ms_sum' => (int) $row->latency_ms_sum,
                        'latency_ms_max' => (int) $row->latency_ms_max,
                        'ttft_ms_sum' => (int) $row->ttft_ms_sum,
                        'ttft_samples' => (int) $row->ttft_samples,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all(),
                );
            });
        });

        return $rows->count();
    }

    private function bucketExpression(): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "to_char(started_at at time zone 'UTC', 'YYYY-MM-DD')",
            'sqlite' => "strftime('%Y-%m-%d', started_at)",
            default => 'date(started_at)',
        };
    }
}

<?php

namespace App\Services;

use App\Contracts\MetricsStore;
use App\DTO\CallOutcome;
use App\DTO\RoutingDecision;
use App\Enums\GatewayOperation;

/**
 * Prometheus/OpenTelemetry-compatible gateway metrics.
 *
 * Only bounded, operator-meaningful dimensions are recorded: operation, public
 * model alias, provider type, outcome and error category. Application ids,
 * credential ids, request ids and any request/response content are deliberately
 * excluded so that scraping the endpoint can never disclose tenant activity
 * detail or prompt data.
 */
final readonly class TelemetryMetrics
{
    public function __construct(private MetricsStore $store) {}

    public function recordCall(
        RoutingDecision $decision,
        GatewayOperation $operation,
        CallOutcome $outcome,
        bool $streaming,
        ?int $totalLatencyMs,
    ): void {
        if (! config('telemetry.metrics.enabled')) {
            return;
        }

        $labels = [
            'operation' => $operation->value,
            'model' => $decision->modelId,
            'provider' => $decision->provider->type->value,
        ];

        $this->store->increment('gateway_requests_total', [
            ...$labels,
            'outcome' => $outcome->status->value,
            'streaming' => $streaming ? 'true' : 'false',
        ]);

        if ($outcome->promptTokens > 0) {
            $this->store->increment('gateway_tokens_total', [...$labels, 'kind' => 'input'], $outcome->promptTokens);
        }
        if ($outcome->completionTokens > 0) {
            $this->store->increment('gateway_tokens_total', [...$labels, 'kind' => 'output'], $outcome->completionTokens);
        }
        if ($outcome->cachedInputTokens > 0) {
            $this->store->increment('gateway_tokens_total', [...$labels, 'kind' => 'cached'], $outcome->cachedInputTokens);
        }

        if ($outcome->errorCategory !== null) {
            $this->store->increment('gateway_failures_total', [
                ...$labels,
                'category' => $outcome->errorCategory,
            ]);
        }

        if ($totalLatencyMs !== null) {
            $this->observe(
                'gateway_latency_ms',
                $labels,
                $totalLatencyMs,
                (array) config('telemetry.metrics.latency_buckets'),
            );
        }
        if ($outcome->timeToFirstTokenMs !== null) {
            $this->observe(
                'gateway_time_to_first_token_ms',
                $labels,
                $outcome->timeToFirstTokenMs,
                (array) config('telemetry.metrics.ttft_buckets'),
            );
        }
    }

    public function recordCost(RoutingDecision $decision, int $microunits): void
    {
        if (! config('telemetry.metrics.enabled') || $microunits <= 0) {
            return;
        }
        $this->store->increment('gateway_cost_microunits_total', [
            'model' => $decision->modelId,
            'provider' => $decision->provider->type->value,
            'currency' => $decision->pricing->currency,
        ], $microunits);
    }

    public function recordBudgetRejection(string $code, string $model): void
    {
        if (! config('telemetry.metrics.enabled')) {
            return;
        }
        $this->store->increment('gateway_budget_rejections_total', [
            'code' => $code,
            'model' => $model,
        ]);
    }

    public function recordSaturationRejection(string $reason): void
    {
        if (! config('telemetry.metrics.enabled')) {
            return;
        }
        $this->store->increment('gateway_saturation_rejections_total', ['reason' => $reason]);
    }

    public function recordContentCaptureSkipped(string $reason): void
    {
        if (! config('telemetry.metrics.enabled')) {
            return;
        }
        $this->store->increment('gateway_content_capture_skipped_total', ['reason' => $reason]);
    }

    /**
     * @param  array<string, string>  $labels
     * @param  list<int|float>  $buckets
     */
    private function observe(string $name, array $labels, int $value, array $buckets): void
    {
        foreach ($buckets as $bucket) {
            if ($value <= $bucket) {
                $this->store->increment($name.'_bucket', [...$labels, 'le' => (string) $bucket]);
            }
        }
        $this->store->increment($name.'_bucket', [...$labels, 'le' => '+Inf']);
        $this->store->increment($name.'_sum', $labels, $value);
        $this->store->increment($name.'_count', $labels);
    }
}

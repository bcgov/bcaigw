<?php

namespace App\Services;

use App\Contracts\MetricsStore;
use App\Enums\ControlPlaneStatus;
use Illuminate\Support\Facades\DB;

/**
 * Renders the Prometheus text exposition format (v0.0.4), which is also the
 * format scraped by the OpenTelemetry Collector's prometheus receiver.
 */
final readonly class PrometheusExporter
{
    private const HELP = [
        'gateway_requests_total' => ['counter', 'Gateway calls by operation, model, provider and outcome.'],
        'gateway_tokens_total' => ['counter', 'Tokens processed by kind (input, output, cached).'],
        'gateway_cost_microunits_total' => ['counter', 'Exact accounted cost in currency micro-units.'],
        'gateway_failures_total' => ['counter', 'Failed gateway calls by sanitized error category.'],
        'gateway_budget_rejections_total' => ['counter', 'Requests rejected by quota or budget enforcement.'],
        'gateway_saturation_rejections_total' => ['counter', 'Requests rejected because the gateway was saturated.'],
        'gateway_content_capture_skipped_total' => ['counter', 'Retained-content captures skipped, by reason.'],
        'gateway_latency_ms' => ['histogram', 'End-to-end gateway latency in milliseconds.'],
        'gateway_time_to_first_token_ms' => ['histogram', 'Streaming time to first token in milliseconds.'],
        'upstream_target_status' => ['gauge', 'Configured upstream targets by provider type and health status.'],
    ];

    public function __construct(private MetricsStore $store) {}

    public function render(): string
    {
        $namespace = (string) config('telemetry.metrics.namespace');
        $grouped = [];
        foreach ($this->store->all() as $field => $value) {
            [$name, $labels] = MetricSeries::parse($field);
            $grouped[$this->family($name)][] = [$name, $labels, $value];
        }
        foreach ($this->upstreamHealth() as $series) {
            $grouped['upstream_target_status'][] = $series;
        }

        $lines = [];
        ksort($grouped);
        foreach ($grouped as $family => $series) {
            [$type, $help] = self::HELP[$family] ?? ['untyped', 'Gateway metric.'];
            $lines[] = "# HELP {$namespace}_{$family} {$help}";
            $lines[] = "# TYPE {$namespace}_{$family} {$type}";
            usort($series, static fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            foreach ($series as [$name, $labels, $value]) {
                $lines[] = sprintf(
                    '%s_%s%s %s',
                    $namespace,
                    $name,
                    $this->labels($labels),
                    $this->number($value),
                );
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function family(string $name): string
    {
        foreach (['_bucket', '_sum', '_count'] as $suffix) {
            if (str_ends_with($name, $suffix) && isset(self::HELP[substr($name, 0, -strlen($suffix))])) {
                return substr($name, 0, -strlen($suffix));
            }
        }

        return $name;
    }

    /**
     * @return list<array{0: string, 1: array<string, string>, 2: float}>
     */
    private function upstreamHealth(): array
    {
        // Queried through the query builder rather than the model so enum casts
        // are not applied to the aliased aggregate columns.
        $rows = DB::table('upstream_targets')
            ->selectRaw('provider_accounts.type as provider_type, upstream_targets.status as admin_status,'
                .' upstream_targets.health_status as health, count(*) as total')
            ->join('provider_accounts', 'provider_accounts.id', '=', 'upstream_targets.provider_account_id')
            ->groupBy('provider_accounts.type', 'upstream_targets.status', 'upstream_targets.health_status')
            ->get();

        return $rows->map(fn ($row): array => ['upstream_target_status', [
            'provider' => MetricSeries::sanitize((string) $row->provider_type),
            'admin_status' => MetricSeries::sanitize((string) ($row->admin_status ?? ControlPlaneStatus::Draft->value)),
            'health' => MetricSeries::sanitize((string) ($row->health ?? 'unknown')),
        ], (float) $row->total])->all();
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        $pairs = [];
        foreach ($labels as $key => $value) {
            $pairs[] = sprintf('%s="%s"', $key, addcslashes($value, "\"\\\n"));
        }

        return '{'.implode(',', $pairs).'}';
    }

    private function number(float $value): string
    {
        return $value == (int) $value && abs($value) < PHP_INT_MAX
            ? (string) (int) $value
            : rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}

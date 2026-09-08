<?php

namespace App\Services;

use App\Contracts\MetricsStore;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Redis-backed counter store. Metrics are strictly best-effort telemetry: a
 * Redis outage must never fail a gateway request, so every operation is
 * swallowed and the request continues.
 */
final class RedisMetricsStore implements MetricsStore
{
    private const HASH = 'bcaigw:metrics:counters';

    public function increment(string $name, array $labels, float $value = 1.0): void
    {
        $field = MetricSeries::field($name, $labels);

        try {
            $connection = Redis::connection();
            if ((int) $connection->hlen(self::HASH) >= (int) config('telemetry.metrics.max_series')
                && ! (bool) $connection->hexists(self::HASH, $field)) {
                return;
            }
            $connection->hincrbyfloat(self::HASH, $field, $value);
        } catch (Throwable) {
            // Metrics are non-critical; never surface storage failures.
        }
    }

    public function all(): array
    {
        try {
            /** @var array<string, string> $raw */
            $raw = Redis::connection()->hgetall(self::HASH);
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn (string $value): float => (float) $value, $raw);
    }

    public function flush(): void
    {
        try {
            Redis::connection()->del(self::HASH);
        } catch (Throwable) {
            // Ignored.
        }
    }
}

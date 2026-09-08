<?php

namespace App\Services;

use App\Contracts\MetricsStore;

final class InMemoryMetricsStore implements MetricsStore
{
    /** @var array<string, float> */
    private array $counters = [];

    public function increment(string $name, array $labels, float $value = 1.0): void
    {
        $field = MetricSeries::field($name, $labels);
        if (! array_key_exists($field, $this->counters)
            && count($this->counters) >= (int) config('telemetry.metrics.max_series')) {
            return;
        }
        $this->counters[$field] = ($this->counters[$field] ?? 0.0) + $value;
    }

    public function all(): array
    {
        return $this->counters;
    }

    public function flush(): void
    {
        $this->counters = [];
    }
}

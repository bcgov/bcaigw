<?php

namespace App\Services;

/**
 * Encodes and sanitises metric series identifiers.
 *
 * Label values are aggressively normalised so that unbounded identifiers
 * (request ids, ULID/UUID public ids, free-text errors, prompt content) can
 * never reach the metrics backend and explode cardinality.
 */
final class MetricSeries
{
    /**
     * @param  array<string, string>  $labels
     */
    public static function field(string $name, array $labels): string
    {
        ksort($labels);
        $pairs = [];
        foreach ($labels as $key => $value) {
            if (preg_match('/^[a-z_][a-z0-9_]{0,32}$/', $key) !== 1) {
                continue;
            }
            $pairs[] = $key.'='.self::sanitize($value);
        }

        return $name.'|'.implode(',', $pairs);
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    public static function parse(string $field): array
    {
        [$name, $encoded] = array_pad(explode('|', $field, 2), 2, '');
        $labels = [];
        foreach (array_filter(explode(',', (string) $encoded)) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($key !== '') {
                $labels[$key] = (string) $value;
            }
        }

        return [$name, $labels];
    }

    public static function sanitize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'unknown';
        }
        // UUIDs, ULIDs and other opaque identifiers are unbounded in cardinality.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) === 1
            || preg_match('/^[0-9a-f]{32,}$/i', $value) === 1) {
            return 'redacted';
        }
        $value = preg_replace('/[^A-Za-z0-9._\/:-]+/', '_', $value) ?? 'unknown';
        $max = (int) config('telemetry.metrics.max_label_length', 64);

        return $value === '' ? 'unknown' : substr($value, 0, $max);
    }
}

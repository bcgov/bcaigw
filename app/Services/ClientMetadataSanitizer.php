<?php

namespace App\Services;

/**
 * Strict allowlisting of caller-supplied metadata before it is persisted as
 * telemetry.
 *
 * Callers can attach arbitrary key/value pairs to a request. Those values are
 * untrusted and could contain prompt fragments, credentials or unbounded text,
 * so only short scalar values under conservative key/value/entry limits are
 * kept, and keys are restricted to a safe character class.
 */
final class ClientMetadataSanitizer
{
    private const MAX_KEY_LENGTH = 40;

    private const MAX_VALUE_LENGTH = 200;

    /**
     * @return array<string, string|int|bool>
     */
    public static function sanitize(mixed $metadata): array
    {
        if (! is_array($metadata) || $metadata === []) {
            return [];
        }

        $maxEntries = max(0, (int) config('gateway-data.max_metadata_entries', 16));
        $sanitized = [];
        foreach ($metadata as $key => $value) {
            if (count($sanitized) >= $maxEntries) {
                break;
            }
            if (! is_string($key)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,'.(self::MAX_KEY_LENGTH - 1).'}$/', $key) !== 1) {
                continue;
            }
            if (is_bool($value) || is_int($value)) {
                $sanitized[$key] = $value;

                continue;
            }
            if (! is_string($value) || $value === '') {
                continue;
            }
            $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
            if ($clean === '') {
                continue;
            }
            $sanitized[$key] = mb_substr($clean, 0, self::MAX_VALUE_LENGTH);
        }

        return $sanitized;
    }
}

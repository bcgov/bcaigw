<?php

namespace App\Services;

/**
 * Maps sanitized gateway error codes onto a small, bounded set of categories.
 *
 * Categories are safe for metric labels and dashboards: they never contain
 * upstream messages, endpoints, provider payloads or free text.
 */
final class ErrorCategory
{
    public const CLIENT = 'client_error';

    public const AUTHORIZATION = 'authorization';

    public const QUOTA = 'quota';

    public const SATURATION = 'saturation';

    public const UPSTREAM = 'upstream';

    public const TIMEOUT = 'timeout';

    public const CANCELLED = 'cancelled';

    public const INTERNAL = 'internal';

    public static function of(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return match ($code) {
            'validation_error',
            'unsupported_parameter',
            'invalid_idempotency_key',
            'idempotency_not_supported',
            'idempotency_conflict',
            'payload_too_large',
            'streaming_not_supported',
            'model_not_found' => self::CLIENT,
            'invalid_api_key',
            'insufficient_scope',
            'capability_not_granted',
            'invalid_client' => self::AUTHORIZATION,
            'request_rate_limit',
            'token_rate_limit',
            'token_budget_exceeded',
            'cost_budget_exceeded',
            'quota_accounting_unavailable',
            'budget_currency_mismatch' => self::QUOTA,
            'concurrency_limit_exceeded' => self::SATURATION,
            'upstream_error',
            'model_temporarily_unavailable',
            'provider_credentials_unavailable',
            'provider_adapter_unavailable',
            'secure_transport_unavailable',
            'upstream_connection_failed',
            'upstream_connection_lost',
            'invalid_upstream_response',
            'invalid_upstream_stream',
            'unsupported_content' => self::UPSTREAM,
            'upstream_timeout',
            'first_byte_timeout',
            'idle_timeout',
            'total_timeout' => self::TIMEOUT,
            'client_cancelled' => self::CANCELLED,
            default => self::INTERNAL,
        };
    }
}

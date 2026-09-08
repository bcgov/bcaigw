<?php

return [
    /*
     | Content retention is controlled per application through
     | `prompt_response_retention_enabled`, which defaults to enabled. This
     | switch is an operator-level kill switch that can only ever reduce what is
     | captured; it never enables capture for an application that opted out.
     */
    'content' => [
        'capture_enabled' => filter_var(
            env('TELEMETRY_CONTENT_CAPTURE_ENABLED', true),
            FILTER_VALIDATE_BOOL,
        ),
        // Global ceiling. An application ceiling may lower this but never raise it.
        'max_request_bytes' => (int) env('TELEMETRY_MAX_REQUEST_BYTES', 262_144),
        'max_response_bytes' => (int) env('TELEMETRY_MAX_RESPONSE_BYTES', 262_144),
        'max_stream_chunks' => (int) env('TELEMETRY_MAX_STREAM_CHUNKS', 512),
        'truncation_marker' => '[bcaigw:truncated]',
    ],

    /*
     | Envelope encryption keyring. Every content record is sealed with a
     | per-record AES-256-GCM data key; the data key is wrapped with the active
     | master key. `APP_KEY` is explicitly rejected as master key material so
     | that application key rotation never destroys retained content and so that
     | content keys can live in a platform secret store.
     |
     | Supply either an inline base64 keyring or a platform secret reference
     | (`env:NAME` / `file:name`) that resolves to the same JSON structure:
     |   {"kid-2026-09":"<base64 32 byte key>"}
     */
    'keys' => [
        'active' => env('BCAIGW_CONTENT_KEY_ID'),
        'keyring' => env('BCAIGW_CONTENT_KEYRING'),
        'reference' => env('BCAIGW_CONTENT_KEYRING_REFERENCE'),
        'algorithm' => 'aes-256-gcm',
    ],

    'metrics' => [
        'enabled' => filter_var(env('TELEMETRY_METRICS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'namespace' => 'bcaigw',
        'scrape_token' => env('TELEMETRY_METRICS_TOKEN'),
        'max_series' => (int) env('TELEMETRY_METRICS_MAX_SERIES', 2_000),
        'max_label_length' => 64,
        'latency_buckets' => [50, 100, 250, 500, 1_000, 2_500, 5_000, 10_000, 30_000, 60_000],
        'ttft_buckets' => [50, 100, 250, 500, 1_000, 2_500, 5_000, 10_000],
    ],

    'dashboard' => [
        'page_size' => (int) env('TELEMETRY_PAGE_SIZE', 25),
        'export_max_rows' => (int) env('TELEMETRY_EXPORT_MAX_ROWS', 10_000),
    ],

    'rollup' => [
        'lookback_days' => (int) env('TELEMETRY_ROLLUP_LOOKBACK_DAYS', 3),
        'summary_max_days' => (int) env('TELEMETRY_SUMMARY_MAX_DAYS', 92),
    ],

    'deletion' => [
        'max_selected' => (int) env('TELEMETRY_DELETION_MAX_SELECTED', 500),
        'max_range_days' => (int) env('TELEMETRY_DELETION_MAX_RANGE_DAYS', 366),
    ],
];

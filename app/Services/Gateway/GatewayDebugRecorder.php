<?php

namespace App\Services\Gateway;

use App\Models\Application;
use App\Models\ApplicationEnvironment;
use App\Models\GatewayDebugLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Captures full gateway request/response detail for environments with
 * debug_enabled. Inline base64 attachments and large vectors are replaced by
 * summaries so logs stay readable and bounded. Never fails the gateway call.
 */
class GatewayDebugRecorder
{
    public function enabledFor(?ApplicationEnvironment $environment): bool
    {
        return $environment !== null && (bool) $environment->debug_enabled;
    }

    /**
     * @param  array{request_id: string, operation: string, requested_model: ?string, upstream_model?: ?string, request: array<string, mixed>, http_status: int, response: mixed, upstream_status?: ?int, error_code?: ?string, latency_ms?: ?int, prompt_tokens?: ?int, completion_tokens?: ?int}  $entry
     */
    public function record(Application $application, ApplicationEnvironment $environment, array $entry): void
    {
        if (! $this->enabledFor($environment)) {
            return;
        }

        try {
            $attachments = [];
            $request = $this->sanitize($entry['request'], $attachments);
            [$request, $requestTruncated] = $this->bound($request);
            [$response, $responseTruncated] = $this->bound($this->sanitize($entry['response']));

            GatewayDebugLog::create([
                'request_id' => $entry['request_id'],
                'application_id' => $application->id,
                'environment' => $environment->environment,
                'operation' => $entry['operation'],
                'requested_model' => $entry['requested_model'],
                'upstream_model' => $entry['upstream_model'] ?? null,
                'http_status' => $entry['http_status'],
                'upstream_status' => $entry['upstream_status'] ?? null,
                'error_code' => $entry['error_code'] ?? null,
                'has_image' => collect($attachments)->contains('type', 'image'),
                'has_file' => collect($attachments)->contains('type', 'file'),
                'attachments' => $attachments ?: null,
                'request_payload' => $request,
                'response_payload' => $response,
                'request_truncated' => $requestTruncated,
                'response_truncated' => $responseTruncated,
                'latency_ms' => $entry['latency_ms'] ?? null,
                'prompt_tokens' => $entry['prompt_tokens'] ?? null,
                'completion_tokens' => $entry['completion_tokens'] ?? null,
            ]);

            $this->pruneOccasionally();
        } catch (Throwable $e) {
            Log::warning('Failed to record gateway debug log.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Replace inline data URLs, base64 images and embedding vectors with
     * summaries, collecting attachment metadata along the way.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     */
    private function sanitize(mixed $value, array &$attachments = []): mixed
    {
        if (! is_array($value)) {
            return is_string($value) ? $this->summarizeDataUrl($value) : $value;
        }

        $type = $value['type'] ?? null;

        if ($type === 'image_url' && isset($value['image_url'])) {
            $url = is_array($value['image_url']) ? (string) ($value['image_url']['url'] ?? '') : (string) $value['image_url'];
            $attachments[] = ['type' => 'image'] + $this->describeUrl($url);

            return ['type' => 'image_url', 'image_url' => ['url' => $this->summarizeDataUrl($url)]];
        }

        if (($type === 'file' || $type === 'input_file') && isset($value['file']) && is_array($value['file'])) {
            $file = $value['file'];
            $data = (string) ($file['file_data'] ?? '');
            $attachments[] = ['type' => 'file', 'filename' => $file['filename'] ?? null] + $this->describeUrl($data);

            return ['type' => $type, 'file' => array_filter([
                'filename' => $file['filename'] ?? null,
                'file_id' => $file['file_id'] ?? null,
                'file_data' => $data !== '' ? $this->summarizeDataUrl($data) : null,
            ], fn ($v) => $v !== null)];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if ($key === 'b64_json' && is_string($item)) {
                $out[$key] = '[base64 image omitted, ~'.$this->decodedBytes($item).' bytes]';
            } elseif ($key === 'embedding' && is_array($item)) {
                $out[$key] = '['.count($item).'-dimension vector omitted]';
            } else {
                $out[$key] = $this->sanitize($item, $attachments);
            }
        }

        return $out;
    }

    /**
     * @return array{source: string, mime?: ?string, bytes?: int, url?: string}
     */
    private function describeUrl(string $url): array
    {
        if (preg_match('#^data:([^;,]+)?(;base64)?,(.*)$#s', $url, $m)) {
            return ['source' => 'inline', 'mime' => $m[1] ?: null, 'bytes' => $this->decodedBytes($m[3])];
        }

        return ['source' => 'url', 'url' => mb_substr($url, 0, 500)];
    }

    private function summarizeDataUrl(string $value): string
    {
        if (! str_starts_with($value, 'data:') || ! preg_match('#^data:([^;,]+)?(;base64)?,(.*)$#s', $value, $m)) {
            return $value;
        }

        return '[data:'.($m[1] ?: 'unknown').' omitted, ~'.$this->decodedBytes($m[3]).' bytes]';
    }

    private function decodedBytes(string $base64): int
    {
        return (int) floor(strlen(rtrim($base64, '=')) * 3 / 4);
    }

    /**
     * @return array{0: mixed, 1: bool}
     */
    private function bound(mixed $payload): array
    {
        $max = max(1024, (int) config('gateway.debug.max_payload_bytes', 65536));
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (strlen($json) <= $max) {
            return [is_array($payload) ? $payload : ['value' => $payload], false];
        }

        return [['truncated_preview' => mb_strcut($json, 0, $max)], true];
    }

    private function pruneOccasionally(): void
    {
        // Lottery-based expiry so retention holds without a scheduler.
        if (random_int(1, 50) !== 1) {
            return;
        }

        $days = max(1, (int) config('gateway.debug.retention_days', 14));

        GatewayDebugLog::query()->where('created_at', '<', Carbon::now()->subDays($days))->delete();
    }
}

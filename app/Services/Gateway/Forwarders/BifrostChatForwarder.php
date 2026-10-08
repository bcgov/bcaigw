<?php

namespace App\Services\Gateway\Forwarders;

use App\Models\UpstreamTarget;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Forwards to a self-hosted Bifrost gateway (OSS, Apache-2.0). Bifrost exposes an
 * OpenAI-compatible /v1/chat/completions endpoint and owns the provider keys,
 * routing, fallback and load balancing. BCAIGW stays the governance front door
 * and delegates the actual provider call to Bifrost.
 *
 * The target's provider_model_identifier holds Bifrost's "provider/model" string
 * (e.g. "openai/gpt-4o-mini", "anthropic/claude-3-5-sonnet", "bedrock/...").
 * base_url may be left empty on the target to fall back to services.bifrost.base_url.
 */
class BifrostChatForwarder extends AbstractChatForwarder
{
    /**
     * Maps a BCAIGW provider account type to the Bifrost provider prefix used in
     * the "provider/model" model string. Types not listed here are assumed to
     * already be valid Bifrost provider names (openai, groq, mistral, ...).
     *
     * @var array<string, string>
     */
    private const PROVIDER_PREFIX = [
        'azure-openai' => 'azure',
        'azure_openai' => 'azure',
        'openai-compatible' => 'openai',
        'openai_compatible' => 'openai',
        'google' => 'gemini',
        'vertex-ai' => 'vertex',
        'google-vertex' => 'vertex',
    ];

    public function supports(string $providerType): bool
    {
        return $providerType === 'bifrost';
    }

    /**
     * Applies Bifrost auth. Bifrost v2 gates the dashboard, admin API and (unless
     * disabled on the gateway) inference behind dashboard auth, which accepts HTTP
     * Basic with the admin user/pass. Falls back to a bearer key, else no auth (OSS v1).
     */
    private function applyAuth(PendingRequest $request, ?UpstreamTarget $target = null): PendingRequest
    {
        // Pin the call to the provider key (account) the target was registered under.
        $keyId = (string) ($target?->bifrost_key_id ?? '');
        if ($keyId !== '') {
            $request = $request->withHeaders(['x-bf-api-key-id' => $keyId]);
        }

        $username = (string) config('services.bifrost.admin_username');
        $password = (string) config('services.bifrost.admin_password');

        if ($username !== '' && $password !== '') {
            return $request->withBasicAuth($username, $password);
        }

        $apiKey = (string) config('services.bifrost.api_key');

        return $apiKey !== '' ? $request->withToken($apiKey) : $request;
    }

    /**
     * Builds the Bifrost "provider/model" identifier for a target. Bifrost-typed
     * targets already store the full string; native targets are prefixed with the
     * Bifrost provider name derived from the account type.
     */
    private function resolveModelId(UpstreamTarget $target): string
    {
        $model = (string) $target->provider_model_identifier;
        $type = (string) $target->provider?->type;

        if ($type === 'bifrost' || str_contains($model, '/')) {
            return $model;
        }

        $prefix = self::PROVIDER_PREFIX[$type] ?? $type;

        return $prefix !== '' ? $prefix.'/'.$model : $model;
    }

    /**
     * Builds a human-usable error message from an upstream error body. Providers
     * (notably Bedrock via Bifrost) sometimes return an error with an empty
     * message (e.g. a SerializationException from a malformed data URL), so fall
     * back to the error type and status rather than surfacing a blank string.
     *
     * @param  array<string, mixed>  $data
     */
    private function extractError(array $data, ?int $status): string
    {
        $message = trim((string) data_get($data, 'error.message', ''));

        if ($message !== '') {
            return $message;
        }

        $type = trim((string) (data_get($data, 'error.type') ?? data_get($data, 'type') ?? ''));
        $status = $status !== null ? " (HTTP {$status})" : '';

        if ($type !== '') {
            return "The upstream model returned {$type}{$status}. Check the request payload — a common cause is a malformed image_url or file data URL (e.g. a doubled 'data:...;base64,' prefix).";
        }

        return "The upstream model returned an error{$status}.";
    }

    /**
     * Normalizes the assistant message content to a string. Some providers
     * (notably Bedrock via Bifrost) return content as an array of blocks
     * (e.g. [['type' => 'text', 'text' => '...']]) rather than a plain string.
     */
    private function extractText(mixed $content): ?string
    {
        if ($content === null || is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            $parts = array_map(
                fn ($block) => is_array($block) ? (string) ($block['text'] ?? '') : (string) $block,
                $content,
            );

            return implode('', array_filter($parts, fn ($part) => $part !== ''));
        }

        return (string) $content;
    }

    /**
     * Normalizes outgoing request content: a plain string passes through, while
     * an array is treated as OpenAI content blocks (text/image_url/file) so
     * vision and document inputs reach the model unchanged.
     */
    private function normalizeContent(mixed $content): mixed
    {
        if (is_array($content)) {
            return array_values($content);
        }

        return (string) $content;
    }

    /**
     * @param  array<int, array{role: string, content: string|array<int, mixed>}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function forward(UpstreamTarget $target, array $messages, array $options = []): array
    {
        // Only a Bifrost-typed target may override the base URL; native targets
        // carry their provider's own URL (e.g. AWS Bedrock), which must never be
        // used here now that they are routed through the shared Bifrost engine.
        $override = $target->provider?->type === 'bifrost' ? (string) $target->base_url : '';
        $base = $override !== '' ? $override : (string) config('services.bifrost.base_url');

        if ($base === '') {
            return $this->failure(null, 0, 'The gateway is missing the Bifrost base URL.');
        }

        // Accept a base URL with or without a trailing /v1 and normalize to one endpoint.
        $base = preg_replace('#/v1/?$#', '', rtrim($base, '/'));
        $endpoint = $base.'/v1/chat/completions';

        $body = array_filter([
            'model' => $this->resolveModelId($target),
            'messages' => array_map(
                // Content may be a plain string or an array of OpenAI content
                // blocks (text/image_url/file) for vision and document inputs.
                fn (array $m) => [
                    'role' => $m['role'] ?? 'user',
                    'content' => $this->normalizeContent($m['content'] ?? ''),
                ],
                $messages,
            ),
            'max_tokens' => (int) ($options['max_tokens'] ?? min(4096, $target->max_output_tokens ?: 4096)),
            'temperature' => $options['temperature'] ?? null,
            'top_p' => $options['top_p'] ?? null,
            // Reasoning models accept an effort hint (low/medium/high).
            'reasoning_effort' => $options['reasoning_effort'] ?? null,
        ], fn ($value) => $value !== null);

        $startedAt = microtime(true);

        try {
            $request = $this->applyAuth(Http::acceptJson()->timeout($target->timeout_seconds ?: 60), $target);

            $response = $request->post($endpoint, $body);
            $latencyMs = $this->elapsed($startedAt);
            $data = $response->json() ?? [];

            if (! $response->successful()) {
                return $this->failure($response->status(), $latencyMs, $this->extractError($data, $response->status()));
            }

            $usage = (array) data_get($data, 'usage', []);

            return $this->success(
                $response->status(),
                $latencyMs,
                $this->extractText(data_get($data, 'choices.0.message.content')),
                (int) ($usage['prompt_tokens'] ?? 0),
                (int) ($usage['completion_tokens'] ?? 0),
                isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
                $response->header('x-bifrost-request-id') ?: ($response->header('x-request-id') ?: data_get($data, 'id')),
            );
        } catch (Throwable $e) {
            return $this->failure(null, $this->elapsed($startedAt), $e->getMessage());
        }
    }

    /**
     * Forwards an image-generation request to Bifrost's OpenAI-compatible
     * /v1/images/generations endpoint and normalizes the reply into a list of
     * generated images (base64 or URL).
     *
     * @param  array<string, mixed>  $options
     * @return array{ok: bool, http_status: ?int, latency_ms: int, images: array<int, array<string, string>>, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}, error: ?string, correlation_id: ?string}
     */
    public function forwardImage(UpstreamTarget $target, string $prompt, array $options = []): array
    {
        $override = $target->provider?->type === 'bifrost' ? (string) $target->base_url : '';
        $base = $override !== '' ? $override : (string) config('services.bifrost.base_url');

        if ($base === '') {
            return $this->imageFailure(null, 0, 'The gateway is missing the Bifrost base URL.');
        }

        $base = preg_replace('#/v1/?$#', '', rtrim($base, '/'));
        $endpoint = $base.'/v1/images/generations';

        $body = array_filter([
            'model' => $this->resolveModelId($target),
            'prompt' => $prompt,
            'n' => (int) ($options['n'] ?? 1),
            'size' => $options['size'] ?? null,
            'response_format' => $options['response_format'] ?? 'b64_json',
            'quality' => $options['quality'] ?? null,
            'style' => $options['style'] ?? null,
        ], fn ($value) => $value !== null);

        $startedAt = microtime(true);

        try {
            $request = $this->applyAuth(Http::acceptJson()->timeout($target->timeout_seconds ?: 120), $target);

            $response = $request->post($endpoint, $body);
            $latencyMs = $this->elapsed($startedAt);
            $data = $response->json() ?? [];

            if (! $response->successful()) {
                return $this->imageFailure($response->status(), $latencyMs, $this->extractError($data, $response->status()));
            }

            $images = [];
            foreach ((array) data_get($data, 'data', []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (isset($item['b64_json'])) {
                    $images[] = ['b64_json' => (string) $item['b64_json']];
                } elseif (isset($item['url'])) {
                    $images[] = ['url' => (string) $item['url']];
                }
            }

            if ($images === []) {
                return $this->imageFailure($response->status(), $latencyMs, 'The upstream model returned no images.');
            }

            $usage = (array) data_get($data, 'usage', []);

            return [
                'ok' => true,
                'http_status' => $response->status(),
                'latency_ms' => $latencyMs,
                'images' => $images,
                'usage' => [
                    'input_tokens' => (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
                    'output_tokens' => (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
                    'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
                ],
                'error' => null,
                'correlation_id' => $response->header('x-bifrost-request-id') ?: ($response->header('x-request-id') ?: data_get($data, 'id')),
            ];
        } catch (Throwable $e) {
            return $this->imageFailure(null, $this->elapsed($startedAt), $e->getMessage());
        }
    }

    /**
     * Forwards an embeddings request to Bifrost's OpenAI-compatible
     * /v1/embeddings endpoint and normalizes the reply into an indexed list of
     * vectors.
     *
     * @param  string|array<int, string>  $input
     * @param  array<string, mixed>  $options
     * @return array{ok: bool, http_status: ?int, latency_ms: int, embeddings: array<int, array{index: int, embedding: array<int, float>}>, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}, error: ?string, correlation_id: ?string}
     */
    public function forwardEmbeddings(UpstreamTarget $target, string|array $input, array $options = []): array
    {
        $endpoint = $this->resolveBase($target);

        if ($endpoint === null) {
            return $this->vectorFailure(null, 0, 'The gateway is missing the Bifrost base URL.');
        }

        $body = array_filter([
            'model' => $this->resolveModelId($target),
            'input' => $input,
            'encoding_format' => $options['encoding_format'] ?? null,
            'dimensions' => $options['dimensions'] ?? null,
        ], fn ($value) => $value !== null);

        $startedAt = microtime(true);

        try {
            $request = $this->applyAuth(Http::acceptJson()->timeout($target->timeout_seconds ?: 60), $target);

            $response = $request->post($endpoint.'/v1/embeddings', $body);
            $latencyMs = $this->elapsed($startedAt);
            $data = $response->json() ?? [];

            if (! $response->successful()) {
                return $this->vectorFailure($response->status(), $latencyMs, $this->extractError($data, $response->status()));
            }

            $embeddings = [];
            foreach ((array) data_get($data, 'data', []) as $item) {
                if (! is_array($item) || ! isset($item['embedding'])) {
                    continue;
                }

                $embeddings[] = [
                    'index' => (int) ($item['index'] ?? count($embeddings)),
                    'embedding' => array_values((array) $item['embedding']),
                ];
            }

            if ($embeddings === []) {
                return $this->vectorFailure($response->status(), $latencyMs, 'The upstream model returned no embeddings.');
            }

            $usage = (array) data_get($data, 'usage', []);

            return [
                'ok' => true,
                'http_status' => $response->status(),
                'latency_ms' => $latencyMs,
                'embeddings' => $embeddings,
                'usage' => [
                    'input_tokens' => (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
                    'output_tokens' => 0,
                    'total_tokens' => (int) ($usage['total_tokens'] ?? $usage['prompt_tokens'] ?? 0),
                ],
                'error' => null,
                'correlation_id' => $response->header('x-bifrost-request-id') ?: ($response->header('x-request-id') ?: data_get($data, 'id')),
            ];
        } catch (Throwable $e) {
            return $this->vectorFailure(null, $this->elapsed($startedAt), $e->getMessage());
        }
    }

    /**
     * Forwards a rerank request to Bifrost's /v1/rerank endpoint and normalizes
     * the reply into a scored, ordered list of document indexes. Reranking is a
     * distinct Bedrock API (bedrock-runtime Invoke), not chat/converse.
     *
     * @param  array<int, string>  $documents
     * @param  array<string, mixed>  $options
     * @return array{ok: bool, http_status: ?int, latency_ms: int, results: array<int, array{index: int, relevance_score: float}>, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}, error: ?string, correlation_id: ?string}
     */
    public function forwardRerank(UpstreamTarget $target, string $query, array $documents, array $options = []): array
    {
        $endpoint = $this->resolveBase($target);

        if ($endpoint === null) {
            return $this->rerankFailure(null, 0, 'The gateway is missing the Bifrost base URL.');
        }

        $body = array_filter([
            'model' => $this->resolveModelId($target),
            'query' => $query,
            'documents' => array_values($documents),
            'top_n' => $options['top_n'] ?? null,
        ], fn ($value) => $value !== null);

        $startedAt = microtime(true);

        try {
            $request = $this->applyAuth(Http::acceptJson()->timeout($target->timeout_seconds ?: 60), $target);

            $response = $request->post($endpoint.'/v1/rerank', $body);
            $latencyMs = $this->elapsed($startedAt);
            $data = $response->json() ?? [];

            if (! $response->successful()) {
                return $this->rerankFailure($response->status(), $latencyMs, $this->extractError($data, $response->status()));
            }

            $results = [];
            foreach ((array) data_get($data, 'results', []) as $item) {
                if (! is_array($item) || ! isset($item['index'])) {
                    continue;
                }

                $results[] = [
                    'index' => (int) $item['index'],
                    'relevance_score' => (float) ($item['relevance_score'] ?? 0.0),
                ];
            }

            $usage = (array) data_get($data, 'usage', []);

            return [
                'ok' => true,
                'http_status' => $response->status(),
                'latency_ms' => $latencyMs,
                'results' => $results,
                'usage' => [
                    'input_tokens' => (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
                    'output_tokens' => 0,
                    'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
                ],
                'error' => null,
                'correlation_id' => $response->header('x-bifrost-request-id') ?: ($response->header('x-request-id') ?: data_get($data, 'id')),
            ];
        } catch (Throwable $e) {
            return $this->rerankFailure(null, $this->elapsed($startedAt), $e->getMessage());
        }
    }

    /**
     * Resolves the normalized Bifrost base URL (without a trailing /v1) for a
     * target, or null when no base URL is configured.
     */
    private function resolveBase(UpstreamTarget $target): ?string
    {
        $override = $target->provider?->type === 'bifrost' ? (string) $target->base_url : '';
        $base = $override !== '' ? $override : (string) config('services.bifrost.base_url');

        if ($base === '') {
            return null;
        }

        return preg_replace('#/v1/?$#', '', rtrim($base, '/'));
    }

    /**
     * @return array{ok: false, http_status: ?int, latency_ms: int, images: array<int, array<string, string>>, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}, error: string, correlation_id: null}
     */
    private function imageFailure(?int $status, int $latencyMs, string $error): array
    {
        return [
            'ok' => false,
            'http_status' => $status,
            'latency_ms' => $latencyMs,
            'images' => [],
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'error' => $error,
            'correlation_id' => null,
        ];
    }

    /**
     * @return array{ok: false, http_status: ?int, latency_ms: int, embeddings: array<int, mixed>, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}, error: string, correlation_id: null}
     */
    private function vectorFailure(?int $status, int $latencyMs, string $error): array
    {
        return [
            'ok' => false,
            'http_status' => $status,
            'latency_ms' => $latencyMs,
            'embeddings' => [],
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'error' => $error,
            'correlation_id' => null,
        ];
    }

    /**
     * @return array{ok: false, http_status: ?int, latency_ms: int, results: array<int, mixed>, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}, error: string, correlation_id: null}
     */
    private function rerankFailure(?int $status, int $latencyMs, string $error): array
    {
        return [
            'ok' => false,
            'http_status' => $status,
            'latency_ms' => $latencyMs,
            'results' => [],
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'error' => $error,
            'correlation_id' => null,
        ];
    }
}

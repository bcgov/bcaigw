<?php

namespace App\Services\Gateway\Forwarders;

use App\Models\UpstreamTarget;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Forwards to Cohere's v2 Chat API (/v2/chat). Text is read from the first text
 * content block and token usage from the billed/token usage fields.
 */
class CohereChatForwarder extends AbstractChatForwarder
{
    public function supports(string $providerType): bool
    {
        return $providerType === 'cohere';
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function forward(UpstreamTarget $target, array $messages, array $options = []): array
    {
        $apiKey = (string) config('services.cohere.api_key');

        if ($apiKey === '') {
            return $this->failure(null, 0, 'The gateway is missing Cohere credentials.');
        }

        $endpoint = rtrim((string) $target->base_url, '/').'/v2/chat';

        $body = array_filter([
            'model' => $target->provider_model_identifier,
            'messages' => array_map(
                fn (array $m) => ['role' => $m['role'] ?? 'user', 'content' => (string) ($m['content'] ?? '')],
                $messages,
            ),
            'max_tokens' => (int) ($options['max_tokens'] ?? min(4096, $target->max_output_tokens ?: 4096)),
            'temperature' => $options['temperature'] ?? null,
            'p' => $options['top_p'] ?? null,
        ], fn ($value) => $value !== null);

        $startedAt = microtime(true);

        try {
            $response = Http::acceptJson()
                ->withToken($apiKey)
                ->timeout($target->timeout_seconds ?: 30)
                ->post($endpoint, $body);

            $latencyMs = $this->elapsed($startedAt);
            $data = $response->json() ?? [];

            if (! $response->successful()) {
                return $this->failure($response->status(), $latencyMs, data_get($data, 'message', 'The upstream model returned an error.'));
            }

            $parts = (array) data_get($data, 'message.content', []);
            $inputTokens = (int) (data_get($data, 'usage.tokens.input_tokens') ?? data_get($data, 'usage.billed_units.input_tokens') ?? 0);
            $outputTokens = (int) (data_get($data, 'usage.tokens.output_tokens') ?? data_get($data, 'usage.billed_units.output_tokens') ?? 0);

            return $this->success(
                $response->status(),
                $latencyMs,
                collect($parts)->pluck('text')->filter()->implode(''),
                $inputTokens,
                $outputTokens,
                null,
                data_get($data, 'id'),
            );
        } catch (Throwable $e) {
            return $this->failure(null, $this->elapsed($startedAt), $e->getMessage());
        }
    }
}

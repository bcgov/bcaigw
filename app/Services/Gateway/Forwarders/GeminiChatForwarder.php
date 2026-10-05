<?php

namespace App\Services\Gateway\Forwarders;

use App\Models\UpstreamTarget;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Forwards to Google's Gemini generateContent API. The API key is supplied as a
 * query parameter and the system prompt is sent via systemInstruction.
 */
class GeminiChatForwarder extends AbstractChatForwarder
{
    private const TYPES = ['google', 'gemini', 'vertex', 'vertex-ai', 'google-vertex'];

    public function supports(string $providerType): bool
    {
        return in_array($providerType, self::TYPES, true);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function forward(UpstreamTarget $target, array $messages, array $options = []): array
    {
        $type = (string) $target->provider?->type;
        $apiKey = (string) (config("services.{$type}.api_key") ?: config('services.google.api_key'));

        if ($apiKey === '') {
            return $this->failure(null, 0, 'The gateway is missing Google Gemini credentials.');
        }

        [$system, $turns] = $this->splitSystem($messages);
        $base = rtrim((string) $target->base_url, '/');
        $endpoint = $base.'/v1beta/models/'.$target->provider_model_identifier.':generateContent?key='.$apiKey;

        $generationConfig = array_filter([
            'maxOutputTokens' => (int) ($options['max_tokens'] ?? min(4096, $target->max_output_tokens ?: 4096)),
            'temperature' => $options['temperature'] ?? null,
            'topP' => $options['top_p'] ?? null,
        ], fn ($value) => $value !== null);

        $body = array_filter([
            'contents' => array_map(
                fn (array $m) => [
                    'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $m['content']]],
                ],
                $turns,
            ),
            'systemInstruction' => $system !== '' ? ['parts' => [['text' => $system]]] : null,
            'generationConfig' => $generationConfig,
        ], fn ($value) => $value !== null && $value !== []);

        $startedAt = microtime(true);

        try {
            $response = Http::acceptJson()
                ->timeout($target->timeout_seconds ?: 30)
                ->post($endpoint, $body);

            $latencyMs = $this->elapsed($startedAt);
            $data = $response->json() ?? [];

            if (! $response->successful()) {
                return $this->failure($response->status(), $latencyMs, data_get($data, 'error.message', 'The upstream model returned an error.'));
            }

            $parts = (array) data_get($data, 'candidates.0.content.parts', []);
            $meta = (array) data_get($data, 'usageMetadata', []);

            return $this->success(
                $response->status(),
                $latencyMs,
                collect($parts)->pluck('text')->filter()->implode(''),
                (int) ($meta['promptTokenCount'] ?? 0),
                (int) ($meta['candidatesTokenCount'] ?? 0),
                isset($meta['totalTokenCount']) ? (int) $meta['totalTokenCount'] : null,
                $response->header('x-request-id') ?: null,
            );
        } catch (Throwable $e) {
            return $this->failure(null, $this->elapsed($startedAt), $e->getMessage());
        }
    }
}

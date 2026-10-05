<?php

namespace App\Services\Gateway\Forwarders;

use App\Models\UpstreamTarget;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Forwards to Anthropic's native Messages API (/v1/messages). The system prompt
 * is carried out of band and text is read from the first content block.
 */
class AnthropicChatForwarder extends AbstractChatForwarder
{
    public function supports(string $providerType): bool
    {
        return $providerType === 'anthropic';
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function forward(UpstreamTarget $target, array $messages, array $options = []): array
    {
        $apiKey = (string) config('services.anthropic.api_key');

        if ($apiKey === '') {
            return $this->failure(null, 0, 'The gateway is missing Anthropic credentials.');
        }

        [$system, $turns] = $this->splitSystem($messages);
        $endpoint = rtrim((string) $target->base_url, '/').'/v1/messages';

        $body = array_filter([
            'model' => $target->provider_model_identifier,
            'max_tokens' => (int) ($options['max_tokens'] ?? min(4096, $target->max_output_tokens ?: 4096)),
            'system' => $system !== '' ? $system : null,
            'messages' => array_map(
                fn (array $m) => ['role' => $m['role'], 'content' => $m['content']],
                $turns,
            ),
            'temperature' => $options['temperature'] ?? null,
            'top_p' => $options['top_p'] ?? null,
        ], fn ($value) => $value !== null);

        $startedAt = microtime(true);

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => (string) (config('services.anthropic.version') ?: '2023-06-01'),
                ])
                ->timeout($target->timeout_seconds ?: 30)
                ->post($endpoint, $body);

            $latencyMs = $this->elapsed($startedAt);
            $data = $response->json() ?? [];

            if (! $response->successful()) {
                return $this->failure($response->status(), $latencyMs, data_get($data, 'error.message', 'The upstream model returned an error.'));
            }

            $usage = (array) data_get($data, 'usage', []);
            $inputTokens = (int) ($usage['input_tokens'] ?? 0);
            $outputTokens = (int) ($usage['output_tokens'] ?? 0);

            return $this->success(
                $response->status(),
                $latencyMs,
                $this->extractText((array) data_get($data, 'content', [])),
                $inputTokens,
                $outputTokens,
                null,
                data_get($data, 'id'),
            );
        } catch (Throwable $e) {
            return $this->failure(null, $this->elapsed($startedAt), $e->getMessage());
        }
    }

    /**
     * Concatenate the text of every text content block in the reply.
     *
     * @param  array<int, array<string, mixed>>  $content
     */
    private function extractText(array $content): string
    {
        return collect($content)
            ->filter(fn ($block) => ($block['type'] ?? null) === 'text')
            ->pluck('text')
            ->filter()
            ->implode('');
    }
}

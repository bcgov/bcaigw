<?php

namespace App\Services\Gateway\Forwarders;

use App\Models\UpstreamTarget;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Forwards to any provider that speaks the OpenAI /chat/completions schema
 * (OpenAI, Azure OpenAI, Groq, Mistral, Together, Fireworks, Ollama, vLLM, ...).
 * Credentials are resolved per provider type from config/services.{type}.api_key.
 */
class OpenAiCompatibleChatForwarder extends AbstractChatForwarder
{
    private const TYPES = [
        'openai', 'openai-compatible', 'openai_compatible', 'azure-openai', 'azure_openai',
        'groq', 'mistral', 'together', 'fireworks', 'ollama', 'vllm', 'deepseek', 'xai',
    ];

    /** Provider types that may run without an API key (local runtimes). */
    private const KEYLESS_TYPES = ['ollama', 'vllm'];

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
        $apiKey = (string) config("services.{$type}.api_key");
        $isAzure = str_starts_with($type, 'azure');

        if ($apiKey === '' && ! in_array($type, self::KEYLESS_TYPES, true)) {
            return $this->failure(null, 0, "The gateway is missing credentials for provider '{$type}'.");
        }

        $base = rtrim((string) $target->base_url, '/');
        $endpoint = $base.'/chat/completions';
        $apiVersion = (string) config("services.{$type}.api_version");
        if ($isAzure && $apiVersion !== '') {
            $endpoint .= '?api-version='.$apiVersion;
        }

        $body = array_filter([
            'model' => $target->provider_model_identifier,
            'messages' => array_map(
                fn (array $m) => ['role' => $m['role'] ?? 'user', 'content' => (string) ($m['content'] ?? '')],
                $messages,
            ),
            'max_tokens' => (int) ($options['max_tokens'] ?? min(4096, $target->max_output_tokens ?: 4096)),
            'temperature' => $options['temperature'] ?? null,
            'top_p' => $options['top_p'] ?? null,
        ], fn ($value) => $value !== null);

        $startedAt = microtime(true);

        try {
            $request = Http::acceptJson()->timeout($target->timeout_seconds ?: 30);
            // Azure OpenAI authenticates with an api-key header; everyone else uses a bearer token.
            $request = $isAzure ? $request->withHeaders(['api-key' => $apiKey]) : $request->withToken($apiKey);

            $response = $request->post($endpoint, $body);
            $latencyMs = $this->elapsed($startedAt);
            $data = $response->json() ?? [];

            if (! $response->successful()) {
                return $this->failure($response->status(), $latencyMs, data_get($data, 'error.message', 'The upstream model returned an error.'));
            }

            $usage = (array) data_get($data, 'usage', []);

            return $this->success(
                $response->status(),
                $latencyMs,
                data_get($data, 'choices.0.message.content'),
                (int) ($usage['prompt_tokens'] ?? 0),
                (int) ($usage['completion_tokens'] ?? 0),
                isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
                $response->header('x-request-id') ?: null,
            );
        } catch (Throwable $e) {
            return $this->failure(null, $this->elapsed($startedAt), $e->getMessage());
        }
    }
}

<?php

namespace App\Services\Gateway\Forwarders;

use App\Models\UpstreamTarget;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Forwards a normalized chat request to an AWS Bedrock Converse endpoint and
 * returns the reply text plus token usage.
 */
class BedrockChatForwarder extends AbstractChatForwarder
{
    public function supports(string $providerType): bool
    {
        return $providerType === 'bedrock';
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function forward(UpstreamTarget $target, array $messages, array $options = []): array
    {
        $apiKey = config('services.bedrock.api_key');

        if (empty($apiKey)) {
            return $this->failure(null, 0, 'The gateway is missing AWS Bedrock credentials.');
        }

        $endpoint = rtrim($target->base_url, '/').'/model/'.$target->provider_model_identifier.'/converse';
        $requestBody = $this->buildRequestBody($messages, $options, $target);

        $startedAt = microtime(true);

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($target->timeout_seconds ?: 30)
                ->post($endpoint, $requestBody);

            $latencyMs = $this->elapsed($startedAt);
            $body = $response->json() ?? [];

            if (! $response->successful()) {
                return $this->failure($response->status(), $latencyMs, data_get($body, 'message', 'The upstream model returned an error.'));
            }

            $usage = (array) data_get($body, 'usage', []);

            return $this->success(
                $response->status(),
                $latencyMs,
                data_get($body, 'output.message.content.0.text'),
                (int) ($usage['inputTokens'] ?? 0),
                (int) ($usage['outputTokens'] ?? 0),
                (int) ($usage['totalTokens'] ?? 0) ?: null,
                $response->header('x-amzn-RequestId') ?: null,
            );
        } catch (Throwable $e) {
            return $this->failure(null, $this->elapsed($startedAt), $e->getMessage());
        }
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildRequestBody(array $messages, array $options, UpstreamTarget $target): array
    {
        $system = [];
        $converse = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $text = (string) ($message['content'] ?? '');

            if ($role === 'system') {
                $system[] = ['text' => $text];

                continue;
            }

            $converse[] = [
                'role' => $role === 'assistant' ? 'assistant' : 'user',
                'content' => [['text' => $text]],
            ];
        }

        $inferenceConfig = ['maxTokens' => (int) ($options['max_tokens'] ?? min(4096, $target->max_output_tokens ?: 4096))];

        if (isset($options['temperature'])) {
            $inferenceConfig['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['top_p'])) {
            $inferenceConfig['topP'] = (float) $options['top_p'];
        }

        $requestBody = [
            'messages' => $converse,
            'inferenceConfig' => $inferenceConfig,
        ];

        if ($system !== []) {
            $requestBody['system'] = $system;
        }

        return $requestBody;
    }
}

<?php

namespace App\Services\Gateway\Forwarders;

use App\Models\UpstreamTarget;

/**
 * Resolves the correct ChatForwarder for an upstream target based on its
 * provider account type and delegates the forward call. This is the single
 * entry point the gateway uses so callers stay provider-agnostic.
 */
class ChatForwarderManager
{
    /** @var array<int, ChatForwarder> */
    private array $forwarders;

    public function __construct(
        private readonly BifrostChatForwarder $bifrost,
        BedrockChatForwarder $bedrock,
        OpenAiCompatibleChatForwarder $openAiCompatible,
        AnthropicChatForwarder $anthropic,
        GeminiChatForwarder $gemini,
        CohereChatForwarder $cohere,
    ) {
        // Native forwarders are kept only as a fallback for when Bifrost is not
        // configured; when a Bifrost base URL is present every target routes
        // through the shared Bifrost engine regardless of provider type.
        $this->forwarders = [$bedrock, $openAiCompatible, $anthropic, $gemini, $cohere];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function forward(UpstreamTarget $target, array $messages, array $options = []): array
    {
        // Bifrost is the single provider engine: when a base URL is configured,
        // all traffic (gateway calls and admin/user tests) goes through it.
        if (filled(config('services.bifrost.base_url'))) {
            return $this->bifrost->forward($target, $messages, $options);
        }

        $type = (string) $target->provider?->type;

        foreach ($this->forwarders as $forwarder) {
            if ($forwarder->supports($type)) {
                return $forwarder->forward($target, $messages, $options);
            }
        }

        return [
            'ok' => false,
            'http_status' => null,
            'latency_ms' => 0,
            'reply_text' => null,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'error' => "No forwarder is registered for provider type '{$type}'.",
            'correlation_id' => null,
        ];
    }

    /**
     * Forwards an image-generation request. Image generation is served only by
     * the Bifrost engine; without a Bifrost base URL the call is unsupported.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function forwardImage(UpstreamTarget $target, string $prompt, array $options = []): array
    {
        if (filled(config('services.bifrost.base_url'))) {
            return $this->bifrost->forwardImage($target, $prompt, $options);
        }

        return [
            'ok' => false,
            'http_status' => null,
            'latency_ms' => 0,
            'images' => [],
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'error' => 'Image generation requires the Bifrost engine, which is not configured.',
            'correlation_id' => null,
        ];
    }

    /**
     * Forwards an embeddings request. Served only by the Bifrost engine.
     *
     * @param  string|array<int, string>  $input
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function forwardEmbeddings(UpstreamTarget $target, string|array $input, array $options = []): array
    {
        if (filled(config('services.bifrost.base_url'))) {
            return $this->bifrost->forwardEmbeddings($target, $input, $options);
        }

        return [
            'ok' => false,
            'http_status' => null,
            'latency_ms' => 0,
            'embeddings' => [],
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'error' => 'Embeddings require the Bifrost engine, which is not configured.',
            'correlation_id' => null,
        ];
    }

    /**
     * Forwards a rerank request. Served only by the Bifrost engine.
     *
     * @param  array<int, string>  $documents
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function forwardRerank(UpstreamTarget $target, string $query, array $documents, array $options = []): array
    {
        if (filled(config('services.bifrost.base_url'))) {
            return $this->bifrost->forwardRerank($target, $query, $documents, $options);
        }

        return [
            'ok' => false,
            'http_status' => null,
            'latency_ms' => 0,
            'results' => [],
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'error' => 'Reranking requires the Bifrost engine, which is not configured.',
            'correlation_id' => null,
        ];
    }
}

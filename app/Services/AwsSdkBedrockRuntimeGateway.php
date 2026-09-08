<?php

namespace App\Services;

use App\Contracts\BedrockRuntimeGateway;
use App\Contracts\CanonicalGatewayRequest;
use App\Contracts\PlatformSecretResolver;
use App\DTO\AdapterResult;
use App\DTO\CanonicalMessage;
use App\DTO\ChatCompletionRequestData;
use App\DTO\EmbeddingRequestData;
use App\DTO\RoutingDecision;
use App\Exceptions\GatewayException;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Token\Token;
use Closure;
use Illuminate\Support\Str;

final class AwsSdkBedrockRuntimeGateway implements BedrockRuntimeGateway
{
    /**
     * @param  (Closure(RoutingDecision): BedrockRuntimeClient)|null  $clientFactory
     */
    public function __construct(
        private readonly ?Closure $clientFactory = null,
        private readonly ?PlatformSecretResolver $secrets = null,
    ) {}

    public function invoke(
        RoutingDecision $decision,
        CanonicalGatewayRequest $request,
    ): AdapterResult {
        if ($request instanceof EmbeddingRequestData) {
            return $this->embeddings($decision, $request);
        }

        $result = $this->client($decision)->converse($this->conversePayload($decision, $request));
        $data = $result->toArray();
        $usage = $data['usage'] ?? [];
        $prompt = (int) ($usage['inputTokens'] ?? 0);
        $completion = (int) ($usage['outputTokens'] ?? 0);
        $cachedInput = (int) ($usage['cacheReadInputTokens'] ?? 0);
        $text = collect($data['output']['message']['content'] ?? [])
            ->pluck('text')
            ->filter()
            ->implode('');
        $correlationId = $data['@metadata']['headers']['x-amzn-requestid'] ?? null;

        if ($request instanceof ChatCompletionRequestData) {
            $body = [
                'id' => 'chatcmpl_'.Str::uuid(),
                'object' => 'chat.completion',
                'created' => now()->timestamp,
                'model' => $decision->modelId,
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $text],
                    'finish_reason' => $this->finishReason($data['stopReason'] ?? null),
                ]],
                'usage' => [
                    'prompt_tokens' => $prompt,
                    'completion_tokens' => $completion,
                    'total_tokens' => $prompt + $completion,
                ],
            ];
        } else {
            $body = [
                'id' => 'resp_'.Str::uuid(),
                'object' => 'response',
                'created_at' => now()->timestamp,
                'status' => 'completed',
                'model' => $decision->modelId,
                'output' => [[
                    'type' => 'message',
                    'id' => 'msg_'.Str::uuid(),
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => $text]],
                ]],
                'usage' => [
                    'input_tokens' => $prompt,
                    'output_tokens' => $completion,
                    'total_tokens' => $prompt + $completion,
                ],
            ];
        }

        return new AdapterResult($body, $prompt, $completion, $correlationId, $cachedInput);
    }

    public function stream(
        RoutingDecision $decision,
        CanonicalGatewayRequest $request,
    ): iterable {
        if ($request instanceof EmbeddingRequestData) {
            throw new GatewayException(
                'invalid_request_error',
                'streaming_not_supported',
                400,
                'Embeddings do not support streaming.',
                'stream',
            );
        }

        $result = $this->client($decision)->converseStream($this->conversePayload($decision, $request));
        $id = $request instanceof ChatCompletionRequestData
            ? 'chatcmpl_'.Str::uuid()
            : 'resp_'.Str::uuid();
        foreach ($result['stream'] as $event) {
            $data = $event->toArray();
            if (isset($data['contentBlockDelta']['delta']['text'])) {
                $text = $data['contentBlockDelta']['delta']['text'];
                yield $request instanceof ChatCompletionRequestData
                    ? [
                        'id' => $id,
                        'object' => 'chat.completion.chunk',
                        'created' => now()->timestamp,
                        'model' => $decision->modelId,
                        'choices' => [[
                            'index' => 0,
                            'delta' => ['content' => $text],
                            'finish_reason' => null,
                        ]],
                    ]
                    : [
                        'type' => 'response.output_text.delta',
                        'response_id' => $id,
                        'delta' => $text,
                    ];
            }
            if (isset($data['messageStop'])) {
                if ($request instanceof ChatCompletionRequestData) {
                    yield [
                        'id' => $id,
                        'object' => 'chat.completion.chunk',
                        'created' => now()->timestamp,
                        'model' => $decision->modelId,
                        'choices' => [[
                            'index' => 0,
                            'delta' => (object) [],
                            'finish_reason' => $this->finishReason($data['messageStop']['stopReason'] ?? null),
                        ]],
                    ];
                }
            }
            if (isset($data['metadata']['usage'])) {
                $usage = $data['metadata']['usage'];
                $prompt = max(0, (int) ($usage['inputTokens'] ?? 0));
                $completion = max(0, (int) ($usage['outputTokens'] ?? 0));
                $cached = min($prompt, max(0, (int) ($usage['cacheReadInputTokens'] ?? 0)));
                yield $request instanceof ChatCompletionRequestData
                    ? [
                        'id' => $id,
                        'object' => 'chat.completion.chunk',
                        'created' => now()->timestamp,
                        'model' => $decision->modelId,
                        'choices' => [],
                        'usage' => [
                            'prompt_tokens' => $prompt,
                            'completion_tokens' => $completion,
                            'total_tokens' => $prompt + $completion,
                            'prompt_tokens_details' => ['cached_tokens' => $cached],
                        ],
                    ]
                    : [
                        'type' => 'response.completed',
                        'response' => [
                            'id' => $id,
                            'status' => 'completed',
                            'model' => $decision->modelId,
                            'usage' => [
                                'input_tokens' => $prompt,
                                'output_tokens' => $completion,
                                'total_tokens' => $prompt + $completion,
                                'input_tokens_details' => ['cached_tokens' => $cached],
                            ],
                        ],
                    ];
            }
        }
    }

    private function client(RoutingDecision $decision): BedrockRuntimeClient
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($decision);
        }

        $entries = array_map(
            fn (string $address): string => "{$decision->endpointHost}:{$decision->endpointPort}:"
                .(str_contains($address, ':') ? "[{$address}]" : $address),
            $decision->resolvedAddresses,
        );
        $http = [
            'connect_timeout' => min(config('gateway-data.connect_timeout_seconds'), $decision->timeoutSeconds),
            'timeout' => $decision->timeoutSeconds,
            'allow_redirects' => false,
        ];
        if ($entries !== []) {
            if (! defined('CURLOPT_RESOLVE')) {
                throw new GatewayException(
                    'server_error',
                    'secure_transport_unavailable',
                    503,
                    'The selected model provider is temporarily unavailable.',
                );
            }
            $http['curl'] = [constant('CURLOPT_RESOLVE') => $entries];
        }

        $config = [
            'version' => 'latest',
            'region' => $decision->provider->region,
            'endpoint' => $decision->baseUrl,
            'http' => $http,
        ];

        // A configured provider secret is a Bedrock API key used as a bearer token;
        // absent one, the SDK default chain (workload identity / SigV4) applies.
        $token = ($this->secrets ?? app(PlatformSecretResolver::class))
            ->resolve($decision->provider->secretReference);
        if ($token !== null) {
            $config['token'] = new Token($token);
        }

        return new BedrockRuntimeClient($config);
    }

    /**
     * @return array<string, mixed>
     */
    private function conversePayload(
        RoutingDecision $decision,
        CanonicalGatewayRequest $request,
    ): array {
        $messages = $request instanceof ChatCompletionRequestData
            ? $request->messages
            : (is_array($request->input)
                ? $request->input
                : [new CanonicalMessage('user', $request->input)]);
        $system = [];
        if ($request instanceof ResponseRequestData && $request->instructions !== null) {
            $system[] = ['text' => $request->instructions];
        }
        $converted = [];
        foreach ($messages as $message) {
            if (! is_string($message->content)) {
                throw new GatewayException(
                    'invalid_request_error',
                    'unsupported_content',
                    422,
                    'This Bedrock target currently supports text content only.',
                    'input',
                );
            }
            if ($message->role === 'system') {
                $system[] = ['text' => $message->content];
            } else {
                $converted[] = [
                    'role' => $message->role === 'assistant' ? 'assistant' : 'user',
                    'content' => [['text' => $message->content]],
                ];
            }
        }
        $maxTokens = $request->maxOutputTokens;

        $inferenceConfig = array_filter([
            'maxTokens' => $maxTokens,
            'temperature' => $request->temperature,
            'topP' => $request->topP,
        ], fn ($value) => $value !== null);

        return array_filter([
            'modelId' => $decision->providerModelIdentifier,
            'messages' => $converted,
            'system' => $system ?: null,
            'inferenceConfig' => $inferenceConfig ?: null,
        ], fn ($value) => $value !== null);
    }

    private function embeddings(
        RoutingDecision $decision,
        EmbeddingRequestData $request,
    ): AdapterResult {
        $data = [];
        $promptTokens = 0;
        foreach ($request->input as $index => $text) {
            $result = $this->client($decision)->invokeModel([
                'modelId' => $decision->providerModelIdentifier,
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => json_encode(array_filter([
                    'inputText' => $text,
                    'dimensions' => $request->dimensions,
                    'normalize' => true,
                ], fn ($value) => $value !== null), JSON_THROW_ON_ERROR),
            ])->toArray();
            $body = json_decode((string) $result['body'], true, flags: JSON_THROW_ON_ERROR);
            $data[] = ['object' => 'embedding', 'index' => $index, 'embedding' => $body['embedding'] ?? []];
            $promptTokens += (int) ($body['inputTextTokenCount'] ?? ceil(strlen($text) / 4));
        }

        return new AdapterResult([
            'object' => 'list',
            'data' => $data,
            'model' => $decision->modelId,
            'usage' => ['prompt_tokens' => $promptTokens, 'total_tokens' => $promptTokens],
        ], $promptTokens);
    }

    private function finishReason(?string $reason): string
    {
        return match ($reason) {
            'max_tokens' => 'length',
            'tool_use' => 'tool_calls',
            default => 'stop',
        };
    }
}

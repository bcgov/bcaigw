<?php

namespace App\Services;

use App\Contracts\CanonicalGatewayRequest;
use App\Contracts\UpstreamModelAdapter;
use App\DTO\AdapterResult;
use App\DTO\ChatCompletionRequestData;
use App\DTO\EmbeddingRequestData;
use App\DTO\ResponseRequestData;
use App\DTO\RoutingDecision;
use App\Enums\GatewayOperation;
use App\Enums\ProviderType;
use App\Exceptions\GatewayException;
use Illuminate\Support\Str;
use JsonException;

final readonly class OpenAiCompatibleAdapter implements UpstreamModelAdapter
{
    public function __construct(
        private ProviderType $type,
        private PinnedHttpTransport $transport,
    ) {}

    public function providerType(): ProviderType
    {
        return $this->type;
    }

    public function invoke(
        RoutingDecision $decision,
        CanonicalGatewayRequest $request,
    ): AdapterResult {
        $response = $this->transport->send(
            $decision,
            $this->url($decision, $request->operation()),
            $this->payload($decision, $request),
        );

        return $this->normalize($decision, $request, $response->body, $response->correlationId);
    }

    public function stream(
        RoutingDecision $decision,
        CanonicalGatewayRequest $request,
    ): iterable {
        $chunks = $this->transport->stream(
            $decision,
            $this->url($decision, $request->operation()),
            [
                ...$this->payload($decision, $request),
                'stream' => true,
                ...($request->operation() === GatewayOperation::ChatCompletions
                    ? ['stream_options' => ['include_usage' => true]]
                    : []),
            ],
        );
        $buffer = '';
        foreach ($chunks as $chunk) {
            $buffer .= $chunk;
            if (strlen($buffer) > config('gateway-data.max_stream_event_bytes')) {
                $this->invalidStream();
            }
            while (($position = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $position));
                $buffer = substr($buffer, $position + 1);
                if (! str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    return;
                }
                try {
                    $event = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new GatewayException(
                        'server_error',
                        'invalid_upstream_stream',
                        502,
                        'The selected model provider returned an invalid stream.',
                    );
                }
                if (! is_array($event)) {
                    throw new GatewayException(
                        'server_error',
                        'invalid_upstream_stream',
                        502,
                        'The selected model provider returned an invalid stream.',
                    );
                }
                yield $this->normalizeStreamEvent($decision, $request->operation(), $event);
            }
        }
        if (trim($buffer) !== '') {
            throw new GatewayException(
                'server_error',
                'invalid_upstream_stream',
                502,
                'The selected model provider returned an incomplete stream.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        RoutingDecision $decision,
        CanonicalGatewayRequest $request,
    ): array {
        if ($request instanceof ChatCompletionRequestData) {
            return array_filter([
                'model' => $decision->providerModelIdentifier,
                'messages' => array_map(fn ($message) => array_filter([
                    'role' => $message->role,
                    'content' => $message->content,
                    'name' => $message->name,
                ], fn ($value) => $value !== null), $request->messages),
                'max_tokens' => $request->maxOutputTokens,
                'temperature' => $request->temperature,
                'top_p' => $request->topP,
                'stop' => $request->stop,
                'stream' => false,
            ], fn ($value) => $value !== null);
        }
        if ($request instanceof ResponseRequestData) {
            return array_filter([
                'model' => $decision->providerModelIdentifier,
                'input' => is_array($request->input)
                    ? array_map(fn ($message) => [
                        'role' => $message->role,
                        'content' => $message->content,
                    ], $request->input)
                    : $request->input,
                'instructions' => $request->instructions,
                'max_output_tokens' => $request->maxOutputTokens,
                'temperature' => $request->temperature,
                'top_p' => $request->topP,
                'stream' => false,
            ], fn ($value) => $value !== null);
        }
        if ($request instanceof EmbeddingRequestData) {
            return array_filter([
                'model' => $decision->providerModelIdentifier,
                'input' => $request->input,
                'encoding_format' => $request->encodingFormat,
                'dimensions' => $request->dimensions,
            ], fn ($value) => $value !== null);
        }

        throw new GatewayException('server_error', 'adapter_contract_error', 500, 'The request could not be processed.');
    }

    private function url(RoutingDecision $decision, GatewayOperation $operation): string
    {
        $path = match ($operation) {
            GatewayOperation::ChatCompletions => '/chat/completions',
            GatewayOperation::Responses => '/responses',
            GatewayOperation::Embeddings => '/embeddings',
        };

        if ($this->type === ProviderType::AzureAiFoundry) {
            return rtrim($decision->baseUrl, '/').$path
                .'?api-version='.rawurlencode(config('gateway-data.azure_api_version'));
        }

        return rtrim($decision->baseUrl, '/').$path;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function normalize(
        RoutingDecision $decision,
        CanonicalGatewayRequest $request,
        array $body,
        ?string $correlationId,
    ): AdapterResult {
        $operation = $request->operation();
        $usage = $body['usage'] ?? [];
        $usageReported = is_array($body['usage'] ?? null);
        $promptTokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $cachedInputTokens = (int) (
            $usage['prompt_tokens_details']['cached_tokens']
            ?? $usage['input_tokens_details']['cached_tokens']
            ?? 0
        );
        $normalized = match ($operation) {
            GatewayOperation::ChatCompletions => [
                'id' => is_string($body['id'] ?? null) ? $body['id'] : 'chatcmpl_'.Str::uuid(),
                'object' => 'chat.completion',
                'created' => (int) ($body['created'] ?? now()->timestamp),
                'model' => $decision->modelId,
                'choices' => $this->chatChoices($body['choices'] ?? null),
                'usage' => [
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens' => $promptTokens + $completionTokens,
                ],
            ],
            GatewayOperation::Responses => [
                'id' => is_string($body['id'] ?? null) ? $body['id'] : 'resp_'.Str::uuid(),
                'object' => 'response',
                'created_at' => (int) ($body['created_at'] ?? now()->timestamp),
                'status' => in_array($body['status'] ?? null, ['completed', 'failed', 'incomplete'], true)
                    ? $body['status']
                    : 'completed',
                'model' => $decision->modelId,
                'output' => $this->responseOutput($body['output'] ?? null),
                'usage' => [
                    'input_tokens' => $promptTokens,
                    'output_tokens' => $completionTokens,
                    'total_tokens' => $promptTokens + $completionTokens,
                ],
            ],
            GatewayOperation::Embeddings => [
                'object' => 'list',
                'data' => $this->embeddings(
                    $body['data'] ?? null,
                    $request instanceof EmbeddingRequestData && $request->encodingFormat === 'base64',
                ),
                'model' => $decision->modelId,
                'usage' => [
                    'prompt_tokens' => $promptTokens,
                    'total_tokens' => $promptTokens,
                ],
            ],
        };

        return new AdapterResult(
            $normalized,
            $promptTokens,
            $completionTokens,
            $correlationId,
            $cachedInputTokens,
            $usageReported,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function chatChoices(mixed $choices): array
    {
        if (! is_array($choices)) {
            $this->invalidResponse();
        }

        return array_values(array_map(function (mixed $choice): array {
            if (! is_array($choice) || ! is_array($choice['message'] ?? null)) {
                $this->invalidResponse();
            }
            $message = $choice['message'];
            if (! is_string($message['content'] ?? null)) {
                $this->invalidResponse();
            }

            return [
                'index' => max(0, (int) ($choice['index'] ?? 0)),
                'message' => [
                    'role' => 'assistant',
                    'content' => $message['content'],
                ],
                'finish_reason' => $this->finishReason($choice['finish_reason'] ?? null),
            ];
        }, $choices));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function responseOutput(mixed $output): array
    {
        if (! is_array($output)) {
            $this->invalidResponse();
        }

        return array_values(array_map(function (mixed $item): array {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message' || ! is_array($item['content'] ?? null)) {
                $this->invalidResponse();
            }
            $content = array_values(array_map(function (mixed $part): array {
                if (! is_array($part)
                    || ! in_array($part['type'] ?? null, ['output_text', 'refusal'], true)
                    || ! is_string($part['text'] ?? $part['refusal'] ?? null)) {
                    $this->invalidResponse();
                }

                return ($part['type'] === 'refusal')
                    ? ['type' => 'refusal', 'refusal' => $part['refusal']]
                    : ['type' => 'output_text', 'text' => $part['text'], 'annotations' => []];
            }, $item['content']));

            return [
                'type' => 'message',
                'id' => is_string($item['id'] ?? null) ? $item['id'] : 'msg_'.Str::uuid(),
                'role' => 'assistant',
                'content' => $content,
            ];
        }, $output));
    }

    /**
     * @return list<array{object: string, index: int, embedding: list<float>|string}>
     */
    private function embeddings(mixed $data, bool $base64): array
    {
        if (! is_array($data)) {
            $this->invalidResponse();
        }

        return array_values(array_map(function (mixed $item, int $index) use ($base64): array {
            $embedding = is_array($item) ? ($item['embedding'] ?? null) : null;
            if (($base64 && ! is_string($embedding))
                || (! $base64 && (! is_array($embedding)
                    || collect($embedding)->contains(fn ($value) => ! is_int($value) && ! is_float($value))))) {
                $this->invalidResponse();
            }

            return [
                'object' => 'embedding',
                'index' => $index,
                'embedding' => $base64
                    ? $embedding
                    : array_map(fn ($value): float => (float) $value, array_values($embedding)),
            ];
        }, $data, array_keys($data)));
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function normalizeStreamEvent(
        RoutingDecision $decision,
        GatewayOperation $operation,
        array $event,
    ): array {
        if ($operation === GatewayOperation::ChatCompletions) {
            if (! is_array($event['choices'] ?? null)) {
                $this->invalidStream();
            }
            $choices = array_values(array_map(function (mixed $choice): array {
                if (! is_array($choice) || ! is_array($choice['delta'] ?? null)) {
                    $this->invalidStream();
                }
                $delta = array_intersect_key($choice['delta'], array_flip(['role', 'content', 'refusal']));
                foreach ($delta as $value) {
                    if (! is_string($value)) {
                        $this->invalidStream();
                    }
                }

                return [
                    'index' => max(0, (int) ($choice['index'] ?? 0)),
                    'delta' => $delta === [] ? (object) [] : $delta,
                    'finish_reason' => $this->finishReason($choice['finish_reason'] ?? null),
                ];
            }, $event['choices']));

            return [
                'id' => is_string($event['id'] ?? null) ? $event['id'] : 'chatcmpl_'.Str::uuid(),
                'object' => 'chat.completion.chunk',
                'created' => (int) ($event['created'] ?? now()->timestamp),
                'model' => $decision->modelId,
                'choices' => $choices,
                ...$this->streamUsage($event['usage'] ?? null, chat: true),
            ];
        }

        $type = $event['type'] ?? null;
        if (! is_string($type)
            || ! in_array($type, [
                'response.created',
                'response.in_progress',
                'response.output_item.added',
                'response.content_part.added',
                'response.output_text.delta',
                'response.output_text.done',
                'response.content_part.done',
                'response.output_item.done',
                'response.completed',
                'response.failed',
                'error',
            ], true)) {
            $this->invalidStream();
        }
        if ($type === 'error') {
            throw new GatewayException(
                'server_error',
                'upstream_error',
                502,
                'The selected model provider stream failed.',
            );
        }
        $normalized = array_intersect_key($event, array_flip([
            'type',
            'sequence_number',
            'response_id',
            'output_index',
            'content_index',
            'item_id',
            'delta',
            'text',
        ]));
        foreach (['response_id', 'item_id', 'delta', 'text'] as $stringKey) {
            if (isset($normalized[$stringKey]) && ! is_string($normalized[$stringKey])) {
                $this->invalidStream();
            }
        }
        if (isset($event['item'])) {
            $normalized['item'] = $this->responseOutput([$event['item']])[0];
        }
        if (isset($event['part'])) {
            $part = $event['part'];
            if (! is_array($part)
                || ! in_array($part['type'] ?? null, ['output_text', 'refusal'], true)) {
                $this->invalidStream();
            }
            $normalized['part'] = $part['type'] === 'refusal'
                ? ['type' => 'refusal', 'refusal' => (string) ($part['refusal'] ?? '')]
                : ['type' => 'output_text', 'text' => (string) ($part['text'] ?? ''), 'annotations' => []];
        }
        if (isset($event['response'])) {
            $response = $event['response'];
            if (! is_array($response)) {
                $this->invalidStream();
            }
            $normalized['response'] = [
                'id' => is_string($response['id'] ?? null) ? $response['id'] : 'resp_'.Str::uuid(),
                'object' => 'response',
                'status' => in_array($response['status'] ?? null, ['completed', 'failed', 'in_progress', 'incomplete'], true)
                    ? $response['status']
                    : 'in_progress',
                'model' => $decision->modelId,
                'output' => isset($response['output'])
                    ? $this->responseOutput($response['output'])
                    : [],
                ...$this->streamUsage($response['usage'] ?? null, chat: false),
            ];
        }

        return $normalized;
    }

    private function finishReason(mixed $reason): ?string
    {
        return in_array($reason, ['stop', 'length', 'tool_calls', 'content_filter', 'function_call'], true)
            ? $reason
            : ($reason === null ? null : 'stop');
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function streamUsage(mixed $usage, bool $chat): array
    {
        if ($usage === null) {
            return [];
        }
        if (! is_array($usage)) {
            $this->invalidStream();
        }
        $prompt = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $cached = (int) (
            $usage['prompt_tokens_details']['cached_tokens']
            ?? $usage['input_tokens_details']['cached_tokens']
            ?? 0
        );
        if ($prompt < 0 || $completion < 0) {
            $this->invalidStream();
        }

        return ['usage' => $chat
            ? [
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'total_tokens' => $prompt + $completion,
                'prompt_tokens_details' => ['cached_tokens' => max(0, min($cached, $prompt))],
            ]
            : [
                'input_tokens' => $prompt,
                'output_tokens' => $completion,
                'total_tokens' => $prompt + $completion,
                'input_tokens_details' => ['cached_tokens' => max(0, min($cached, $prompt))],
            ]];
    }

    private function invalidResponse(): never
    {
        throw new GatewayException(
            'server_error',
            'invalid_upstream_response',
            502,
            'The selected model provider returned an invalid response.',
        );
    }

    private function invalidStream(): never
    {
        throw new GatewayException(
            'server_error',
            'invalid_upstream_stream',
            502,
            'The selected model provider returned an invalid stream.',
        );
    }
}

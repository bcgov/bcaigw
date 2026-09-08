<?php

namespace App\Services;

use App\Contracts\CanonicalGatewayRequest;
use App\DTO\CanonicalMessage;
use App\DTO\ChatCompletionRequestData;
use App\DTO\EmbeddingRequestData;
use App\DTO\ResponseRequestData;
use App\DTO\RoutingDecision;
use App\Enums\GatewayOperation;
use App\Exceptions\GatewayException;

final class CanonicalRequestFactory
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function make(
        GatewayOperation $operation,
        array $data,
        RoutingDecision $decision,
    ): CanonicalGatewayRequest {
        return match ($operation) {
            GatewayOperation::ChatCompletions => $this->chat($data, $decision),
            GatewayOperation::Responses => $this->responses($data, $decision),
            GatewayOperation::Embeddings => $this->embeddings($data, $decision),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function chat(array $data, RoutingDecision $decision): ChatCompletionRequestData
    {
        if (isset($data['max_tokens'], $data['max_completion_tokens'])) {
            $this->invalid('Specify only one output token limit.', 'max_tokens');
        }
        $messages = $this->messages($data['messages'], ['text', 'image_url']);
        $maxOutput = (int) ($data['max_completion_tokens']
            ?? $data['max_tokens']
            ?? min(1024, $decision->maxOutputTokens));
        $this->assertLimits($messages, $maxOutput, $decision);
        $stop = $data['stop'] ?? null;
        if (is_string($stop) && mb_strlen($stop) > 200) {
            $this->invalid('Stop sequence is too long.', 'stop');
        }
        if (is_array($stop)
            && (count($stop) > 4
                || collect($stop)->contains(fn ($value) => ! is_string($value) || mb_strlen($value) > 200))) {
            $this->invalid('Stop must contain at most four bounded strings.', 'stop');
        }
        if ($stop !== null && ! is_string($stop) && ! is_array($stop)) {
            $this->invalid('Stop must be a string or an array of strings.', 'stop');
        }

        return new ChatCompletionRequestData(
            modelId: $data['model'],
            messages: $messages,
            maxOutputTokens: $maxOutput,
            stream: (bool) ($data['stream'] ?? false),
            temperature: isset($data['temperature']) ? (float) $data['temperature'] : null,
            topP: isset($data['top_p']) ? (float) $data['top_p'] : null,
            stop: $stop,
            metadata: $data['metadata'] ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function responses(array $data, RoutingDecision $decision): ResponseRequestData
    {
        $input = $data['input'];
        if (is_string($input)) {
            $this->assertText($input, 'input');
            $canonicalInput = $input;
            $inputForLimit = $input;
        } elseif (is_array($input)) {
            $canonicalInput = $this->messages($input, ['input_text', 'input_image', 'text']);
            $inputForLimit = $canonicalInput;
        } else {
            $this->invalid('Input must be a string or an array of messages.', 'input');
        }
        if (isset($data['instructions'])) {
            $this->assertText($data['instructions'], 'instructions');
            $inputForLimit = [$inputForLimit, $data['instructions']];
        }
        $maxOutput = (int) ($data['max_output_tokens'] ?? min(1024, $decision->maxOutputTokens));
        $this->assertLimits($inputForLimit, $maxOutput, $decision);

        return new ResponseRequestData(
            modelId: $data['model'],
            input: $canonicalInput,
            instructions: $data['instructions'] ?? null,
            maxOutputTokens: $maxOutput,
            stream: (bool) ($data['stream'] ?? false),
            temperature: isset($data['temperature']) ? (float) $data['temperature'] : null,
            topP: isset($data['top_p']) ? (float) $data['top_p'] : null,
            metadata: $data['metadata'] ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function embeddings(array $data, RoutingDecision $decision): EmbeddingRequestData
    {
        $input = is_string($data['input']) ? [$data['input']] : $data['input'];
        if (! is_array($input) || $input === [] || count($input) > config('gateway-data.max_inputs')) {
            $this->invalid('Embedding input must contain a bounded list of strings.', 'input');
        }
        foreach ($input as $value) {
            if (! is_string($value)) {
                $this->invalid('Embedding input values must be strings.', 'input');
            }
            $this->assertText($value, 'input');
        }
        $this->assertLimits($input, 0, $decision);

        return new EmbeddingRequestData(
            modelId: $data['model'],
            input: array_values($input),
            encodingFormat: $data['encoding_format'] ?? 'float',
            dimensions: isset($data['dimensions']) ? (int) $data['dimensions'] : null,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<string>  $acceptedPartTypes
     * @return list<CanonicalMessage>
     */
    private function messages(array $messages, array $acceptedPartTypes): array
    {
        if ($messages === [] || count($messages) > config('gateway-data.max_messages')) {
            $this->invalid('Message count exceeds the configured limit.', 'messages');
        }

        return array_map(function (array $message, int $index) use ($acceptedPartTypes): CanonicalMessage {
            $unknown = array_diff(array_keys($message), ['role', 'content', 'name']);
            if ($unknown !== []) {
                $this->invalid('Message contains an unsupported parameter.', "messages.{$index}");
            }
            $content = $message['content'] ?? null;
            if (is_string($content)) {
                $this->assertText($content, "messages.{$index}.content");
            } elseif (is_array($content)) {
                if ($content === [] || count($content) > config('gateway-data.max_content_parts')) {
                    $this->invalid('Content part count exceeds the configured limit.', "messages.{$index}.content");
                }
                foreach ($content as $part) {
                    if (! is_array($part)
                        || ! in_array($part['type'] ?? null, $acceptedPartTypes, true)) {
                        $this->invalid('Content part type is not supported.', "messages.{$index}.content");
                    }
                    $text = $part['text'] ?? $part['input_text'] ?? null;
                    if ($text !== null) {
                        if (! is_string($text)) {
                            $this->invalid('Text content must be a string.', "messages.{$index}.content");
                        }
                        $this->assertText($text, "messages.{$index}.content");
                    }
                    $this->assertContentPart($part, "messages.{$index}.content");
                }
            } else {
                $this->invalid('Message content must be text or content parts.', "messages.{$index}.content");
            }

            return new CanonicalMessage(
                role: $message['role'],
                content: $content,
                name: $message['name'] ?? null,
            );
        }, $messages, array_keys($messages));
    }

    private function assertText(string $text, string $parameter): void
    {
        if ($text === '' || mb_strlen($text) > config('gateway-data.max_text_characters')) {
            $this->invalid('Text input is empty or exceeds the configured length.', $parameter);
        }
    }

    /**
     * @param  array<string, mixed>  $part
     */
    private function assertContentPart(array $part, string $parameter): void
    {
        $type = $part['type'];
        $allowed = match ($type) {
            'text' => ['type', 'text'],
            'input_text' => ['type', 'input_text'],
            'image_url' => ['type', 'image_url'],
            'input_image' => ['type', 'image_url', 'detail'],
            default => [],
        };
        if (array_diff(array_keys($part), $allowed) !== []) {
            $this->invalid('Content part contains an unsupported parameter.', $parameter);
        }
        if (in_array($type, ['text', 'input_text'], true)
            && ! isset($part[$type === 'text' ? 'text' : 'input_text'])) {
            $this->invalid('Text content is required.', $parameter);
        }
        if (in_array($type, ['image_url', 'input_image'], true)) {
            $value = $part['image_url'] ?? null;
            $url = is_array($value) ? ($value['url'] ?? null) : $value;
            if (! is_string($url)
                || strlen($url) > config('gateway-data.max_image_url_characters')
                || filter_var($url, FILTER_VALIDATE_URL) === false
                || parse_url($url, PHP_URL_SCHEME) !== 'https') {
                $this->invalid('Image URL must be a bounded HTTPS URL.', $parameter);
            }
            if (is_array($value)
                && (array_diff(array_keys($value), ['url', 'detail']) !== []
                    || (isset($value['detail']) && ! in_array($value['detail'], ['auto', 'low', 'high'], true)))) {
                $this->invalid('Image URL options are invalid.', $parameter);
            }
            if (isset($part['detail']) && ! in_array($part['detail'], ['auto', 'low', 'high'], true)) {
                $this->invalid('Image detail is invalid.', $parameter);
            }
        }
    }

    private function assertLimits(mixed $input, int $maxOutput, RoutingDecision $decision): void
    {
        if ($maxOutput > $decision->maxOutputTokens) {
            $this->invalid('Requested output tokens exceed the model limit.', 'max_tokens');
        }
        $estimatedInputTokens = (int) ceil(strlen(json_encode($input, JSON_THROW_ON_ERROR)) / 4);
        if ($estimatedInputTokens > $decision->maxInputTokens
            || $estimatedInputTokens + $maxOutput > $decision->contextWindow) {
            $this->invalid('Input and output exceed the model context limits.', 'input');
        }
    }

    private function invalid(string $message, string $parameter): never
    {
        throw new GatewayException(
            'invalid_request_error',
            'validation_error',
            422,
            $message,
            $parameter,
        );
    }
}

<?php

namespace App\Services\Gateway\Forwarders;

/**
 * Shared helpers for chat forwarders: result shaping, latency timing, and
 * splitting neutral messages into a system prompt plus conversation turns.
 */
abstract class AbstractChatForwarder implements ChatForwarder
{
    /**
     * Milliseconds elapsed since the given high-resolution start time.
     */
    protected function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @return array{ok: true, http_status: ?int, latency_ms: int, reply_text: ?string, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}, error: null, correlation_id: ?string}
     */
    protected function success(?int $status, int $latencyMs, ?string $replyText, int $inputTokens, int $outputTokens, ?int $totalTokens = null, ?string $correlationId = null): array
    {
        return [
            'ok' => true,
            'http_status' => $status,
            'latency_ms' => $latencyMs,
            'reply_text' => $replyText,
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $totalTokens ?? ($inputTokens + $outputTokens),
            ],
            'error' => null,
            'correlation_id' => $correlationId,
        ];
    }

    /**
     * @return array{ok: false, http_status: ?int, latency_ms: int, reply_text: null, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}, error: string, correlation_id: null}
     */
    protected function failure(?int $status, int $latencyMs, string $error): array
    {
        return [
            'ok' => false,
            'http_status' => $status,
            'latency_ms' => $latencyMs,
            'reply_text' => null,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'error' => $error,
            'correlation_id' => null,
        ];
    }

    /**
     * Split neutral messages into a concatenated system prompt and the remaining
     * user/assistant turns. Used by providers that carry the system prompt out of band.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{0: string, 1: array<int, array{role: string, content: string}>}
     */
    protected function splitSystem(array $messages): array
    {
        $system = [];
        $turns = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $text = (string) ($message['content'] ?? '');

            if ($role === 'system') {
                $system[] = $text;

                continue;
            }

            $turns[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => $text];
        }

        return [implode("\n\n", $system), $turns];
    }
}

<?php

namespace App\Services\Gateway\Forwarders;

use App\Models\UpstreamTarget;

/**
 * Contract for forwarding a normalized chat request to a specific upstream
 * provider. Implementations translate the neutral message/option shape into the
 * provider's native API and normalize the reply back into a ForwardResult.
 *
 * @phpstan-type ForwardResult array{ok: bool, http_status: ?int, latency_ms: int, reply_text: ?string, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}, error: ?string, correlation_id: ?string}
 */
interface ChatForwarder
{
    /**
     * Whether this forwarder handles the given provider account type.
     */
    public function supports(string $providerType): bool;

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return ForwardResult
     */
    public function forward(UpstreamTarget $target, array $messages, array $options = []): array;
}

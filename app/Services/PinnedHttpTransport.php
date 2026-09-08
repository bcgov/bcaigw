<?php

namespace App\Services;

use App\DTO\RoutingDecision;
use App\DTO\UpstreamHttpResponse;
use App\Exceptions\GatewayException;
use Generator;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;

final readonly class PinnedHttpTransport
{
    public function __construct(
        private Factory $http,
        private ProviderCredentialHeaders $credentials,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(
        RoutingDecision $decision,
        string $url,
        array $payload,
    ): UpstreamHttpResponse {
        $attempts = max(1, config('gateway-data.safe_connect_retries') + 1);
        $startedAt = hrtime(true);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->normalize(
                    $this->request(
                        $decision,
                        stream: false,
                        timeoutSeconds: $this->remainingSeconds($decision, $startedAt),
                    )->post($url, $payload),
                );
            } catch (ConnectionException $exception) {
                if (! $this->canRetryConnection($exception) || $attempt === $attempts) {
                    break;
                }
            }
        }

        throw new GatewayException(
            'server_error',
            'upstream_connection_failed',
            503,
            'The selected model provider is temporarily unavailable.',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Generator<int, string>
     */
    public function stream(
        RoutingDecision $decision,
        string $url,
        array $payload,
    ): Generator {
        $attempts = max(1, config('gateway-data.safe_connect_retries') + 1);
        $startedAt = hrtime(true);
        $response = null;
        for ($attempt = 1; $attempt <= $attempts && $response === null; $attempt++) {
            try {
                $response = $this->request(
                    $decision,
                    stream: true,
                    timeoutSeconds: $this->remainingSeconds($decision, $startedAt),
                )->post($url, $payload);
            } catch (ConnectionException $exception) {
                if (! $this->canRetryConnection($exception) || $attempt === $attempts) {
                    throw new GatewayException(
                        'server_error',
                        'upstream_connection_failed',
                        503,
                        'The selected model provider is temporarily unavailable.',
                    );
                }
            }
        }
        $this->assertSuccessful($response);
        $body = $response->toPsrResponse()->getBody();
        try {
            while (! $body->eof()) {
                if ((hrtime(true) - $startedAt) / 1_000_000_000 > $decision->timeoutSeconds) {
                    throw new GatewayException(
                        'server_error',
                        'upstream_timeout',
                        504,
                        'The selected model provider timed out.',
                    );
                }
                $chunk = $body->read(8192);
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        } catch (GatewayException $exception) {
            throw $exception;
        } catch (RuntimeException) {
            throw new GatewayException(
                'server_error',
                'upstream_connection_lost',
                502,
                'The selected model provider stream ended unexpectedly.',
            );
        }
    }

    private function request(
        RoutingDecision $decision,
        bool $stream,
        float $timeoutSeconds,
    ): PendingRequest {
        $entries = array_map(
            fn (string $address): string => "{$decision->endpointHost}:{$decision->endpointPort}:"
                .(str_contains($address, ':') ? "[{$address}]" : $address),
            $decision->resolvedAddresses,
        );
        if ($entries === []) {
            throw new GatewayException(
                'server_error',
                'secure_transport_unavailable',
                503,
                'The selected model provider is temporarily unavailable.',
            );
        }
        $options = [
            'allow_redirects' => false,
            'connect_timeout' => min(
                config('gateway-data.connect_timeout_seconds'),
                $timeoutSeconds,
            ),
            'timeout' => $timeoutSeconds,
            'read_timeout' => min(
                config('gateway-data.idle_timeout_seconds'),
                $stream ? config('gateway-data.first_byte_timeout_seconds') : $timeoutSeconds,
                $timeoutSeconds,
            ),
            'stream' => $stream,
        ];
        if (! defined('CURLOPT_RESOLVE')) {
            throw new GatewayException(
                'server_error',
                'secure_transport_unavailable',
                503,
                'The selected model provider is temporarily unavailable.',
            );
        }
        $options['curl'] = [constant('CURLOPT_RESOLVE') => $entries];

        return $this->http
            ->asJson()
            ->acceptJson()
            ->withHeaders($this->credentials->forProvider($decision->provider))
            ->withOptions($options);
    }

    private function normalize(Response $response): UpstreamHttpResponse
    {
        $this->assertSuccessful($response);
        $body = $response->json();
        if (! is_array($body)) {
            throw new GatewayException(
                'server_error',
                'invalid_upstream_response',
                502,
                'The selected model provider returned an invalid response.',
            );
        }

        return new UpstreamHttpResponse(
            status: $response->status(),
            body: $body,
            correlationId: $response->header('x-request-id')
                ?? $response->header('request-id')
                ?? $response->header('x-ms-request-id'),
        );
    }

    private function assertSuccessful(?Response $response): void
    {
        if ($response === null || ! $response->successful()) {
            $status = $response?->status();
            throw new GatewayException(
                $status === 429 ? 'rate_limit_error' : 'server_error',
                $status === 429 ? 'upstream_rate_limited' : 'upstream_error',
                $status === 429 ? 429 : (($status ?? 500) >= 500 ? 502 : 400),
                $status === 429
                    ? 'The selected model provider is rate limited.'
                    : 'The selected model provider rejected the request.',
            );
        }
    }

    private function canRetryConnection(ConnectionException $exception): bool
    {
        $previous = $exception->getPrevious();
        if (! $previous instanceof ConnectException) {
            return false;
        }
        $errno = $previous->getHandlerContext()['errno'] ?? null;
        if ($errno === null
            && preg_match('/^cURL error (5|6|7|35):/', $previous->getMessage(), $matches) === 1) {
            $errno = (int) $matches[1];
        }

        return in_array($errno, [5, 6, 7, 35], true);
    }

    private function remainingSeconds(RoutingDecision $decision, int $startedAt): float
    {
        $remaining = $decision->timeoutSeconds
            - ((hrtime(true) - $startedAt) / 1_000_000_000);
        if ($remaining <= 0) {
            throw new GatewayException(
                'server_error',
                'upstream_timeout',
                504,
                'The selected model provider timed out.',
            );
        }

        return $remaining;
    }
}

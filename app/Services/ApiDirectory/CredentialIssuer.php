<?php

namespace App\Services\ApiDirectory;

use App\Models\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Issues and regenerates API Directory (APS) consumer credentials for an
 * application through the Credential Issuer API, and stores them on the
 * application. See APS "Issue Consumer Credentials via API".
 */
class CredentialIssuer
{
    // Correlates every log line of one issue/regenerate attempt; also sent upstream as x-request-id.
    private string $traceId = '';

    public function isConfigured(): bool
    {
        $aps = (array) config('services.aps', []);

        foreach (['gateway_id', 'environment_app_id', 'token_url', 'client_id', 'client_secret'] as $key) {
            if ((string) ($aps[$key] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Issue a new client for the application and persist it.
     */
    public function issue(Application $application): Application
    {
        $this->assertConfigured();
        $this->traceId = (string) Str::uuid();

        $payload = [
            'environmentAppId' => (string) config('services.aps.environment_app_id'),
            'application' => [
                // APS application names are per gateway; the public id keeps them unique.
                'name' => 'bcaigw-'.$application->public_id,
                'description' => mb_substr($application->name.' ('.$application->ministry_organization.')', 0, 255),
            ],
            'labels' => [
                'issued-by' => 'bcaigw',
                'bcaigw-application' => $application->public_id,
            ],
        ];

        $this->log('info', 'issue.start', [
            'application' => $application->public_id,
            'existing_client_id' => $application->api_directory_client_id,
            'payload' => $payload,
        ]);

        $response = $this->send('issue', 'POST', $this->consumersUrl(), $payload);

        return $this->store($application, $this->credentialFrom($response, 'issue'));
    }

    /**
     * Rotate the secret in place (same client ID) and persist it.
     */
    public function regenerate(Application $application): Application
    {
        $this->assertConfigured();
        $this->traceId = (string) Str::uuid();

        if ((string) $application->api_directory_client_id === '') {
            throw new RuntimeException('This application has no API Directory client to regenerate.');
        }

        $this->log('info', 'regenerate.start', [
            'application' => $application->public_id,
            'client_id' => $application->api_directory_client_id,
        ]);

        $response = $this->send(
            'regenerate',
            'PUT',
            $this->consumersUrl().'/'.rawurlencode($application->api_directory_client_id).'?action=regenerate',
        );

        return $this->store($application, $this->credentialFrom($response, 'regenerate'));
    }

    /**
     * Send a Credential Issuer request, logging timing and the upstream response (never secrets).
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function send(string $action, string $method, string $url, ?array $payload = null): Response
    {
        $startedAt = microtime(true);

        try {
            $request = $this->client()->withHeaders(['x-request-id' => $this->traceId]);
            $response = $method === 'PUT' ? $request->put($url) : $request->post($url, $payload ?? []);
        } catch (ConnectionException $e) {
            $this->log('error', "{$action}.connection_failed", [
                'method' => $method,
                'url' => $url,
                'duration_ms' => $this->elapsed($startedAt),
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException("API Directory credential {$action} failed: could not reach APS (trace {$this->traceId}).", 0, $e);
        }

        $context = [
            'method' => $method,
            'url' => $url,
            'status' => $response->status(),
            'duration_ms' => $this->elapsed($startedAt),
            'headers' => $this->traceHeaders($response),
        ];

        if ($response->successful()) {
            // Success bodies carry the client secret: log only which fields came back.
            $body = (array) $response->json();
            $this->log('info', "{$action}.response", $context + [
                'client_id' => $body['clientId'] ?? null,
                'flow' => $body['flow'] ?? null,
                'token_endpoint' => $body['tokenEndpoint'] ?? null,
                'fields' => array_keys($body),
            ]);
        } else {
            $this->log('error', "{$action}.response", $context + [
                'body' => mb_substr($response->body(), 0, 2000),
            ]);
        }

        return $response;
    }

    /**
     * Upstream headers that help APS correlate a failed call (Kong/route identifiers).
     *
     * @return array<string, string>
     */
    private function traceHeaders(Response $response): array
    {
        $wanted = ['x-request-id', 'x-kong-request-id', 'x-kong-upstream-latency', 'x-kong-proxy-latency', 'via', 'server', 'content-type', 'date'];
        $headers = [];

        foreach ($wanted as $name) {
            $value = $response->header($name);
            if ($value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $level, string $event, array $context = []): void
    {
        Log::{$level}('[aps] '.$event, ['trace_id' => $this->traceId] + $context);
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @param  array<string, mixed>  $credential
     */
    private function store(Application $application, array $credential): Application
    {
        $application->api_directory_client_id = $credential['clientId'];
        $application->api_directory_client_secret = $credential['clientSecret'] ?? null;
        $application->api_directory_token_endpoint = $credential['tokenEndpoint'] ?? $application->api_directory_token_endpoint;
        $application->api_directory_flow = $credential['flow'] ?? null;
        $application->api_directory_issued_at = Carbon::now();
        $application->save();

        return $application;
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialFrom(Response $response, string $action): array
    {
        if (! $response->successful()) {
            $message = $response->json('message') ?? $response->json('error') ?? $response->body();

            throw new RuntimeException("API Directory credential {$action} failed (HTTP {$response->status()}, trace {$this->traceId}): ".mb_substr((string) $message, 0, 300));
        }

        $credential = (array) $response->json();

        if (($credential['clientId'] ?? '') === '') {
            throw new RuntimeException("API Directory credential {$action} returned no client ID.");
        }

        return $credential;
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(30)->withToken($this->accessToken());
    }

    /**
     * Service-account token (client credentials), cached until shortly before expiry.
     */
    private function accessToken(): string
    {
        $cacheKey = 'aps:issuer-token:'.sha1((string) config('services.aps.client_id'));

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $this->log('debug', 'token.cache_hit', $this->tokenClaims($cached));

            return $cached;
        }

        $startedAt = microtime(true);
        $response = Http::asForm()->acceptJson()->timeout(15)->post((string) config('services.aps.token_url'), [
            'grant_type' => 'client_credentials',
            'client_id' => (string) config('services.aps.client_id'),
            'client_secret' => (string) config('services.aps.client_secret'),
        ]);

        $token = (string) $response->json('access_token', '');

        if (! $response->successful() || $token === '') {
            $this->log('error', 'token.failed', [
                'token_url' => (string) config('services.aps.token_url'),
                'client_id' => (string) config('services.aps.client_id'),
                'status' => $response->status(),
                'duration_ms' => $this->elapsed($startedAt),
                'error' => $response->json('error'),
                'error_description' => $response->json('error_description'),
            ]);

            throw new RuntimeException('Could not obtain an API Directory service-account token (HTTP '.$response->status().').');
        }

        $this->log('info', 'token.issued', [
            'token_url' => (string) config('services.aps.token_url'),
            'duration_ms' => $this->elapsed($startedAt),
            'expires_in' => $response->json('expires_in'),
        ] + $this->tokenClaims($token));

        $ttl = max(30, (int) $response->json('expires_in', 300) - 30);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    /**
     * Non-secret claims of the service-account token (issuer/client/expiry) for tracing.
     *
     * @return array<string, mixed>
     */
    private function tokenClaims(string $jwt): array
    {
        $claims = json_decode((string) base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/')), true);

        if (! is_array($claims)) {
            return [];
        }

        return [
            'token_iss' => $claims['iss'] ?? null,
            'token_azp' => $claims['azp'] ?? null,
            'token_expires_in' => isset($claims['exp']) ? (int) $claims['exp'] - time() : null,
        ];
    }

    private function consumersUrl(): string
    {
        return rtrim((string) config('services.aps.base_url'), '/')
            .'/ds/api/v3/gateways/'.rawurlencode((string) config('services.aps.gateway_id')).'/consumers';
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('API Directory credential issuing is not configured (APS_GATEWAY_ID, APS_ENVIRONMENT_APP_ID, APS_TOKEN_URL, APS_CLIENT_ID, APS_CLIENT_SECRET).');
        }
    }
}

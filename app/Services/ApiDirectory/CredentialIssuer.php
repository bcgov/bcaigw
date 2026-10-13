<?php

namespace App\Services\ApiDirectory;

use App\Models\Application;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Issues and regenerates API Directory (APS) consumer credentials for an
 * application through the Credential Issuer API, and stores them on the
 * application. See APS "Issue Consumer Credentials via API".
 */
class CredentialIssuer
{
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

        $response = $this->client()->post($this->consumersUrl(), [
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
        ]);

        return $this->store($application, $this->credentialFrom($response, 'issue'));
    }

    /**
     * Rotate the secret in place (same client ID) and persist it.
     */
    public function regenerate(Application $application): Application
    {
        $this->assertConfigured();

        if ((string) $application->api_directory_client_id === '') {
            throw new RuntimeException('This application has no API Directory client to regenerate.');
        }

        $response = $this->client()->put(
            $this->consumersUrl().'/'.rawurlencode($application->api_directory_client_id).'?action=regenerate',
        );

        return $this->store($application, $this->credentialFrom($response, 'regenerate'));
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

            throw new RuntimeException("API Directory credential {$action} failed (HTTP {$response->status()}): ".mb_substr((string) $message, 0, 300));
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
            return $cached;
        }

        $response = Http::asForm()->acceptJson()->timeout(15)->post((string) config('services.aps.token_url'), [
            'grant_type' => 'client_credentials',
            'client_id' => (string) config('services.aps.client_id'),
            'client_secret' => (string) config('services.aps.client_secret'),
        ]);

        $token = (string) $response->json('access_token', '');

        if (! $response->successful() || $token === '') {
            throw new RuntimeException('Could not obtain an API Directory service-account token (HTTP '.$response->status().').');
        }

        $ttl = max(30, (int) $response->json('expires_in', 300) - 30);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
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

<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use UnexpectedValueException;

/**
 * Validates the API Directory bearer token the same way the PDEX application does:
 * verify the RS256 signature against the issuer's cached JWKS, then confirm the
 * issuer and audience claims. Verified claims are attached to the request.
 */
class ValidateGatewayToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->bearerToken($request);

        if ($token === null) {
            return $this->unauthorized('A bearer token is required.');
        }

        $jwksUri = (string) config('gateway.api_auth.jwks_uri');
        $issuer = (string) config('gateway.api_auth.issuer');
        $audience = (string) config('gateway.api_auth.audience');

        if ($jwksUri === '' || $audience === '') {
            return $this->unauthorized('Gateway token validation is not configured.');
        }

        try {
            JWT::$leeway = (int) config('gateway.api_auth.leeway_seconds', 60);
            $keys = JWK::parseKeySet($this->jwks($jwksUri));
            $claims = (array) JWT::decode($token, $keys);
        } catch (ExpiredException) {
            return $this->unauthorized('The token has expired.');
        } catch (SignatureInvalidException | UnexpectedValueException) {
            return $this->unauthorized('The token signature is invalid.');
        } catch (Throwable) {
            return $this->unauthorized('The token could not be validated.');
        }

        if ($issuer !== '' && ($claims['iss'] ?? null) !== $issuer) {
            return $this->unauthorized('The token issuer is not trusted.');
        }

        if (! $this->audienceMatches($claims, $audience)) {
            return $this->unauthorized('The token audience does not match this gateway.');
        }

        $request->attributes->set('gateway_token_claims', $claims);
        $request->attributes->set('gateway_token_client', $claims['azp'] ?? $claims['client_id'] ?? null);

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function jwks(string $jwksUri): array
    {
        $ttl = (int) config('gateway.api_auth.jwks_cache_seconds', 3600);

        return Cache::remember('gateway:jwks:'.md5($jwksUri), $ttl, function () use ($jwksUri): array {
            $response = Http::acceptJson()->timeout(10)->get($jwksUri);

            if (! $response->ok()) {
                throw new RuntimeException('Unable to load JWKS from the identity provider.');
            }

            return $response->json();
        });
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function audienceMatches(array $claims, string $audience): bool
    {
        $aud = $claims['aud'] ?? null;

        if (is_string($aud) && $aud === $audience) {
            return true;
        }

        if (is_array($aud) && in_array($audience, $aud, true)) {
            return true;
        }

        // Keycloak client-credentials tokens frequently carry the client in azp only.
        return ($claims['azp'] ?? null) === $audience;
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'error' => [
                'message' => $message,
                'type' => 'invalid_request_error',
                'code' => 'invalid_token',
            ],
        ], 401, ['WWW-Authenticate' => 'Bearer']);
    }
}

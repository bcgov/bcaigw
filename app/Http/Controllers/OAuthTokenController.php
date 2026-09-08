<?php

namespace App\Http\Controllers;

use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Exceptions\OAuthServerException;
use App\Services\MachineSecretHasher;
use App\Services\OAuthTokenService;
use App\Services\SecurityAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

final class OAuthTokenController extends Controller
{
    #[OA\Post(
        path: '/oauth/token',
        operationId: 'issueMachineToken',
        summary: 'Exchange application machine credentials for an opaque bearer token',
        tags: ['OAuth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    required: ['grant_type', 'api_directory_client_id'],
                    properties: [
                        new OA\Property(property: 'grant_type', type: 'string', example: 'client_credentials'),
                        new OA\Property(property: 'api_directory_client_id', type: 'string'),
                        new OA\Property(property: 'client_id', type: 'string'),
                        new OA\Property(property: 'client_secret', type: 'string', format: 'password'),
                        new OA\Property(property: 'scope', type: 'string', example: 'gateway.invoke'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Bearer token issued'),
            new OA\Response(response: 400, description: 'OAuth request is invalid'),
            new OA\Response(response: 401, description: 'Client authentication failed'),
            new OA\Response(response: 429, description: 'Rate limit exceeded'),
        ]
    )]
    public function __invoke(
        Request $request,
        OAuthTokenService $tokens,
        MachineSecretHasher $hasher,
        SecurityAuditRecorder $audit,
    ): JsonResponse {
        try {
            $this->validateProtocol($request);
            [$clientIdentifier, $clientSecret] = $this->clientAuthentication($request, $hasher);
            $apiDirectoryClientId = $request->input('api_directory_client_id');
            if (! is_string($apiDirectoryClientId) || $apiDirectoryClientId === '' || strlen($apiDirectoryClientId) > 255) {
                throw new OAuthServerException('invalid_request', 400, 'missing_application_identifier');
            }

            $scope = $request->input('scope') ?? '';
            if (! is_string($scope)) {
                throw new OAuthServerException('invalid_scope', 400, 'malformed_scope');
            }
            $abilities = $scope === ''
                ? []
                : array_values(array_unique(array_filter(explode(' ', trim($scope)))));

            $payload = $tokens->issue(
                $request,
                $apiDirectoryClientId,
                $clientIdentifier,
                $clientSecret,
                $abilities,
            );

            return response()->json($payload)
                ->header('Cache-Control', 'no-store')
                ->header('Pragma', 'no-cache');
        } catch (OAuthServerException $exception) {
            $audit->record(
                $request,
                AuditEventType::MachineTokenRejected,
                AuditOutcome::Failed,
                context: array_filter([
                    'reason' => $exception->auditReason,
                    'application_public_id' => $exception->applicationPublicId,
                ]),
            );

            $response = response()->json([
                'error' => $exception->error,
                'error_description' => $exception->getMessage(),
            ], $exception->status)
                ->header('Cache-Control', 'no-store')
                ->header('Pragma', 'no-cache');

            if ($exception->error === 'invalid_client') {
                $response->header('WWW-Authenticate', 'Basic realm="bcaigw-oauth"');
            }

            return $response;
        }
    }

    private function validateProtocol(Request $request): void
    {
        if ($request->input('grant_type') !== 'client_credentials') {
            throw new OAuthServerException(
                'unsupported_grant_type',
                400,
                'unsupported_grant_type',
            );
        }
    }

    /**
     * @return array{string, string}
     */
    private function clientAuthentication(
        Request $request,
        MachineSecretHasher $hasher,
    ): array {
        $authorization = $request->header('Authorization');
        if ($authorization !== null) {
            if ($request->filled('client_id') || $request->filled('client_secret')) {
                throw new OAuthServerException('invalid_request', 400, 'ambiguous_client_authentication');
            }

            if (preg_match('/^Basic\s+([A-Za-z0-9+\/]+={0,2})$/i', $authorization, $matches) !== 1) {
                $hasher->verify('', null);
                throw new OAuthServerException('invalid_client', 401, 'malformed_basic_authentication');
            }

            $decoded = base64_decode($matches[1], true);
            if ($decoded === false || ! str_contains($decoded, ':')) {
                $hasher->verify('', null);
                throw new OAuthServerException('invalid_client', 401, 'malformed_basic_authentication');
            }

            [$identifier, $secret] = explode(':', $decoded, 2);
            $identifier = urldecode($identifier);
            $secret = urldecode($secret);
            if ($identifier === '' || $secret === ''
                || strlen($identifier) > 80 || strlen($secret) > 255) {
                $hasher->verify($secret, null);
                throw new OAuthServerException('invalid_client', 401, 'malformed_basic_authentication');
            }

            return [$identifier, $secret];
        }

        $identifier = $request->input('client_id');
        $secret = $request->input('client_secret');
        if (! is_string($identifier) || $identifier === ''
            || ! is_string($secret) || $secret === ''
            || strlen($identifier) > 80 || strlen($secret) > 255) {
            $hasher->verify(is_string($secret) ? $secret : '', null);
            throw new OAuthServerException('invalid_client', 401, 'missing_client_authentication');
        }

        return [$identifier, $secret];
    }
}

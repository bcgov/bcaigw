<?php

namespace App\Http\Middleware;

use App\Auth\MachinePrincipal;
use App\Enums\ApplicationStatus;
use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Models\MachineAccessToken;
use App\Models\MachineCredential;
use App\Services\MachineRequestMetadata;
use App\Services\OpenAiError;
use App\Services\SecurityAuditRecorder;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateMachineRequest
{
    public function __construct(
        private MachineRequestMetadata $requestMetadata,
        private SecurityAuditRecorder $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();
        $accessToken = is_string($plainTextToken) && str_starts_with($plainTextToken, 'bcaigw_at_')
            ? MachineAccessToken::query()
                ->with(['application', 'credential'])
                ->where('token_hash', hash('sha256', $plainTextToken))
                ->first()
            : null;

        if ($accessToken === null || ! $this->isUsable($accessToken)) {
            $this->audit->record(
                $request,
                AuditEventType::MachineAuthenticationFailed,
                AuditOutcome::Failed,
                context: array_filter([
                    'reason' => 'invalid_bearer',
                    'application_public_id' => $accessToken?->application?->public_id,
                ]),
            );

            return $this->unauthorized();
        }

        $principal = new MachinePrincipal(
            $accessToken->application,
            $accessToken->credential,
            $accessToken,
            $accessToken->abilities,
        );
        $request->attributes->set(MachinePrincipal::class, $principal);
        $this->recordUse($request, $principal);

        return $next($request);
    }

    private function isUsable(MachineAccessToken $accessToken): bool
    {
        $application = $accessToken->application;
        $credential = $accessToken->credential;

        return $accessToken->revoked_at === null
            && $accessToken->expires_at->isFuture()
            && $application->status === ApplicationStatus::Active
            && $application->configuration_ready
            && $accessToken->application_id === $credential->application_id
            && $accessToken->application_status_version === $application->status_version
            && $accessToken->credential_version === $credential->version
            && $credential->isUsable();
    }

    private function recordUse(Request $request, MachinePrincipal $principal): void
    {
        $threshold = now()->subSeconds(
            max(1, (int) config('machine-auth.last_used_update_interval_seconds')),
        );
        $metadata = [
            'last_used_at' => now(),
            'last_used_ip_hash' => $this->requestMetadata->ipHash($request),
        ];
        $updated = MachineAccessToken::query()
            ->whereKey($principal->accessToken->id)
            ->where(fn ($query) => $query
                ->whereNull('last_used_at')
                ->orWhere('last_used_at', '<', $threshold))
            ->update($metadata);
        MachineCredential::query()
            ->whereKey($principal->credential->id)
            ->where(fn ($query) => $query
                ->whereNull('last_used_at')
                ->orWhere('last_used_at', '<', $threshold))
            ->update($metadata);

        if ($updated === 1) {
            $this->audit->record(
                $request,
                AuditEventType::MachineRequestAuthenticated,
                AuditOutcome::Succeeded,
                context: [
                    'application_public_id' => $principal->application->public_id,
                    'credential_public_id' => $principal->credential->public_id,
                    'route_name' => (string) $request->route()?->getName(),
                ],
            );
        }
    }

    private function unauthorized(): JsonResponse
    {
        $request = request();
        if ($request->is('v1/*')) {
            return OpenAiError::response(
                'Invalid authentication credentials.',
                'authentication_error',
                'invalid_api_key',
                401,
                requestId: $request->attributes->get('bcaigw.request_id'),
            )->header('WWW-Authenticate', 'Bearer realm="bcaigw", error="invalid_token"');
        }

        return response()->json([
            'error' => 'invalid_token',
            'error_description' => 'Bearer token authentication failed.',
        ], 401)->header(
            'WWW-Authenticate',
            'Bearer realm="bcaigw", error="invalid_token"',
        );
    }
}

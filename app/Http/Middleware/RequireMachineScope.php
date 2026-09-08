<?php

namespace App\Http\Middleware;

use App\Auth\MachinePrincipal;
use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Services\OpenAiError;
use App\Services\SecurityAuditRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireMachineScope
{
    public function __construct(private SecurityAuditRecorder $audit) {}

    public function handle(Request $request, Closure $next, string $requiredAbility): Response
    {
        $principal = MachinePrincipal::fromRequest($request);
        if (! $principal->can($requiredAbility)) {
            $this->audit->record(
                $request,
                AuditEventType::MachineScopeDenied,
                AuditOutcome::Denied,
                context: [
                    'application_public_id' => $principal->application->public_id,
                    'credential_public_id' => $principal->credential->public_id,
                    'required_scope' => $requiredAbility,
                ],
            );

            if ($request->is('v1/*')) {
                return OpenAiError::response(
                    'The API key lacks the required scope.',
                    'permission_error',
                    'insufficient_scope',
                    403,
                    requestId: $request->attributes->get('bcaigw.request_id'),
                )->header(
                    'WWW-Authenticate',
                    sprintf('Bearer error="insufficient_scope", scope="%s"', $requiredAbility),
                );
            }

            return response()->json([
                'error' => 'insufficient_scope',
                'error_description' => 'The bearer token lacks the required scope.',
            ], 403)->header(
                'WWW-Authenticate',
                sprintf('Bearer error="insufficient_scope", scope="%s"', $requiredAbility),
            );
        }

        return $next($request);
    }
}

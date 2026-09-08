<?php

namespace App\Http\Middleware;

use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Models\Application;
use App\Services\SecurityAuditRecorder;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuditAuthorizationDenial
{
    public function __construct(private SecurityAuditRecorder $audit) {}

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $application = $request->route('application');
        $arguments = $application instanceof Application ? [$application] : [];

        if (Gate::forUser($request->user())->denies($ability, $arguments)) {
            $this->audit->record(
                $request,
                AuditEventType::AuthorizationDenied,
                AuditOutcome::Denied,
                actor: $request->user(),
                context: [
                    'route' => $request->route()?->getName(),
                ],
            );

            abort(403);
        }

        try {
            return $next($request);
        } catch (AuthorizationException $exception) {
            $this->audit->record(
                $request,
                AuditEventType::AuthorizationDenied,
                AuditOutcome::Denied,
                actor: $request->user(),
                context: ['route' => $request->route()?->getName()],
            );

            throw $exception;
        }
    }
}

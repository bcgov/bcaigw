<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveGatewayApplication;
use App\Http\Middleware\SuperAdmin;
use App\Http\Middleware\ValidateGatewayToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware(['gateway.jwt', 'gateway.app'])
                ->prefix('api/gateway')
                ->group(__DIR__.'/../routes/gateway.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         | Behind the OpenShift Route (and the BC Gov API gateway in front of it)
         | the pod only ever sees a proxy address. Without this the application
         | generates http:// URLs for the Keycloak redirect_uri, refuses to send
         | secure cookies, and records the router address in security audits.
         | Only private/cluster ranges are trusted by default; a deployment may
         | narrow this further, and NetworkPolicy already restricts who can open
         | a connection to the pod at all.
         */
        $middleware->trustProxies(
            at: array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'))
            ), static fn (string $proxy): bool => $proxy !== '')),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        $middleware->redirectGuestsTo(fn () => route('login'));

        $middleware->alias([
            'superadmin' => SuperAdmin::class,
            'gateway.jwt' => ValidateGatewayToken::class,
            'gateway.app' => ResolveGatewayApplication::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

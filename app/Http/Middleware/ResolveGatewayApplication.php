<?php

namespace App\Http\Middleware;

use App\Models\Application;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the application the caller is invoking as from its BCAIGW application
 * ID (a per-application GUID) and confirms it matches the API Directory token the
 * caller presented. The token is the security anchor; the GUID is a plain selector.
 */
class ResolveGatewayApplication
{
    public function handle(Request $request, Closure $next): Response
    {
        $gatewayKey = trim((string) $request->header('X-BCAIGW-Application-Id', ''));

        if ($gatewayKey === '') {
            return $this->deny('The X-BCAIGW-Application-Id header is required.', 'invalid_request', 400);
        }

        $application = Application::query()
            ->where('gateway_key', $gatewayKey)
            ->first();

        if ($application === null) {
            return $this->deny('Unknown application.', 'invalid_application', 404);
        }

        // The API Directory token must have been issued to this application's client.
        $tokenClient = $request->attributes->get('gateway_token_client');

        if ($tokenClient === null || $tokenClient !== $application->api_directory_client_id) {
            return $this->deny('The token was not issued for this application.', 'invalid_client', 403);
        }

        $request->attributes->set('gateway_application', $application);

        return $next($request);
    }

    private function deny(string $message, string $code, int $status): Response
    {
        return response()->json([
            'error' => [
                'message' => $message,
                'type' => 'invalid_request_error',
                'code' => $code,
            ],
        ], $status);
    }
}

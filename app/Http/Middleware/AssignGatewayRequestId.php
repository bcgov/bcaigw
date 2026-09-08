<?php

namespace App\Http\Middleware;

use App\Exceptions\GatewayException;
use App\Services\OpenAiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignGatewayRequestId
{
    public const ATTRIBUTE = 'bcaigw.request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set(self::ATTRIBUTE, $requestId);

        if ((int) $request->server('CONTENT_LENGTH', 0) > config('gateway-data.max_payload_bytes')) {
            return OpenAiError::response(
                'The request payload is too large.',
                'invalid_request_error',
                'payload_too_large',
                413,
                requestId: $requestId,
            );
        }

        try {
            $response = $next($request);
        } catch (GatewayException $exception) {
            $response = OpenAiError::response(
                $exception->getMessage(),
                $exception->errorType,
                $exception->errorCode,
                $exception->status,
                $exception->parameter,
                $requestId,
                $exception->headers,
            );
        }

        $response->headers->set('x-request-id', $requestId);

        return $response;
    }
}

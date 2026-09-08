<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;

final class OpenAiError
{
    public static function response(
        string $message,
        string $type,
        string $code,
        int $status,
        ?string $parameter = null,
        ?string $requestId = null,
        array $headers = [],
    ): JsonResponse {
        $response = response()->json([
            'error' => [
                'message' => $message,
                'type' => $type,
                'param' => $parameter,
                'code' => $code,
            ],
        ], $status);

        if ($requestId) {
            $response->header('x-request-id', $requestId);
        }
        foreach ($headers as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }
}

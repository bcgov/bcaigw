<?php

namespace App\Http\Middleware;

use App\Auth\MachinePrincipal;
use App\Services\OpenAiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LimitGatewayConcurrency
{
    public function handle(Request $request, Closure $next): Response
    {
        $principal = MachinePrincipal::fromRequest($request);
        $key = 'gateway-concurrency:'.$principal->application->id;
        Cache::add($key, 0, now()->addMinutes(5));
        $active = (int) Cache::increment($key);
        $limit = max(1, (int) config('gateway-data.max_concurrent_requests_per_application'));
        if ($active > $limit) {
            $this->release($key);

            return OpenAiError::response(
                'Too many concurrent requests for this application.',
                'rate_limit_error',
                'concurrency_limit_exceeded',
                429,
                requestId: $request->attributes->get('bcaigw.request_id'),
            );
        }

        $deferred = false;
        try {
            $response = $next($request);
            if ($response instanceof StreamedResponse) {
                $callback = $response->getCallback();
                $response->setCallback(function () use ($callback, $key): void {
                    try {
                        $callback();
                    } finally {
                        $this->release($key);
                    }
                });
                $deferred = true;
            }

            return $response;
        } finally {
            if (! $deferred) {
                $this->release($key);
            }
        }
    }

    private function release(string $key): void
    {
        if ((int) Cache::decrement($key) <= 0) {
            Cache::forget($key);
        }
    }
}

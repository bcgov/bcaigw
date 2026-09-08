<?php

namespace App\Http\Controllers;

use App\Services\PrometheusExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Prometheus/OpenTelemetry-compatible scrape endpoint.
 *
 * The exposition contains only bounded, low-cardinality series: no request IDs,
 * no credential identifiers and no content. Access is gated by a shared scrape
 * token so the endpoint can be exposed to a cluster monitoring stack without
 * being world-readable.
 */
final class MetricsController extends Controller
{
    public function __construct(private readonly PrometheusExporter $exporter) {}

    public function __invoke(Request $request): Response
    {
        if (! (bool) config('telemetry.metrics.enabled')) {
            return response('metrics disabled', Response::HTTP_NOT_FOUND)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $expected = config('telemetry.metrics.scrape_token');
        if (is_string($expected) && $expected !== '') {
            $presented = $request->bearerToken() ?? (string) $request->query('token', '');
            if (! hash_equals($expected, (string) $presented)) {
                return response('forbidden', Response::HTTP_FORBIDDEN)
                    ->header('Content-Type', 'text/plain; charset=UTF-8');
            }
        }

        return response($this->exporter->render(), Response::HTTP_OK)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }
}

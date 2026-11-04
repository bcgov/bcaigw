<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\GatewayDebugLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-only access to debug captures for environments with debug_enabled.
 */
class ApplicationDebugLogController extends Controller
{
    public function index(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'environment' => ['nullable', 'string', Rule::in(array_keys(config('gateway.environments')))],
        ]);

        $logs = GatewayDebugLog::query()
            ->where('application_id', $application->id)
            ->when($validated['environment'] ?? null, fn ($query, $environment) => $query->where('environment', $environment))
            ->latest('created_at')
            ->paginate(25, [
                'id', 'request_id', 'environment', 'operation', 'requested_model', 'upstream_model',
                'http_status', 'upstream_status', 'error_code', 'has_image', 'has_file',
                'latency_ms', 'prompt_tokens', 'completion_tokens', 'created_at',
            ]);

        return response()->json($logs)->header('Cache-Control', 'no-store');
    }

    public function show(Application $application, GatewayDebugLog $log): JsonResponse
    {
        abort_unless($log->application_id === $application->id, 404);

        return response()->json([
            'log' => $log->makeVisible(['request_payload', 'response_payload'])->toArray(),
        ])->header('Cache-Control', 'no-store');
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Enums\ContentState;
use App\Exceptions\ContentEncryptionException;
use App\Http\Requests\RevealCallContentRequest;
use App\Http\Requests\TelemetryFilterRequest;
use App\Models\Application;
use App\Models\GatewayCallAttempt;
use App\Services\CallContentRetentionService;
use App\Services\SecurityAuditRecorder;
use App\Services\TelemetryQueryService;
use App\Support\SafeCsv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Owner and administrator call-history surfaces.
 *
 * Listing and detail views return metadata only. Retained prompt/response
 * content is deliberately absent from every payload here and can only be
 * obtained through the explicit `reveal` action, which requires a stronger
 * authorization check plus a recorded reason.
 */
final class CallHistoryController extends Controller
{
    public function __construct(
        private readonly TelemetryQueryService $telemetry,
        private readonly CallContentRetentionService $retention,
        private readonly SecurityAuditRecorder $audit,
    ) {}

    public function index(TelemetryFilterRequest $request): InertiaResponse
    {
        $user = $request->user();
        $filters = $request->filters();

        return Inertia::render('Telemetry/CallHistory', [
            'filters' => $filters,
            'isAdministrator' => $user->isAdministrator(),
            'applications' => $user->isAdministrator()
                ? Application::query()
                    ->orderBy('name')
                    ->limit(200)
                    ->get(['public_id', 'name'])
                    ->map(fn ($application): array => [
                        'public_id' => $application->public_id,
                        'name' => $application->name,
                    ])
                : $user->applications()
                    ->orderBy('name')
                    ->get(['applications.public_id', 'applications.name'])
                    ->map(fn ($application): array => [
                        'public_id' => $application->public_id,
                        'name' => $application->name,
                    ]),
            'calls' => $this->telemetry->paginate($user, $filters)
                ->through(fn (GatewayCallAttempt $attempt): array => $this->summarise($attempt)),
            'summary' => $this->telemetry->summary($user, $filters),
        ]);
    }

    public function show(Request $request, GatewayCallAttempt $callAttempt): InertiaResponse
    {
        $application = $callAttempt->application()->firstOrFail();
        $this->authorize('viewCallHistory', $application);

        $callAttempt->loadMissing([
            'application:id,public_id,name',
            'credential:id,public_id,name',
            'alias:id,public_id,model_id',
            'target:id,public_id,name',
            'provider:id,public_id,type',
            'contentDeletion:id,public_id,created_at,reason',
        ]);

        return Inertia::render('Telemetry/CallDetail', [
            'call' => [
                ...$this->summarise($callAttempt),
                'target_public_id' => $callAttempt->target?->public_id,
                'alias_public_id' => $callAttempt->alias?->public_id,
                'config_version' => $callAttempt->target_configuration_version,
                'alias_configuration_version' => $callAttempt->alias_configuration_version,
                'model_pricing_version_id' => $callAttempt->model_pricing_version_id,
                'grant_configuration_version' => $callAttempt->grant_configuration_version,
                'application_status_version' => $callAttempt->application_status_version,
                'client_metadata' => $callAttempt->client_metadata,
                'upstream_correlation_id' => $callAttempt->upstream_correlation_id,
                'deletion' => $callAttempt->contentDeletion === null ? null : [
                    'public_id' => $callAttempt->contentDeletion->public_id,
                    'created_at' => $callAttempt->contentDeletion->created_at?->toIso8601String(),
                ],
            ],
            'canReveal' => $request->user()->can('revealCallContent', $application)
                && $callAttempt->content_state === ContentState::Stored,
        ]);
    }

    public function reveal(RevealCallContentRequest $request, GatewayCallAttempt $callAttempt): JsonResponse
    {
        $application = $callAttempt->application()->firstOrFail();
        $this->authorize('revealCallContent', $application);

        try {
            $content = $this->retention->reveal(
                $callAttempt,
                $application,
                $request->user(),
                $request,
                $request->validated('reason'),
            );
        } catch (ContentEncryptionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        return response()->json(['content' => $content]);
    }

    public function export(TelemetryFilterRequest $request): StreamedResponse|JsonResponse
    {
        $user = $request->user();
        $filters = $request->filters();
        $rows = $this->telemetry->exportRows($user, $filters);

        $this->audit->record(
            $request,
            AuditEventType::TelemetryExported,
            AuditOutcome::Succeeded,
            actor: $user,
            context: [
                'format' => $request->query('format') === 'json' ? 'json' : 'csv',
                'rows' => $rows->count(),
                'application_public_id' => is_string($filters['application'] ?? null)
                    ? $filters['application']
                    : null,
            ],
        );

        if ($request->query('format') === 'json') {
            return response()->json([
                'calls' => $rows->map(fn (GatewayCallAttempt $attempt): array => $this->summarise($attempt))->values(),
            ]);
        }

        return $this->streamCsv($rows);
    }

    /**
     * @param  Collection<int, GatewayCallAttempt>  $rows
     */
    private function streamCsv(Collection $rows): StreamedResponse
    {
        $filename = 'bcaigw-calls-'.now('UTC')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $headers = [
                'request_id', 'started_at', 'completed_at', 'application_public_id', 'application_name',
                'credential_public_id', 'operation', 'requested_model', 'resolved_model_alias',
                'provider_public_id', 'provider_type', 'streaming', 'status', 'http_status',
                'error_category', 'error_code', 'prompt_tokens', 'completion_tokens', 'cached_input_tokens',
                'total_tokens', 'cost_microunits', 'cost_currency', 'queue_latency_ms', 'upstream_latency_ms',
                'total_latency_ms', 'time_to_first_token_ms', 'retry_count', 'client_cancelled',
                'partial_response', 'content_state',
            ];
            echo SafeCsv::line($headers);

            foreach ($rows as $attempt) {
                $summary = $this->summarise($attempt);
                echo SafeCsv::line(array_map(
                    static fn (string $column): mixed => $summary[$column] ?? null,
                    $headers,
                ));
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Metadata projection. Note the deliberate absence of any content field.
     *
     * @return array<string, mixed>
     */
    private function summarise(GatewayCallAttempt $attempt): array
    {
        return [
            'request_id' => $attempt->request_id,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'completed_at' => $attempt->completed_at?->toIso8601String(),
            'application_public_id' => $attempt->application?->public_id,
            'application_name' => $attempt->application?->name,
            'credential_public_id' => $attempt->credential?->public_id,
            'operation' => $attempt->operation?->value,
            'requested_model' => $attempt->requested_model,
            'resolved_model_alias' => $attempt->resolved_model_alias,
            'provider_public_id' => $attempt->provider?->public_id,
            'provider_type' => $attempt->provider?->type?->value,
            'streaming' => (bool) $attempt->streaming,
            'status' => $attempt->status?->value,
            'http_status' => $attempt->http_status,
            'error_category' => $attempt->error_category,
            'error_code' => $attempt->error_code,
            'prompt_tokens' => $attempt->prompt_tokens,
            'completion_tokens' => $attempt->completion_tokens,
            'cached_input_tokens' => $attempt->cached_input_tokens,
            'total_tokens' => $attempt->total_tokens,
            'cost_microunits' => $attempt->cost_microunits,
            'cost_currency' => $attempt->cost_currency,
            'queue_latency_ms' => $attempt->queue_latency_ms,
            'upstream_latency_ms' => $attempt->upstream_latency_ms,
            'total_latency_ms' => $attempt->total_latency_ms,
            'time_to_first_token_ms' => $attempt->time_to_first_token_ms,
            'retry_count' => $attempt->retry_count,
            'client_cancelled' => (bool) $attempt->client_cancelled,
            'partial_response' => (bool) $attempt->partial_response,
            'content_state' => $attempt->content_state?->value,
        ];
    }
}

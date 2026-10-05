<?php

namespace Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\GatewayCallAttempt;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Portal\Http\Requests\TelemetryFilterRequest;

class CallHistoryController extends Controller
{
    public function index(TelemetryFilterRequest $request): Response
    {
        $user = $request->user();
        $filters = $request->filters();

        $query = $this->baseQuery($request, $filters);

        $calls = (clone $query)
            ->with([
                'application:id,public_id,name',
                'provider:id,public_id,type',
            ])
            ->orderByDesc('started_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (GatewayCallAttempt $attempt): array => $this->summarise($attempt));

        return Inertia::render('Portal/CallHistory/Index', [
            'filters' => $filters,
            'isAdministrator' => $user->isAdministrator(),
            'applications' => $this->accessibleApplications($request),
            'calls' => $calls,
            'summary' => $this->summary(clone $query),
        ]);
    }

    public function show(Request $request, GatewayCallAttempt $callAttempt): Response
    {
        $application = $callAttempt->application()->firstOrFail();
        $this->authorize('viewCallHistory', $application);

        $callAttempt->loadMissing([
            'application:id,public_id,name',
            'alias:id,public_id,model_id',
            'target:id,public_id,name',
            'provider:id,public_id,type',
            'contentDeletion:id,public_id,created_at,reason',
        ]);

        return Inertia::render('Portal/CallHistory/Detail', [
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
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<GatewayCallAttempt>
     */
    private function baseQuery(Request $request, array $filters): Builder
    {
        $user = $request->user();

        $query = GatewayCallAttempt::query();

        if (! $user->isAdministrator()) {
            $query->whereIn('application_id', $user->applications()->pluck('applications.id'));
        }

        if (isset($filters['application'])) {
            $query->whereHas('application', fn (Builder $q) => $q->where('public_id', $filters['application']));
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['operation'])) {
            $query->where('operation', $filters['operation']);
        }

        if (isset($filters['model'])) {
            $query->where('resolved_model_alias', 'ilike', '%'.$filters['model'].'%');
        }

        if (isset($filters['error_category'])) {
            $query->where('error_category', $filters['error_category']);
        }

        if (isset($filters['request_id'])) {
            $query->where('request_id', $filters['request_id']);
        }

        if (isset($filters['from'])) {
            $query->where('started_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('started_at', '<=', $filters['to']);
        }

        return $query;
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, string>>
     */
    private function accessibleApplications(Request $request)
    {
        $user = $request->user();

        $applications = $user->isAdministrator()
            ? Application::query()->orderBy('name')->limit(200)->get(['public_id', 'name'])
            : $user->applications()->orderBy('name')->get(['applications.public_id', 'applications.name']);

        return $applications->map(fn (Application $application): array => [
            'public_id' => $application->public_id,
            'name' => $application->name,
        ]);
    }

    /**
     * @param  Builder<GatewayCallAttempt>  $query
     * @return array<string, mixed>
     */
    private function summary(Builder $query): array
    {
        $row = $query->selectRaw(<<<'SQL'
            count(*) as requests,
            count(*) filter (where status = 'failed') as failures,
            coalesce(sum(total_tokens), 0) as total_tokens,
            coalesce(sum(cost_microunits), 0) as cost_microunits,
            coalesce(round(avg(total_latency_ms)), 0) as average_latency_ms
        SQL)->first();

        return [
            'totals' => [
                'requests' => (int) ($row->requests ?? 0),
                'failures' => (int) ($row->failures ?? 0),
                'total_tokens' => (int) ($row->total_tokens ?? 0),
                'cost_microunits' => (int) ($row->cost_microunits ?? 0),
                'average_latency_ms' => (int) ($row->average_latency_ms ?? 0),
            ],
        ];
    }

    /**
     * Metadata projection. Prompt/response content is never exposed here.
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
            'operation' => $attempt->operation,
            'requested_model' => $attempt->requested_model,
            'resolved_model_alias' => $attempt->resolved_model_alias,
            'provider_public_id' => $attempt->provider?->public_id,
            'provider_type' => $attempt->provider?->type,
            'streaming' => (bool) $attempt->streaming,
            'status' => $attempt->status,
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
            'content_state' => $attempt->content_state,
        ];
    }
}

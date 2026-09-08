<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TelemetryDeletionMode;
use App\Enums\TelemetryDeletionScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteTelemetryContentRequest;
use App\Models\Application;
use App\Models\TelemetryContentDeletion;
use App\Services\ContentDeletionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Administrator-only destruction of retained content and detailed telemetry.
 *
 * Retention is indefinite by policy, so this is the only path that removes
 * captured content. Every batch produces an append-only tombstone with the
 * actor, scope, reason and counts.
 */
final class TelemetryDeletionController extends Controller
{
    public function __construct(private readonly ContentDeletionService $deletions) {}

    public function index(): InertiaResponse
    {
        $this->authorize('deleteCallContent', Application::class);

        return Inertia::render('Admin/TelemetryDeletions', [
            'deletions' => TelemetryContentDeletion::query()
                ->with(['actor:id,name', 'application:id,public_id,name'])
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (TelemetryContentDeletion $deletion): array => [
                    'public_id' => $deletion->public_id,
                    'scope' => $deletion->scope->value,
                    'mode' => $deletion->mode->value,
                    'reason' => $deletion->reason,
                    'actor' => $deletion->actor?->name,
                    'application' => $deletion->application?->name,
                    'application_public_id' => $deletion->application?->public_id,
                    'range_from' => $deletion->range_from?->toIso8601String(),
                    'range_to' => $deletion->range_to?->toIso8601String(),
                    'matched_attempts' => $deletion->matched_attempts,
                    'content_records_destroyed' => $deletion->content_records_destroyed,
                    'details_redacted' => $deletion->details_redacted,
                    'created_at' => $deletion->created_at?->toIso8601String(),
                ]),
            'applications' => Application::query()
                ->orderBy('name')
                ->limit(500)
                ->get(['public_id', 'name'])
                ->map(fn (Application $application): array => [
                    'public_id' => $application->public_id,
                    'name' => $application->name,
                ]),
        ]);
    }

    public function store(DeleteTelemetryContentRequest $request): RedirectResponse
    {
        $this->authorize('deleteCallContent', Application::class);

        $application = $request->validated('application') === null
            ? null
            : Application::query()->where('public_id', $request->validated('application'))->firstOrFail();

        $deletion = $this->deletions->delete(
            administrator: $request->user(),
            request: $request,
            scope: TelemetryDeletionScope::from($request->validated('scope')),
            mode: TelemetryDeletionMode::from($request->validated('mode')),
            reason: $request->validated('reason'),
            application: $application,
            requestIds: array_values($request->validated('request_ids', [])),
            from: $request->validated('range_from') === null
                ? null
                : CarbonImmutable::parse($request->validated('range_from'), 'UTC'),
            to: $request->validated('range_to') === null
                ? null
                : CarbonImmutable::parse($request->validated('range_to'), 'UTC'),
        );

        return back()->with('status', sprintf(
            'Deletion %s complete: %d call(s) matched, %d content record(s) destroyed, %d detail record(s) redacted.',
            $deletion->public_id,
            $deletion->matched_attempts,
            $deletion->content_records_destroyed,
            $deletion->details_redacted,
        ));
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicationTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApplicationTransitionRequest;
use App\Http\Requests\ConfigureApplicationRequest;
use App\Http\Requests\QuotaAdjustmentRequest;
use App\Models\Application;
use App\Models\MachineCredential;
use App\Services\ApplicationConfigurationService;
use App\Services\ApplicationLifecycleService;
use App\Services\QuotaAdjustmentService;
use App\Services\QuotaUsageProjectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ApplicationReviewController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Portal/Applications/Index', [
            'applications' => Application::query()
                ->with('creator:id,name,idir_username')
                ->latest('updated_at')
                ->get(),
        ]);
    }

    public function show(
        Request $request,
        Application $application,
        QuotaUsageProjectionService $usage,
    ): Response {
        if ($request->session()->has('machineCredentialEncrypted')) {
            Inertia::encryptHistory();
        }
        $application->load([
            'users:id,name,idir_username',
            'lifecycleHistory' => fn ($query) => $query->with('actor:id,name')->latest('id'),
        ]);

        return Inertia::render('Portal/Applications/Review', [
            'application' => $application,
            'machineCredentials' => $application->machineCredentials()
                ->latest()
                ->get(MachineCredential::PORTAL_COLUMNS),
            'machineAbilities' => config('machine-auth.abilities'),
            'defaultRotationOverlapSeconds' => config('machine-auth.default_rotation_overlap_seconds'),
            'usageProjection' => $usage->forApplication($application),
            'transitions' => collect(ApplicationTransition::cases())
                ->reject(fn (ApplicationTransition $transition) => $transition === ApplicationTransition::Submit)
                ->pluck('value')
                ->values(),
        ]);
    }

    public function adjustQuota(
        QuotaAdjustmentRequest $request,
        Application $application,
        QuotaAdjustmentService $adjustments,
    ): RedirectResponse {
        $validated = $request->validated();
        $adjustments->adjust(
            $application,
            (int) ($validated['token_adjustment'] ?? 0),
            (string) ($validated['cost_adjustment'] ?? '0'),
            $validated['reason'],
            $request->user(),
            $request,
        );

        return back();
    }

    public function transition(
        ApplicationTransitionRequest $request,
        Application $application,
        ApplicationLifecycleService $lifecycle,
    ): RedirectResponse {
        $validated = $request->validated();
        $transition = ApplicationTransition::from($validated['transition']);

        if ($transition === ApplicationTransition::Submit) {
            abort(403);
        }

        $lifecycle->transition(
            $application,
            $transition,
            $request->user(),
            $request,
            $validated['status_version'],
            $validated['note'] ?? null,
        );

        return back();
    }

    public function configure(
        ConfigureApplicationRequest $request,
        Application $application,
        ApplicationConfigurationService $configuration,
    ): RedirectResponse {
        $validated = $request->validated();
        $expectedVersion = $validated['status_version'];
        unset($validated['status_version']);

        $configuration->update(
            $application,
            $validated,
            $request->user(),
            $request,
            $expectedVersion,
        );

        return back();
    }
}

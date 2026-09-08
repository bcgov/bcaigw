<?php

namespace App\Http\Controllers\Applications;

use App\Enums\ApplicationEnvironment;
use App\Enums\DataClassification;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApplicationRequest;
use App\Http\Requests\UpdateApplicationRequest;
use App\Models\Application;
use App\Models\MachineCredential;
use App\Services\ApplicationWriter;
use App\Services\QuotaUsageProjectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ApplicationController extends Controller
{
    public function index(Request $request): Response
    {
        $applications = $request->user()->applications()
            ->select([
                'applications.id',
                'applications.public_id',
                'applications.name',
                'applications.ministry_organization',
                'applications.status',
                'applications.status_version',
                'applications.updated_at',
            ])
            ->orderByDesc('applications.updated_at')
            ->get();

        return Inertia::render('Applications/Index', compact('applications'));
    }

    public function create(): Response
    {
        return Inertia::render('Applications/Form', [
            'application' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(
        StoreApplicationRequest $request,
        ApplicationWriter $writer,
    ): RedirectResponse {
        $application = $writer->create(
            $request->safe()->except(['status_version']),
            $request->user(),
            $request,
        );

        return redirect()->route('portal.applications.show', $application);
    }

    public function show(
        Request $request,
        Application $application,
        QuotaUsageProjectionService $usage,
    ): Response {
        $this->authorize('view', $application);
        if ($request->session()->has('machineCredentialEncrypted')) {
            Inertia::encryptHistory();
        }
        $canManageCredentials = $request->user()->can('manageCredentials', $application);
        $application->load([
            'users:id,name,idir_username',
            'lifecycleHistory' => fn ($query) => $query->with('actor:id,name')->latest('id'),
        ]);

        return Inertia::render('Applications/Show', [
            'application' => $application,
            'machineCredentials' => $canManageCredentials
                ? $application->machineCredentials()
                    ->latest()
                    ->get(MachineCredential::PORTAL_COLUMNS)
                : [],
            'machineAbilities' => config('machine-auth.abilities'),
            'defaultRotationOverlapSeconds' => config('machine-auth.default_rotation_overlap_seconds'),
            'grantedModels' => $application->modelGrants()
                ->where('enabled', true)
                ->with('alias:id,public_id,model_id,display_name,capabilities,status')
                ->get()
                ->map(fn ($grant) => [
                    'public_id' => $grant->public_id,
                    'model_id' => $grant->alias->model_id,
                    'display_name' => $grant->alias->display_name,
                    'capabilities' => $grant->capabilities,
                    'status' => $grant->alias->status->value,
                ]),
            'usageProjection' => $usage->forApplication($application),
            'can' => [
                'edit' => $request->user()->can('update', $application),
                'submit' => $request->user()->can('submit', $application),
                'manageMembers' => $request->user()->can('manageMembers', $application),
                'manageCredentials' => $canManageCredentials,
            ],
        ]);
    }

    public function edit(Application $application): Response
    {
        $this->authorize('update', $application);

        return Inertia::render('Applications/Form', [
            'application' => $application,
            ...$this->formOptions(),
        ]);
    }

    public function update(
        UpdateApplicationRequest $request,
        Application $application,
        ApplicationWriter $writer,
    ): RedirectResponse {
        $validated = $request->validated();
        $expectedVersion = $validated['status_version'];
        unset($validated['status_version']);

        $writer->update($application, $validated, $request->user(), $request, $expectedVersion);

        return redirect()->route('portal.applications.show', $application);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'options' => [
                'environments' => array_column(ApplicationEnvironment::cases(), 'value'),
                'classifications' => array_column(DataClassification::cases(), 'value'),
                'models' => config('gateway.requested_models'),
                'capabilities' => config('gateway.capabilities'),
            ],
        ];
    }
}

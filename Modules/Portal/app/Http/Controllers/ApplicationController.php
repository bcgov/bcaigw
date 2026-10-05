<?php

namespace Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationEnvironment;
use App\Models\ApplicationEnvironmentPromotion;
use App\Models\PublicModelAlias;
use App\Services\Applications\PromotionService;
use App\Services\Gateway\ApplicationUsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Portal\Http\Requests\ApplicationRequest;
use RuntimeException;

class ApplicationController extends Controller
{
    public function index(Request $request, ApplicationUsageService $usage): Response
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
                'applications.token_budget_monthly',
                'applications.expected_tokens_per_month',
                'applications.cost_budget_monthly',
                'applications.budget_currency',
            ])
            ->orderByDesc('applications.updated_at')
            ->get();

        $summary = $usage->listSummary($applications->pluck('id')->all());

        $applications->each(function (Application $application) use ($summary): void {
            $month = $summary[$application->id]['month'] ?? [];
            $tokenBudget = $application->token_budget_monthly ?? $application->expected_tokens_per_month;
            $costBudget = $application->cost_budget_monthly !== null ? (float) $application->cost_budget_monthly : null;

            $application->setAttribute('usage', $this->buildUsage(
                $month,
                $tokenBudget !== null ? (int) $tokenBudget : null,
                $costBudget,
                $application->budget_currency ?: 'CAD',
            ));
        });

        return Inertia::render('Portal/Applications/Index', [
            'applications' => $applications,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Portal/Applications/Form', [
            'application' => null,
            'options' => $this->formOptions(),
        ]);
    }

    public function store(ApplicationRequest $request): RedirectResponse
    {
        $application = new Application($request->safe()->except(['status_version']));
        $application->status = Application::STATUS_DRAFT;
        $application->status_version = 0;
        $application->created_by = $request->user()->id;
        $application->save();

        $application->users()->attach($request->user()->id, [
            'role' => Application::ROLE_OWNER,
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('portal.applications.show', $application)
            ->with('success', 'Application created.');
    }

    public function show(Request $request, Application $application, ApplicationUsageService $usage): Response
    {
        $this->authorize('view', $application);

        $application->load([
            'users:id,name,idir_username',
            'lifecycleHistory' => fn ($query) => $query->with('actor:id,name')->latest('id'),
        ]);

        return Inertia::render('Portal/Applications/Show', [
            'application' => $application,
            'environments' => $this->environmentsPayload($request, $application, $usage),
            'catalogModels' => PublicModelAlias::query()
                ->where('status', 'active')
                ->orderBy('display_name')
                ->with('activeTarget:id,max_output_tokens,context_window')
                ->get(['public_id', 'model_id', 'display_name', 'capabilities', 'active_target_id'])
                ->map(fn (PublicModelAlias $alias) => [
                    'public_id' => $alias->public_id,
                    'model_id' => $alias->model_id,
                    'display_name' => $alias->display_name,
                    'capabilities' => (array) ($alias->capabilities ?? []),
                    'max_output_tokens' => $alias->activeTarget?->max_output_tokens,
                    'context_window' => $alias->activeTarget?->context_window,
                ]),
            'grantedModels' => $application->modelGrants()
                ->where('environment', ApplicationEnvironment::ENV_DEVELOPMENT)
                ->where('enabled', true)
                ->with('alias:id,public_id,model_id,display_name,capabilities,active_target_id', 'alias.activeTarget:id,max_output_tokens,context_window')
                ->get()
                ->map(fn ($grant) => [
                    'public_id' => $grant->public_id,
                    'model_id' => $grant->alias->model_id,
                    'display_name' => $grant->alias->display_name,
                    'capabilities' => $grant->capabilities,
                    'max_output_tokens' => $grant->alias->activeTarget?->max_output_tokens,
                    'context_window' => $grant->alias->activeTarget?->context_window,
                ]),
            'can' => [
                'edit' => $request->user()->can('update', $application),
                'submit' => $request->user()->can('submit', $application),
                'promote' => $request->user()->can('promote', $application),
                'manageMembers' => $request->user()->can('manageMembers', $application),
            ],
            'apiUsage' => [
                'token_endpoint' => config('gateway.api_auth.token_endpoint'),
                'audience' => config('gateway.api_auth.audience'),
                'base_url' => config('gateway.api_auth.base_url'),
            ],
        ]);
    }

    /**
     * Request promotion of the application from one environment to the next.
     */
    public function promote(Request $request, Application $application, PromotionService $promotions): RedirectResponse
    {
        $this->authorize('promote', $application);

        $validated = $request->validate([
            'from_environment' => ['required', 'string', 'in:development,test'],
        ]);

        try {
            $promotion = $promotions->requestPromotion($application, $validated['from_environment'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Promotion to '.$promotion->to_environment.' submitted for approval.');
    }

    /**
     * Request a change to the development environment's granted models. The
     * change needs admin approval; development keeps serving its current models
     * until then.
     */
    public function changeModels(Request $request, Application $application, PromotionService $promotions): RedirectResponse
    {
        $this->authorize('promote', $application);

        $validated = $request->validate([
            'models' => ['required', 'array', 'min:1'],
            'models.*.alias_public_id' => ['required', 'string'],
            'models.*.capabilities' => ['nullable', 'array'],
            'models.*.capabilities.*' => ['string'],
        ]);

        try {
            $promotions->requestModelChange($application, $validated['models'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Model change submitted for approval. Development keeps serving its current models until approved.');
    }

    /**
     * Build the per-environment panels (status, budgets, month-to-date usage,
     * granted models, and promotion state) shown on the application page.
     *
     * @return array<int, array<string, mixed>>
     */
    private function environmentsPayload(Request $request, Application $application, ApplicationUsageService $usage): array
    {
        $order = [
            ApplicationEnvironment::ENV_DEVELOPMENT,
            ApplicationEnvironment::ENV_TEST,
            ApplicationEnvironment::ENV_PRODUCTION,
        ];

        $records = $application->applicationEnvironments()->get()->keyBy('environment');
        $pending = $application->promotions()
            ->where('status', ApplicationEnvironmentPromotion::STATUS_PENDING)
            ->get();
        // Stage promotions (dev->test, test->prod) are keyed by their target env.
        $pendingByTarget = $pending
            ->filter(fn ($p) => $p->from_environment !== $p->to_environment)
            ->keyBy('to_environment');
        // A same-environment request is a pending model change for that env.
        $pendingChangeByEnv = $pending
            ->filter(fn ($p) => $p->from_environment === $p->to_environment)
            ->keyBy('to_environment');

        $canPromote = $request->user()->can('promote', $application);
        $labels = config('gateway.environments');

        // Signature of each environment's enabled models/capabilities, used to
        // detect when the next stage already matches (nothing to promote).
        $signatures = $this->modelSignatures($application);

        $payload = [];

        foreach ($order as $environment) {
            /** @var ApplicationEnvironment|null $record */
            $record = $records->get($environment);

            if ($record === null) {
                continue;
            }

            $detail = $usage->environmentDetail($application, $record);

            $grants = $application->modelGrants()
                ->where('environment', $environment)
                ->where('enabled', true)
                ->with('alias:id,public_id,model_id,display_name,capabilities,active_target_id', 'alias.activeTarget:id,max_output_tokens,context_window')
                ->get();

            $models = $grants
                ->map(fn ($grant) => [
                    'alias_public_id' => $grant->alias?->public_id,
                    'model_id' => $grant->alias?->model_id,
                    'display_name' => $grant->alias?->display_name,
                    'capabilities' => array_values((array) $grant->capabilities),
                    'max_output_tokens' => $grant->alias?->activeTarget?->max_output_tokens,
                    'context_window' => $grant->alias?->activeTarget?->context_window,
                ])
                ->values();

            $next = $record->nextEnvironment();
            $pendingPromotion = $pendingByTarget->get($environment);
            $pendingChange = $pendingChangeByEnv->get($environment);
            $isDevelopment = $environment === ApplicationEnvironment::ENV_DEVELOPMENT;

            // The next stage is already in sync when it has the exact same enabled
            // models and capabilities as this environment — there is nothing to promote.
            $nextUpToDate = $next !== null
                && ($signatures[$environment] ?? '[]') === ($signatures[$next] ?? '[]');

            $payload[] = [
                'environment' => $environment,
                'label' => $labels[$environment] ?? ucfirst($environment),
                'status' => $record->status,
                'budgets' => [
                    'rate_limit_per_minute' => $record->rate_limit_per_minute,
                    'token_budget_monthly' => $record->token_budget_monthly,
                    'cost_budget_monthly' => $record->cost_budget_monthly !== null ? (float) $record->cost_budget_monthly : null,
                    'currency' => $record->budget_currency ?: 'CAD',
                ],
                'usage' => $this->buildUsage(
                    $detail['month'],
                    $detail['limits']['effective_token_budget_monthly'] ?? null,
                    $detail['limits']['effective_cost_budget_monthly'] ?? null,
                    $detail['currency'],
                ),
                'models' => $models,
                'next_environment' => $next,
                'next_label' => $next !== null ? ($labels[$next] ?? ucfirst($next)) : null,
                'next_up_to_date' => $nextUpToDate,
                'can_promote' => $canPromote
                    && $next !== null
                    && $record->status === ApplicationEnvironment::STATUS_ACTIVE
                    && $pendingPromotion === null
                    && ! $nextUpToDate,
                'can_manage_models' => $isDevelopment && $canPromote && $record->status === ApplicationEnvironment::STATUS_ACTIVE,
                'pending_promotion' => $pendingPromotion !== null ? [
                    'from' => $pendingPromotion->from_environment,
                    'requested_at' => $pendingPromotion->requested_at,
                    'model_count' => count((array) $pendingPromotion->models_snapshot),
                ] : null,
                'pending_change' => $pendingChange !== null ? [
                    'requested_at' => $pendingChange->requested_at,
                    'model_count' => count((array) $pendingChange->models_snapshot),
                ] : null,
            ];
        }

        return $payload;
    }

    /**
     * Build a normalized signature of each environment's enabled models and
     * capabilities so two environments can be compared for equality.
     *
     * @return array<string, string>
     */
    private function modelSignatures(Application $application): array
    {
        return $application->modelGrants()
            ->where('enabled', true)
            ->with('alias:id,model_id')
            ->get()
            ->groupBy('environment')
            ->map(fn ($group) => $group
                ->map(fn ($grant) => [
                    'model' => $grant->alias?->model_id,
                    'caps' => collect((array) $grant->capabilities)->sort()->values()->all(),
                ])
                ->sortBy('model')
                ->values()
                ->toJson())
            ->all();
    }

    public function edit(Application $application): Response
    {
        $this->authorize('update', $application);

        return Inertia::render('Portal/Applications/Form', [
            'application' => $application,
            'options' => $this->formOptions(),
        ]);
    }

    public function update(ApplicationRequest $request, Application $application): RedirectResponse
    {
        $validated = $request->validated();
        $expectedVersion = (int) $validated['status_version'];
        unset($validated['status_version']);

        if ((int) $application->status_version !== $expectedVersion) {
            return back()->with('error', 'This application was changed by someone else. Reload and try again.');
        }

        $application->fill($validated)->save();

        return redirect()
            ->route('portal.applications.show', $application)
            ->with('success', 'Application updated.');
    }

    public function submit(Request $request, Application $application): RedirectResponse
    {
        $this->authorize('submit', $application);

        $validated = $request->validate([
            'status_version' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ((int) $application->status_version !== (int) $validated['status_version']) {
            return back()->with('error', 'This application was changed by someone else. Reload and try again.');
        }

        DB::transaction(function () use ($application, $request, $validated): void {
            $from = $application->status;
            $application->status = Application::STATUS_SUBMITTED;
            $application->status_version = $application->status_version + 1;
            $application->save();

            $application->lifecycleHistory()->create([
                'from_status' => $from,
                'to_status' => Application::STATUS_SUBMITTED,
                'actor_user_id' => $request->user()->id,
                'note' => $validated['note'] ?? null,
            ]);
        });

        return back()->with('success', 'Application submitted for review.');
    }

    /**
     * Month-to-date usage highlight: tokens and cost consumed against the
     * effective monthly budgets, with the percentage used for each.
     *
     * @param  array<string, mixed>  $month
     * @return array<string, mixed>
     */
    private function buildUsage(array $month, ?int $tokenBudget, ?float $costBudget, string $currency): array
    {
        $tokensUsed = (int) ($month['total_tokens'] ?? 0);
        $cost = (float) ($month['cost'] ?? 0);

        return [
            'tokens_used' => $tokensUsed,
            'token_budget' => $tokenBudget,
            'token_percent' => $tokenBudget !== null && $tokenBudget > 0
                ? round($tokensUsed / $tokenBudget * 100, 1)
                : null,
            'requests' => (int) ($month['requests'] ?? 0),
            'cost' => $cost,
            'cost_budget' => $costBudget,
            'cost_percent' => $costBudget !== null && $costBudget > 0
                ? round($cost / $costBudget * 100, 1)
                : null,
            'currency' => $currency,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        $capabilityLabels = config('gateway.capabilities');

        // Only surface models the admin has enabled (active aliases), each with its own capabilities.
        $models = PublicModelAlias::query()
            ->where('status', 'active')
            ->orderBy('display_name')
            ->get(['model_id', 'display_name', 'capabilities'])
            ->map(fn (PublicModelAlias $alias) => [
                'id' => $alias->model_id,
                'name' => $alias->display_name,
                'capabilities' => collect($alias->capabilities ?? [])
                    ->mapWithKeys(fn ($key) => [$key => $capabilityLabels[$key] ?? $key])
                    ->all(),
            ])
            ->values()
            ->all();

        return [
            'environments' => config('gateway.environments'),
            'classifications' => config('gateway.classifications'),
            'models' => $models,
        ];
    }
}

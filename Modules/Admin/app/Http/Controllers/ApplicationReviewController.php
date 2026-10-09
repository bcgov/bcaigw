<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationEnvironment;
use App\Models\ApplicationEnvironmentPromotion;
use App\Models\PublicModelAlias;
use App\Models\User;
use App\Services\Applications\PromotionService;
use App\Services\Gateway\ApplicationUsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Admin\Http\Requests\ApplicationTransitionRequest;
use Modules\Admin\Services\BedrockModelCards;
use RuntimeException;

class ApplicationReviewController extends Controller
{
    public function __construct(
        private readonly ApplicationUsageService $usage,
        private readonly BedrockModelCards $modelCards,
        private readonly PromotionService $promotions,
    ) {}
    /**
     * Allowed administrator transitions: current status => list of target statuses.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        Application::STATUS_SUBMITTED => [Application::STATUS_APPROVED, Application::STATUS_REJECTED],
        Application::STATUS_APPROVED => [Application::STATUS_ACTIVE, Application::STATUS_REJECTED],
        Application::STATUS_ACTIVE => [Application::STATUS_SUSPENDED],
        Application::STATUS_SUSPENDED => [Application::STATUS_ACTIVE],
        Application::STATUS_REJECTED => [Application::STATUS_APPROVED],
    ];

    public function index(): Response
    {
        $applications = Application::query()
            ->with('creator:id,name,idir_username')
            ->orderByDesc('updated_at')
            ->get([
                'id', 'public_id', 'name', 'ministry_organization',
                'status', 'status_version', 'created_by', 'updated_at',
                'rate_limit_per_minute', 'expected_requests_per_minute',
                'token_budget_monthly', 'expected_tokens_per_month',
                'cost_budget_monthly', 'budget_currency',
            ]);

        $usage = $this->usage->listSummary($applications->pluck('id')->all());

        return Inertia::render('Admin/Applications/Index', [
            'applications' => $applications->map(fn (Application $application) => [
                'public_id' => $application->public_id,
                'name' => $application->name,
                'ministry_organization' => $application->ministry_organization,
                'status' => $application->status,
                'updated_at' => $application->updated_at,
                'creator' => $application->creator,
                'rate_limit_per_minute' => $application->rate_limit_per_minute,
                'expected_requests_per_minute' => $application->expected_requests_per_minute,
                'token_budget_monthly' => $application->token_budget_monthly,
                'expected_tokens_per_month' => $application->expected_tokens_per_month,
                'cost_budget_monthly' => $application->cost_budget_monthly,
                'budget_currency' => $application->budget_currency ?: 'CAD',
                'usage' => $usage[$application->id] ?? null,
            ]),
        ]);
    }

    public function show(Application $application): Response
    {
        $application->load([
            'creator:id,name,idir_username',
            'users:id,name,idir_username',
            'lifecycleHistory' => fn ($query) => $query->with('actor:id,name')->latest('id'),
        ]);

        $region = (string) config('services.bifrost.bedrock_region', 'ca-central-1');
        $grants = $application->modelGrants()
            ->with('alias:id,public_id,model_id,display_name')
            ->get();
        $usage = $this->usage->detail($application);

        $support = $this->regionSupport(
            $grants->pluck('alias.model_id')
                ->merge(collect($usage['by_model'] ?? [])->pluck('model_id'))
                ->merge((array) ($application->requested_models ?? []))
                ->all(),
            $region,
        );

        if (isset($usage['by_model']) && is_array($usage['by_model'])) {
            $usage['by_model'] = array_map(
                fn (array $row) => $row + $this->regionFields($support[$row['model_id'] ?? ''] ?? null),
                $usage['by_model'],
            );
        }

        return Inertia::render('Admin/Applications/Review', [
            'application' => $application,
            'bedrockRegion' => $region,
            'bedrockGeo' => $this->bedrockRegionGeo($region),
            'grantedModels' => $grants->map(fn ($grant) => [
                'public_id' => $grant->public_id,
                'model_id' => $grant->alias?->model_id,
                'display_name' => $grant->alias?->display_name,
                'capabilities' => $grant->capabilities,
                'enabled' => $grant->enabled,
            ] + $this->regionFields($support[$grant->alias?->model_id] ?? null)),
            'usage' => $usage,
            'availableTransitions' => self::TRANSITIONS[$application->status] ?? [],
            'availableModels' => $this->availableModels($application),
            'requestedModels' => $this->requestedModelsPayload($application, $support),
            'environments' => $this->environmentsPayload($application),
            'promotions' => $this->promotionsPayload($application),
            'changeRequests' => $this->changeRequestsPayload($application),
            'classifications' => config('gateway.classifications'),
            'budgetCurrencies' => config('gateway.budget_currencies', ['USD']),
        ]);
    }

    /**
     * The models/capabilities the applicant asked for. Available from the moment
     * of submission so an admin can see what they are approving before any grants
     * are provisioned.
     *
     * @param  array<string, array<string, mixed>>  $support
     * @return array<int, array<string, mixed>>
     */
    private function requestedModelsPayload(Application $application, array $support): array
    {
        $requestedModels = (array) ($application->requested_models ?? []);
        $requestedCapabilities = (array) ($application->requested_capabilities ?? []);

        if ($requestedModels === []) {
            return [];
        }

        $aliases = PublicModelAlias::query()
            ->whereIn('model_id', $requestedModels)
            ->get(['model_id', 'display_name', 'capabilities', 'status'])
            ->keyBy('model_id');

        $payload = [];

        foreach ($requestedModels as $modelId) {
            $alias = $aliases->get($modelId);

            $payload[] = [
                'model_id' => $modelId,
                'display_name' => $alias?->display_name ?? $modelId,
                'capabilities' => array_values(array_filter((array) ($requestedCapabilities[$modelId] ?? []))),
                'available' => $alias !== null && $alias->status === 'active',
            ] + $this->regionFields($support[$modelId] ?? null);
        }

        return $payload;
    }

    /**
     * Per-environment snapshot (status + budgets) for the review page.
     *
     * @return array<int, array<string, mixed>>
     */
    private function environmentsPayload(Application $application): array
    {
        $order = [
            ApplicationEnvironment::ENV_DEVELOPMENT,
            ApplicationEnvironment::ENV_TEST,
            ApplicationEnvironment::ENV_PRODUCTION,
        ];

        $records = $application->applicationEnvironments()->get()->keyBy('environment');
        $labels = config('gateway.environments');

        $payload = [];

        foreach ($order as $environment) {
            $record = $records->get($environment);

            if ($record === null) {
                continue;
            }

            $detail = $this->usage->environmentDetail($application, $record);

            $payload[] = [
                'environment' => $environment,
                'label' => $labels[$environment] ?? ucfirst($environment),
                'status' => $record->status,
                'rate_limit_per_minute' => $record->rate_limit_per_minute,
                'token_budget_daily' => $record->token_budget_daily,
                'token_budget_monthly' => $record->token_budget_monthly,
                'cost_budget_daily' => $record->cost_budget_daily !== null ? (float) $record->cost_budget_daily : null,
                'cost_budget_monthly' => $record->cost_budget_monthly !== null ? (float) $record->cost_budget_monthly : null,
                'currency' => $record->budget_currency ?: (string) config('gateway.default_budget_currency', 'USD'),
                'model_count' => $application->modelGrants()
                    ->where('environment', $environment)
                    ->where('enabled', true)
                    ->count(),
                'usage' => [
                    'today' => $detail['today'],
                    'month' => $detail['month'],
                    'total' => $detail['total'],
                ],
            ];
        }

        return $payload;
    }

    /**
     * Stage promotion requests (dev->test, test->prod), pending first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function promotionsPayload(Application $application): array
    {
        $labels = config('gateway.environments');

        return $application->promotions()
            ->whereColumn('from_environment', '!=', 'to_environment')
            ->with(['requester:id,name', 'reviewer:id,name'])
            ->orderByRaw("case when status = ? then 0 else 1 end", [ApplicationEnvironmentPromotion::STATUS_PENDING])
            ->latest('id')
            ->get()
            ->map(fn (ApplicationEnvironmentPromotion $promotion) => [
                'public_id' => $promotion->public_id,
                'from_environment' => $promotion->from_environment,
                'to_environment' => $promotion->to_environment,
                'to_label' => $labels[$promotion->to_environment] ?? ucfirst($promotion->to_environment),
                'status' => $promotion->status,
                'is_pending' => $promotion->isPending(),
                'model_count' => count((array) $promotion->models_snapshot),
                'models' => collect($promotion->models_snapshot ?? [])
                    ->map(fn ($row) => $row['model_id'] ?? null)
                    ->filter()
                    ->values(),
                'proposed_budgets' => $promotion->proposed_budgets,
                'requested_by' => $promotion->requester?->name,
                'requested_at' => $promotion->requested_at,
                'reviewed_by' => $promotion->reviewer?->name,
                'reviewed_at' => $promotion->reviewed_at,
                'review_note' => $promotion->review_note,
            ])
            ->all();
    }

    /**
     * Development model-change requests (same-environment), pending first. Each
     * carries a current-vs-new diff so an admin can see what is changing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function changeRequestsPayload(Application $application): array
    {
        $current = $application->modelGrants()
            ->where('environment', ApplicationEnvironment::ENV_DEVELOPMENT)
            ->where('enabled', true)
            ->with('alias:id,model_id,display_name')
            ->get()
            ->map(fn ($grant) => [
                'model_id' => $grant->alias?->model_id,
                'display_name' => $grant->alias?->display_name,
                'capabilities' => array_values((array) $grant->capabilities),
            ])
            ->filter(fn ($row) => $row['model_id'] !== null)
            ->keyBy('model_id');

        $currentIds = $current->keys();

        return $application->promotions()
            ->whereColumn('from_environment', '=', 'to_environment')
            ->with(['requester:id,name', 'reviewer:id,name'])
            ->orderByRaw("case when status = ? then 0 else 1 end", [ApplicationEnvironmentPromotion::STATUS_PENDING])
            ->latest('id')
            ->get()
            ->map(function (ApplicationEnvironmentPromotion $promotion) use ($current, $currentIds) {
                $new = collect($promotion->models_snapshot ?? [])
                    ->filter(fn ($row) => ($row['model_id'] ?? null) !== null)
                    ->keyBy('model_id');
                $newIds = $new->keys();

                return [
                    'public_id' => $promotion->public_id,
                    'status' => $promotion->status,
                    'is_pending' => $promotion->isPending(),
                    'requested_by' => $promotion->requester?->name,
                    'requested_at' => $promotion->requested_at,
                    'reviewed_by' => $promotion->reviewer?->name,
                    'reviewed_at' => $promotion->reviewed_at,
                    'review_note' => $promotion->review_note,
                    'current_models' => $current->values(),
                    'new_models' => $new->values(),
                    'added' => $newIds->diff($currentIds)->values(),
                    'removed' => $currentIds->diff($newIds)->values(),
                ];
            })
            ->all();
    }

    /**
     * Approve a pending promotion: apply the model snapshot to the target
     * environment, merge any budget overrides, and activate the environment.
     */
    public function approvePromotion(Request $request, Application $application, ApplicationEnvironmentPromotion $promotion): RedirectResponse
    {
        abort_unless($promotion->application_id === $application->id, 404);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:0'],
            'token_budget_monthly' => ['nullable', 'integer', 'min:0'],
            'cost_budget_monthly' => ['nullable', 'numeric', 'min:0'],
        ]);

        $overrides = array_filter(
            [
                'rate_limit_per_minute' => $validated['rate_limit_per_minute'] ?? null,
                'token_budget_monthly' => $validated['token_budget_monthly'] ?? null,
                'cost_budget_monthly' => $validated['cost_budget_monthly'] ?? null,
            ],
            fn ($value) => $value !== null,
        );

        try {
            $this->promotions->approve($promotion, $request->user(), $overrides, $validated['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Promotion approved and environment activated.');
    }

    /**
     * Reject a pending promotion.
     */
    public function rejectPromotion(Request $request, Application $application, ApplicationEnvironmentPromotion $promotion): RedirectResponse
    {
        abort_unless($promotion->application_id === $application->id, 404);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->promotions->reject($promotion, $request->user(), $validated['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Promotion rejected.');
    }

    /**
     * Admin edit of the application's descriptive details.
     */
    public function updateDetails(Request $request, Application $application): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'ministry_organization' => ['required', 'string', 'max:180'],
            'purpose_use_case' => ['required', 'string', 'max:5000'],
            'primary_contact_name' => ['required', 'string', 'max:150'],
            'primary_contact_email' => ['required', 'email:rfc', 'max:254'],
            'technical_contact_name' => ['nullable', 'string', 'max:150'],
            'technical_contact_email' => ['nullable', 'email:rfc', 'max:254'],
            'data_classification' => ['required', 'string', Rule::in(array_keys(config('gateway.classifications')))],
            'api_directory_client_id' => [
                'required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:-]+$/',
                Rule::unique('applications', 'api_directory_client_id')->ignore($application->id),
            ],
        ]);

        DB::transaction(function () use ($application, $validated, $request): void {
            $application->fill($validated);
            $changes = $this->describeChanges($application);

            if ($changes === []) {
                return;
            }

            $application->save();
            $this->recordAdminChange($application, $request->user(), 'Application details updated.', ['details' => $changes]);
        });

        return back()->with('success', 'Application details updated.');
    }

    /**
     * Admin edit of one environment's status and limits.
     */
    public function updateEnvironment(Request $request, Application $application, string $environment): RedirectResponse
    {
        $record = $application->environment($environment);
        abort_if($record === null, 404);

        $validated = $request->validate([
            'status' => ['required', Rule::in([ApplicationEnvironment::STATUS_ACTIVE, ApplicationEnvironment::STATUS_SUSPENDED, ApplicationEnvironment::STATUS_PENDING])],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'token_budget_daily' => ['nullable', 'integer', 'min:1'],
            'token_budget_monthly' => ['nullable', 'integer', 'min:1'],
            'cost_budget_daily' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'cost_budget_monthly' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'budget_currency' => ['required', 'string', Rule::in(config('gateway.budget_currencies', ['USD']))],
        ]);

        DB::transaction(function () use ($application, $record, $validated, $request): void {
            $record->fill($validated);
            $changes = $this->describeChanges($record);

            if ($changes === []) {
                return;
            }

            $record->configuration_version = $record->configuration_version + 1;
            $record->save();

            $label = config('gateway.environments.'.$record->environment, $record->environment);
            $this->recordAdminChange($application, $request->user(), "{$label} environment settings updated.", [
                'environment' => $record->environment,
                'changes' => $changes,
            ]);
        });

        return back()->with('success', 'Environment settings updated.');
    }

    /**
     * Dirty attributes as [field => [from, to]] for the audit trail.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function describeChanges(\Illuminate\Database\Eloquent\Model $model): array
    {
        $changes = [];

        foreach (array_keys($model->getDirty()) as $field) {
            $changes[$field] = [$model->getOriginal($field), $model->getAttribute($field)];
        }

        return $changes;
    }

    /**
     * Admin edits don't change status, so they are logged as a same-status entry.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function recordAdminChange(Application $application, User $actor, string $note, array $metadata): void
    {
        $application->lifecycleHistory()->create([
            'from_status' => $application->status,
            'to_status' => $application->status,
            'actor_user_id' => $actor->id,
            'note' => $note.' '.implode(', ', array_keys($metadata['changes'] ?? $metadata['details'] ?? [])),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Cross-reference model ids against AWS model cards for region availability.
     * Only bare on-demand Bedrock ids are looked up (global/region-pinned ids
     * are authoritative from the id, non-Bedrock ids have no cards).
     *
     * @param  array<int, string|null>  $modelIds
     * @return array<string, array{supported: bool|null, in_region: bool, geo: bool, global: bool, card_url: ?string}>
     */
    private function regionSupport(array $modelIds, string $region): array
    {
        $geos = ['us', 'us-gov', 'eu', 'apac', 'au', 'jp', 'sa', 'me', 'af', 'il', 'ca'];

        $toCheck = [];
        foreach ($modelIds as $id) {
            if (! is_string($id) || $id === '') {
                continue;
            }
            $bare = (string) preg_replace('#^bedrock/#', '', $id);
            $token = preg_split('#[./]#', Str::lower($bare), 2)[0] ?? '';
            $isScope = in_array($token, $geos, true)
                || (bool) preg_match('/^(us-gov|[a-z]{2})-[a-z]+-\d+$/', $token);
            if ($token !== 'global' && ! $isScope) {
                $toCheck[$id] = $bare;
            }
        }

        if ($toCheck === []) {
            return [];
        }

        $support = $this->modelCards->regionSupportFor(array_values(array_unique($toCheck)), $region);

        $out = [];
        foreach ($toCheck as $id => $bare) {
            $out[$id] = $support[$bare] ?? null;
        }

        return $out;
    }

    /**
     * Shape a region-support entry into the flat keys the Review page reads.
     *
     * @param  array{supported: bool|null, in_region: bool, geo: bool, global: bool, card_url: ?string}|null  $info
     * @return array<string, mixed>
     */
    private function regionFields(?array $info): array
    {
        return [
            'region_supported' => $info['supported'] ?? null,
            'region_in_region' => $info['in_region'] ?? null,
            'region_geo' => $info['geo'] ?? null,
            'region_global' => $info['global'] ?? null,
            'card_url' => $info['card_url'] ?? null,
        ];
    }

    /**
     * Map an AWS region to its Bedrock cross-region inference geo prefix.
     */
    private function bedrockRegionGeo(string $region): string
    {
        if (Str::startsWith($region, 'us-gov')) {
            return 'us-gov';
        }

        $prefix = Str::before($region, '-');

        return $prefix === 'ap' ? 'apac' : $prefix;
    }

    public function transition(ApplicationTransitionRequest $request, Application $application): RedirectResponse    {
        $validated = $request->validated();

        if ((int) $application->status_version !== (int) $validated['status_version']) {
            return back()->with('error', 'This application was changed by someone else. Reload and try again.');
        }

        $allowed = self::TRANSITIONS[$application->status] ?? [];

        if (! in_array($validated['to_status'], $allowed, true)) {
            return back()->with('error', 'That status change is not allowed from the current status.');
        }

        DB::transaction(function () use ($application, $request, $validated): void {
            $from = $application->status;
            $application->status = $validated['to_status'];
            $application->status_version = $application->status_version + 1;
            $application->save();

            $application->lifecycleHistory()->create([
                'from_status' => $from,
                'to_status' => $validated['to_status'],
                'actor_user_id' => $request->user()->id,
                'note' => $validated['note'] ?? null,
            ]);

            if ($validated['to_status'] === Application::STATUS_APPROVED
                || $validated['to_status'] === Application::STATUS_ACTIVE) {
                $this->provisionModelGrants($application, $request->user());
            }

            if ($validated['to_status'] === Application::STATUS_ACTIVE) {
                $this->promotions->ensureDevelopmentEnvironment($application, $request->user());
            }
        });

        return back()->with('success', 'Application status updated.');
    }

    /**
     * Active model aliases that are not already granted to this application, so an
     * admin can grant additional models. Only enabled (active) aliases are offered.
     *
     * @return array<int, array<string, mixed>>
     */
    private function availableModels(Application $application): array
    {
        $grantedAliasIds = $application->modelGrants()->pluck('public_model_alias_id')->all();

        return PublicModelAlias::query()
            ->where('status', 'active')
            ->whereNotIn('id', $grantedAliasIds)
            ->orderBy('display_name')
            ->get(['id', 'public_id', 'model_id', 'display_name', 'capabilities'])
            ->map(fn (PublicModelAlias $alias) => [
                'public_id' => $alias->public_id,
                'model_id' => $alias->model_id,
                'display_name' => $alias->display_name,
                'capabilities' => (array) ($alias->capabilities ?? []),
            ])
            ->all();
    }

    /**
     * Grant an additional model to the application. The alias must be active
     * (enabled); granted capabilities are limited to those the alias advertises.
     */
    public function grantModel(Request $request, Application $application): RedirectResponse
    {
        $validated = $request->validate([
            'alias_public_id' => ['required', 'string'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string'],
        ]);

        $alias = PublicModelAlias::query()
            ->where('public_id', $validated['alias_public_id'])
            ->where('status', 'active')
            ->first(['id', 'capabilities']);

        if ($alias === null) {
            return back()->with('error', 'That model is not available. Only enabled models can be granted.');
        }

        $allowed = (array) ($alias->capabilities ?? []);
        $requested = array_values(array_filter((array) ($validated['capabilities'] ?? [])));
        $capabilities = array_values(array_intersect($requested, $allowed));

        if ($capabilities === []) {
            $capabilities = $allowed;
        }

        $application->modelGrants()->updateOrCreate(
            ['environment' => ApplicationEnvironment::ENV_DEVELOPMENT, 'public_model_alias_id' => $alias->id],
            [
                'capabilities' => $capabilities,
                'enabled' => true,
                'granted_by' => $request->user()->id,
            ],
        );

        return back()->with('success', 'Model granted to the application.');
    }

    /**
     * Toggle whether a model grant is enabled for the application.
     */
    public function toggleGrant(Application $application, string $grant): RedirectResponse
    {
        $modelGrant = $application->modelGrants()
            ->where('public_id', $grant)
            ->first();

        if ($modelGrant === null) {
            return back()->with('error', 'That model grant was not found.');
        }

        $modelGrant->enabled = ! $modelGrant->enabled;
        $modelGrant->save();

        return back()->with('success', $modelGrant->enabled ? 'Model enabled for the application.' : 'Model disabled for the application.');
    }

    /**
     * Create (or re-enable) model grants from the application's requested models
     * and capabilities, mapped onto active public model aliases.
     */
    private function provisionModelGrants(Application $application, User $actor): void
    {
        $requestedModels = (array) ($application->requested_models ?? []);
        $requestedCapabilities = (array) ($application->requested_capabilities ?? []);

        if ($requestedModels === []) {
            return;
        }

        $aliases = PublicModelAlias::query()
            ->where('status', 'active')
            ->whereIn('model_id', $requestedModels)
            ->get(['id', 'model_id', 'capabilities'])
            ->keyBy('model_id');

        foreach ($requestedModels as $modelId) {
            $alias = $aliases->get($modelId);

            if ($alias === null) {
                continue;
            }

            $allowed = (array) ($alias->capabilities ?? []);
            $requested = array_values(array_filter((array) ($requestedCapabilities[$modelId] ?? [])));
            $capabilities = array_values(array_intersect($requested, $allowed));

            if ($capabilities === []) {
                $capabilities = $allowed;
            }

            $application->modelGrants()->updateOrCreate(
                ['environment' => ApplicationEnvironment::ENV_DEVELOPMENT, 'public_model_alias_id' => $alias->id],
                [
                    'capabilities' => $capabilities,
                    'enabled' => true,
                    'granted_by' => $actor->id,
                ],
            );
        }
    }
}

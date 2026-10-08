<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ApplicationModelGrant;
use App\Models\ModelAliasTargetVersion;
use App\Models\ModelPricingVersion;
use App\Models\ProviderAccount;
use App\Models\PublicModelAlias;
use App\Models\UpstreamTarget;
use App\Services\Gateway\Forwarders\ChatForwarderManager;
use Carbon\Carbon;
use Modules\Admin\Services\BedrockModelCards;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ModelControlController extends Controller
{
    public function __construct(
        private readonly ChatForwarderManager $forwarder,
        private readonly BedrockModelCards $modelCards,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Admin/ModelControl', $this->controlPlaneData());
    }

    public function testTarget(Request $request): Response
    {
        $data = $request->validate([
            'target_public_id' => ['required', 'string'],
            'prompt' => ['required', 'string', 'max:4000'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:4096'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'top_p' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'reasoning_effort' => ['nullable', 'string', 'in:low,medium,high'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['string', 'starts_with:data:image/'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*.filename' => ['required_with:files', 'string', 'max:255'],
            'files.*.data' => ['required_with:files', 'string', 'starts_with:data:'],
        ]);

        $target = UpstreamTarget::query()
            ->with('provider:id,type')
            ->where('public_id', $data['target_public_id'])
            ->firstOrFail();

        $result = $this->runConverseTest($target, $data['prompt'], $data['max_tokens'] ?? 300, [
            'temperature' => $data['temperature'] ?? null,
            'top_p' => $data['top_p'] ?? null,
            'reasoning_effort' => $data['reasoning_effort'] ?? null,
            'images' => $data['images'] ?? [],
            'files' => $data['files'] ?? [],
        ]);

        return Inertia::render('Admin/ModelControl', [
            ...$this->controlPlaneData(),
            'testResult' => $result,
        ]);
    }

    public function toggleGrant(ApplicationModelGrant $grant): RedirectResponse
    {
        $grant->enabled = ! $grant->enabled;
        $grant->configuration_version = $grant->configuration_version + 1;
        $grant->save();

        return back()->with('success', $grant->enabled ? 'Grant enabled.' : 'Grant disabled.');
    }

    public function updateProvider(Request $request, ProviderAccount $provider): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'string', 'max:50'],
            'region' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'string', 'in:active,disabled'],
        ]);

        $provider->update([
            ...$data,
            'configuration_version' => $provider->configuration_version + 1,
        ]);

        return back()->with('success', 'Provider updated.');
    }

    public function setProviderStatus(Request $request, ProviderAccount $provider): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'string', 'in:active,disabled']]);

        $provider->update([
            'status' => $data['status'],
            'configuration_version' => $provider->configuration_version + 1,
        ]);

        return back()->with('success', $data['status'] === 'active' ? 'Provider activated.' : 'Provider deactivated.');
    }

    public function updateTarget(Request $request, UpstreamTarget $target): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'string', 'max:2048'],
            'provider_model_identifier' => ['required', 'string', 'max:512'],
            'bifrost_key_id' => ['nullable', 'string', 'max:255'],
            'bifrost_key_name' => ['nullable', 'string', 'max:255'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['string', 'max:50'],
            'context_window' => ['required', 'integer', 'min:1'],
            'max_input_tokens' => ['required', 'integer', 'min:1'],
            'max_output_tokens' => ['required', 'integer', 'min:1'],
            'timeout_seconds' => ['required', 'integer', 'min:1', 'max:600'],
            'status' => ['required', 'string', 'in:active,disabled'],
            'input_cost' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'output_cost' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'cached_input_cost' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        if ($error = $this->providerMembershipError($data['provider_model_identifier'])) {
            return back()->withErrors(['provider_model_identifier' => $error]);
        }

        $pricing = [
            'input_cost' => $data['input_cost'] ?? null,
            'output_cost' => $data['output_cost'] ?? null,
            'cached_input_cost' => $data['cached_input_cost'] ?? null,
        ];
        unset($data['input_cost'], $data['output_cost'], $data['cached_input_cost']);

        DB::transaction(function () use ($target, $data, $pricing): void {
            $target->update([
                ...$data,
                'configuration_version' => $target->configuration_version + 1,
            ]);

            // Pricing lives on the public alias this target backs. When both costs
            // are supplied and differ from the current version, record a new
            // append-only pricing version so history is preserved.
            if ($pricing['input_cost'] === null || $pricing['output_cost'] === null) {
                return;
            }

            $alias = $target->aliases()->latest('id')->first();

            if ($alias === null) {
                return;
            }

            $current = $alias->pricingVersions()->latest('effective_at')->first();

            $unchanged = $current !== null
                && (string) $current->input_cost_per_million_tokens === (string) $pricing['input_cost']
                && (string) $current->output_cost_per_million_tokens === (string) $pricing['output_cost']
                && (string) ($current->cached_input_cost_per_million_tokens ?? '') === (string) ($pricing['cached_input_cost'] ?? '');

            if ($unchanged) {
                return;
            }

            ModelPricingVersion::create([
                'public_model_alias_id' => $alias->id,
                'effective_at' => now(),
                'currency' => $current?->currency ?? 'USD',
                'input_cost_per_million_tokens' => $pricing['input_cost'],
                'output_cost_per_million_tokens' => $pricing['output_cost'],
                'cached_input_cost_per_million_tokens' => $pricing['cached_input_cost'] ?? null,
                'created_by' => Auth::id(),
            ]);
        });

        return back()->with('success', 'Upstream target updated.');
    }

    public function setTargetStatus(Request $request, UpstreamTarget $target): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'string', 'in:active,disabled']]);

        $target->update([
            'status' => $data['status'],
            'configuration_version' => $target->configuration_version + 1,
        ]);

        return back()->with('success', $data['status'] === 'active' ? 'Target activated.' : 'Target deactivated.');
    }

    public function updateAlias(Request $request, PublicModelAlias $alias): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'active_target_public_id' => ['nullable', 'string', 'exists:upstream_targets,public_id'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['string', 'max:50'],
            'status' => ['required', 'string', 'in:active,disabled'],
        ]);

        $newTargetId = null;
        if (! empty($data['active_target_public_id'])) {
            $newTargetId = UpstreamTarget::query()->where('public_id', $data['active_target_public_id'])->value('id');
        }

        if ($data['status'] === 'active' && $newTargetId === null) {
            return back()->withErrors(['active_target_public_id' => 'An active alias must have an active target.']);
        }

        DB::transaction(function () use ($alias, $data, $newTargetId): void {
            $targetChanged = $alias->active_target_id !== $newTargetId;
            $nextVersion = $alias->configuration_version + 1;

            $alias->update([
                'display_name' => $data['display_name'],
                'description' => $data['description'] ?? null,
                'capabilities' => $data['capabilities'],
                'active_target_id' => $newTargetId,
                'status' => $data['status'],
                'configuration_version' => $nextVersion,
            ]);

            // Record an append-only history row whenever the routed target changes.
            if ($targetChanged) {
                ModelAliasTargetVersion::create([
                    'public_model_alias_id' => $alias->id,
                    'configuration_version' => $nextVersion,
                    'upstream_target_id' => $newTargetId,
                    'effective_at' => now(),
                    'changed_by' => Auth::id(),
                ]);
            }
        });

        return back()->with('success', 'Model alias updated.');
    }

    public function setAliasStatus(Request $request, PublicModelAlias $alias): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'string', 'in:active,disabled']]);

        if ($data['status'] === 'active' && $alias->active_target_id === null) {
            return back()->withErrors(['status' => 'Cannot activate an alias that has no active target. Edit it to assign one first.']);
        }

        $alias->update([
            'status' => $data['status'],
            'configuration_version' => $alias->configuration_version + 1,
        ]);

        return back()->with('success', $data['status'] === 'active' ? 'Alias activated.' : 'Alias deactivated.');
    }

    /**
     * Pricing is append-only; an "update" records a new pricing version that supersedes the previous one.
     */
    public function storeAliasPricing(Request $request, PublicModelAlias $alias): RedirectResponse
    {
        $data = $request->validate([
            'input_cost' => ['required', 'numeric', 'min:0', 'max:100000'],
            'output_cost' => ['required', 'numeric', 'min:0', 'max:100000'],
            'cached_input_cost' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'effective_at' => ['nullable', 'date'],
        ]);

        $effectiveAt = ! empty($data['effective_at']) ? Carbon::parse($data['effective_at']) : now();

        $exists = ModelPricingVersion::query()
            ->where('public_model_alias_id', $alias->id)
            ->where('effective_at', $effectiveAt)
            ->exists();

        if ($exists) {
            return back()->withErrors(['effective_at' => 'A pricing version already exists at that effective time.']);
        }

        ModelPricingVersion::create([
            'public_model_alias_id' => $alias->id,
            'effective_at' => $effectiveAt,
            'currency' => 'USD',
            'input_cost_per_million_tokens' => $data['input_cost'],
            'output_cost_per_million_tokens' => $data['output_cost'],
            'cached_input_cost_per_million_tokens' => $data['cached_input_cost'] ?? null,
            'created_by' => Auth::id(),
        ]);

        return back()->with('success', 'New pricing version recorded.');
    }

    /**
     * List providers currently enabled on the Bifrost gateway so the admin can
     * pick one to discover models from.
     */
    public function bifrostProviders(): JsonResponse
    {
        try {
            $response = $this->bifrostRequest()->timeout(15)->get($this->bifrostBaseUrl().'/api/providers');
        } catch (Throwable $e) {
            return response()->json(['error' => 'Could not reach Bifrost: '.$e->getMessage()], 502);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'Bifrost returned HTTP '.$response->status().'.'], 502);
        }

        // Surface only providers Bifrost reports as enabled (provider_status
        // active). The list response has no keys array (keys live behind a
        // separate endpoint), so provider_status is the source of truth for
        // whether a provider can serve traffic.
        $providers = collect($response->json('providers', []))
            ->map(function (array $p) {
                $name = (string) ($p['name'] ?? '');
                // A second account under the same vendor is a Bifrost custom provider
                // with its own name and a base_provider_type (e.g. ca-east -> azure).
                $baseType = $p['custom_provider_config']['base_provider_type'] ?? null;

                return [
                    'name' => $name,
                    'type' => is_string($baseType) && $baseType !== '' ? $baseType : $name,
                    'provider_status' => (string) ($p['provider_status'] ?? 'unknown'),
                    'status' => (string) ($p['status'] ?? ''),
                    'description' => (string) ($p['description'] ?? ''),
                ];
            })
            ->filter(fn (array $p) => $p['name'] !== '' && $p['provider_status'] === 'active')
            ->sortBy('name')
            ->values()
            ->all();

        return response()->json(['providers' => $providers]);
    }

    /**
     * Names of providers Bifrost currently reports as active. Returns null when
     * Bifrost cannot be reached so callers can fail open rather than block edits
     * during a transient outage.
     *
     * @return list<string>|null
     */
    private function activeBifrostProviderNames(): ?array
    {
        try {
            $response = $this->bifrostRequest()->timeout(15)->get($this->bifrostBaseUrl().'/api/providers');
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return collect($response->json('providers', []))
            ->filter(fn ($p) => is_array($p) && ($p['provider_status'] ?? null) === 'active')
            ->map(fn ($p) => (string) ($p['name'] ?? ''))
            ->filter(fn (string $name) => $name !== '')
            ->values()
            ->all();
    }

    /**
     * Enforce that a model identifier names an active Bifrost provider. The
     * identifier is `provider/model`; its provider segment must be one Bifrost
     * currently serves. Returns an error message, or null when acceptable (or
     * when Bifrost is unreachable, in which case we fail open).
     */
    private function providerMembershipError(string $identifier): ?string
    {
        $names = $this->activeBifrostProviderNames();

        if ($names === null) {
            return null;
        }

        if ($names === []) {
            return 'No providers are currently active on Bifrost. Enable one in the Bifrost dashboard first.';
        }

        $provider = Str::contains($identifier, '/') ? Str::before($identifier, '/') : '';

        if ($provider === '' || ! in_array($provider, $names, true)) {
            return 'The model must belong to an active Bifrost provider ('.implode(', ', $names).'). Prefix the identifier with "<provider>/".';
        }

        return null;
    }

    /**
     * List the keys (accounts) configured on a Bifrost provider so the admin can
     * pick which account's models to discover. Secret values are never returned.
     */
    public function bifrostProviderKeys(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
        ]);

        $provider = $data['provider'];

        try {
            $response = $this->bifrostRequest()
                ->timeout(15)
                ->get($this->bifrostBaseUrl().'/api/providers/'.$provider.'/keys');
        } catch (Throwable $e) {
            return response()->json(['error' => 'Could not reach Bifrost: '.$e->getMessage()], 502);
        }

        if ($response->status() === 404) {
            return response()->json(['error' => "Provider '{$provider}' is not configured on Bifrost."], 404);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'Bifrost returned HTTP '.$response->status().'.'], 502);
        }

        $keys = collect($response->json('keys', []))
            ->map(function (array $k) {
                $models = array_values(array_filter((array) ($k['models'] ?? []), 'is_string'));
                // Endpoint is informational (which account); redact nothing else.
                $endpoint = $k['azure_key_config']['endpoint']['value'] ?? null;

                return [
                    'id' => (string) ($k['id'] ?? ''),
                    'name' => (string) ($k['name'] ?? ''),
                    'enabled' => (bool) ($k['enabled'] ?? false),
                    'status' => (string) ($k['status'] ?? ''),
                    'description' => (string) ($k['description'] ?? ''),
                    'models' => $models,
                    'allowlist_open' => $models === [] || in_array('*', $models, true),
                    'endpoint' => is_string($endpoint) ? $endpoint : null,
                ];
            })
            ->filter(fn (array $k) => $k['id'] !== '')
            ->values()
            ->all();

        return response()->json(['keys' => $keys]);
    }

    /**
     * List models the selected Bifrost provider exposes that are not yet
     * registered as upstream targets.
     */
    public function discoverModels(Request $request): JsonResponse
    {
        $data = $request->validate([
            // A Bifrost provider name: a built-in vendor (azure, bedrock, openai)
            // or a custom provider instance (e.g. ca-central, ca-east).
            'provider' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            // Optional key (account) id: scope discovery to the models that one
            // key can access instead of the provider's whole catalog.
            'key' => ['nullable', 'string', 'max:255'],
        ]);

        $providerName = $data['provider'];
        $keyId = $data['key'] ?? null;

        // Fetch the provider once: confirms it exists on Bifrost, exposes its key
        // allowlist for the fallback path, and resolves its base type so a custom
        // instance (ca-east) still gets vendor-specific handling (bedrock, pricing).
        try {
            $providerResponse = $this->bifrostRequest()
                ->timeout(20)
                ->get($this->bifrostBaseUrl().'/api/providers/'.$providerName);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Could not reach Bifrost: '.$e->getMessage()], 502);
        }

        if ($providerResponse->status() === 404) {
            return response()->json(['error' => "Provider '{$providerName}' is not configured on Bifrost."], 404);
        }

        if (! $providerResponse->successful()) {
            return response()->json(['error' => 'Bifrost returned HTTP '.$providerResponse->status().': '.$providerResponse->body()], 502);
        }

        $baseType = $providerResponse->json('custom_provider_config.base_provider_type');
        $baseType = is_string($baseType) && $baseType !== '' ? $baseType : $providerName;

        $pricing = $this->bifrostPricing();
        $existing = UpstreamTarget::query()->pluck('provider_model_identifier')->all();

        // Preferred path: Bifrost's management model catalog (GET /api/models),
        // scoped to this provider (and key, when one is selected). Older builds
        // (e.g. v1.3.9) serve the dashboard SPA here, in which case $catalog is
        // null and we fall back to the provider's own key allowlist below.
        $catalog = $this->discoverFromCatalog($providerName, $baseType, $keyId);

        if ($catalog !== null) {
            $models = collect($catalog)
                ->map(fn (string $model) => $this->mapBifrostModel($model, $providerName, $baseType, $pricing))
                ->reject(fn (array $m) => in_array($m['id'], $existing, true))
                ->sortBy('id')
                ->values()
                ->all();

            if ($baseType === 'bedrock') {
                $models = $this->enrichBedrockWithCards($models, $providerName);
            }

            return response()->json(['models' => $models, 'allowlist_open' => false]);
        }

        // Fallback for Bifrost builds without /api/models: the discoverable models
        // are the explicit allowlist on this provider's keys. A key with an empty
        // list or a '*' entry serves everything, which cannot be enumerated
        // (allowlist_open).
        $allowlistOpen = false;
        $ids = [];

        foreach ((array) $providerResponse->json('keys', []) as $key) {
            // When a key is selected, only that account's allowlist is relevant.
            if ($keyId !== null && ($key['id'] ?? null) !== $keyId) {
                continue;
            }

            $models = (array) ($key['models'] ?? []);

            if ($models === [] || in_array('*', $models, true)) {
                $allowlistOpen = true;

                continue;
            }

            foreach ($models as $model) {
                if (is_string($model) && $model !== '') {
                    $ids[] = $model;
                }
            }
        }

        $models = collect(array_unique($ids))
            ->map(fn (string $model) => $this->mapBifrostModel($model, $providerName, $baseType, $pricing))
            ->reject(fn (array $m) => in_array($m['id'], $existing, true))
            ->sortBy('id')
            ->values()
            ->all();

        if ($baseType === 'bedrock') {
            $models = $this->enrichBedrockWithCards($models, $providerName);
        }

        return response()->json(['models' => $models, 'allowlist_open' => $allowlistOpen]);
    }

    /**
     * Query Bifrost's management model catalog (GET /api/models) for a provider
     * instance (by name). Returns the list of model ids, or null when the
     * endpoint is unavailable (older Bifrost builds answer with the dashboard SPA).
     *
     * @return list<string>|null
     */
    private function discoverFromCatalog(string $providerName, string $baseType, ?string $keyId = null): ?array
    {
        // With a key selected, scope to the models that account can access (the
        // key allowlist applies). Without one, show the provider's whole catalog.
        $query = ['provider' => $providerName, 'limit' => 1000];

        if ($keyId !== null && $keyId !== '') {
            $query['keys'] = $keyId;
        } else {
            $query['unfiltered'] = 'true';
        }

        try {
            $response = $this->bifrostRequest()
                ->timeout(20)
                ->get($this->bifrostBaseUrl().'/api/models', $query);
        } catch (Throwable) {
            return null;
        }

        // Older builds return 200 text/html (the SPA). Require a JSON body with
        // the documented "models" array before trusting the catalog.
        if (! $response->successful()
            || ! str_contains((string) $response->header('Content-Type'), 'json')
            || ! is_array($response->json('models'))) {
            return null;
        }

        $ids = [];

        foreach ($response->json('models', []) as $model) {
            $name = is_array($model) ? ($model['name'] ?? null) : (is_string($model) ? $model : null);
            $modelProvider = is_array($model) ? ($model['provider'] ?? $providerName) : $providerName;

            if (! is_string($name) || $name === '' || $modelProvider !== $providerName) {
                continue;
            }

            // Catalog names are bare (no provider prefix) but may contain slashes
            // themselves; qualify every id as "provider/name" so the gateway routes
            // to this specific provider instance.
            $ids[] = str_starts_with($name, $providerName.'/') ? $name : $providerName.'/'.$name;
        }

        $ids = array_values(array_unique($ids));

        // Bedrock's catalog lists every region/commitment SKU; keep only models
        // this account can actually invoke (on-demand, global, or in-region).
        if ($baseType === 'bedrock') {
            $ids = $this->filterBedrockInvokable($ids, $providerName);
        }

        return $ids;
    }

    /**
     * Restrict the Bedrock catalog to models invokable from the configured
     * region using a denylist: keep global cross-region profiles, bare
     * on-demand ids (which cover in-region models such as Titan Embeddings and
     * Cohere Rerank) and any id pinned to the local geo/region; drop only the
     * profiles and SKUs pinned to other geographies (us., eu., apac., jp., ...)
     * and commitment/provisioned-throughput SKUs.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function filterBedrockInvokable(array $ids, string $providerName): array
    {
        $region = (string) config('services.bifrost.bedrock_region', 'ca-central-1');
        $localGeo = $this->bedrockRegionGeo($region);
        $prefix = $providerName.'/';

        return array_values(array_filter($ids, function (string $id) use ($localGeo, $prefix): bool {
            $name = Str::lower(Str::startsWith($id, $prefix) ? Str::after($id, $prefix) : $id);

            // Commitment / provisioned-throughput SKUs are not on-demand invokable.
            if (Str::contains($name, 'commitment')) {
                return false;
            }

            // Bedrock features and internal routing aliases are not chat models.
            if ($name === 'guardrails' || Str::startsWith($name, 'invoke/')) {
                return false;
            }

            // Leading token is the routing scope: a geo prefix (us, eu, global),
            // a region id (eu-central-1), or a bare provider name.
            $token = preg_split('#[./]#', $name, 2)[0] ?? '';

            // Keep global routing and bare provider on-demand ids.
            if ($token === 'global' || ! $this->isBedrockRegionScope($token)) {
                return true;
            }

            // Region/geo-pinned ids survive only for the configured local region.
            return $token === $localGeo || Str::startsWith($token, $localGeo.'-');
        }));
    }

    /**
     * Cross-reference bare on-demand Bedrock ids against the AWS model-card
     * "Regional Availability" tables to confirm whether they are actually
     * usable in the configured region. Global- and region-pinned ids are
     * authoritative from their id, so only bare provider ids are checked.
     *
     * Adds `region_supported` (true/false/null=unverified) plus the individual
     * in-region/geo/global flags and the source `card_url` to each model.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @return array<int, array<string, mixed>>
     */
    private function enrichBedrockWithCards(array $models, string $providerName): array
    {
        $region = (string) config('services.bifrost.bedrock_region', 'ca-central-1');
        $prefix = $providerName.'/';

        // Only bare on-demand ids need a card lookup (global/region-pinned ids
        // are already authoritative). Map full id => provider-stripped bare id.
        $toCheck = [];
        foreach ($models as $m) {
            $bare = Str::startsWith((string) $m['id'], $prefix) ? Str::after((string) $m['id'], $prefix) : (string) $m['id'];
            $token = preg_split('#[./]#', Str::lower($bare), 2)[0] ?? '';
            if ($token !== 'global' && ! $this->isBedrockRegionScope($token)) {
                $toCheck[$m['id']] = $bare;
            }
        }

        if ($toCheck === []) {
            return $models;
        }

        $support = $this->modelCards->regionSupportFor(array_values(array_unique($toCheck)), $region);

        return array_map(function (array $m) use ($toCheck, $support): array {
            $bare = $toCheck[$m['id']] ?? null;
            $info = $bare !== null ? ($support[$bare] ?? null) : null;
            if ($info === null) {
                return $m;
            }

            $m['region_supported'] = $info['supported'];
            $m['region_in_region'] = $info['in_region'];
            $m['region_geo'] = $info['geo'];
            $m['region_global'] = $info['global'];
            $m['card_url'] = $info['card_url'];

            return $m;
        }, $models);
    }

    /**
     * True when a leading id token is an AWS geo prefix (us, eu, apac, ca, ...)
     * or a full region id (eu-central-1, us-gov-west-1, ...), as opposed to a
     * bare provider name (amazon, cohere, meta, ...).
     */
    private function isBedrockRegionScope(string $token): bool
    {
        $geos = ['us', 'us-gov', 'eu', 'apac', 'au', 'jp', 'sa', 'me', 'af', 'il', 'ca'];

        return in_array($token, $geos, true)
            || (bool) preg_match('/^(us-gov|[a-z]{2})-[a-z]+-\d+$/', $token);
    }

    /**
     * Map an AWS region to its Bedrock cross-region inference geo prefix
     * (e.g. ca-central-1 => 'ca', us-east-1 => 'us', ap-south-1 => 'apac').
     */
    private function bedrockRegionGeo(string $region): string
    {
        if (Str::startsWith($region, 'us-gov')) {
            return 'us-gov';
        }

        $prefix = Str::before($region, '-');

        return $prefix === 'ap' ? 'apac' : $prefix;
    }

    /**
     * Normalise a Bifrost model id into the shape the discovery UI expects,
     * enriched from Bifrost's pricing datasheet (per-million cost, context
     * window, output cap and capabilities) when the model is found there.
     *
     * @param  array<string, array<string, mixed>>  $pricing
     * @return array<string, mixed>
     */
    private function mapBifrostModel(string $modelId, string $providerName, string $baseType, array $pricing = []): array
    {
        $id = str_contains($modelId, '/') ? $modelId : $providerName.'/'.$modelId;

        $entry = $this->lookupPricing($id, $baseType, $pricing);

        $inputPerToken = $entry['input_cost_per_token'] ?? null;
        $outputPerToken = $entry['output_cost_per_token'] ?? null;

        return [
            'id' => $id,
            'name' => Str::afterLast($id, '/'),
            // Datasheet costs are per-token; the UI works in dollars per million.
            'input_cost' => is_numeric($inputPerToken) ? round($inputPerToken * 1_000_000, 4) : null,
            'output_cost' => is_numeric($outputPerToken) ? round($outputPerToken * 1_000_000, 4) : null,
            'context_window' => $entry['max_input_tokens'] ?? $entry['max_tokens'] ?? null,
            'max_output_tokens' => $entry['max_output_tokens'] ?? null,
            'capabilities' => $this->capabilitiesFromPricing($entry),
        ];
    }

    /**
     * Resolve a discovered model id to its pricing datasheet entry, trying the
     * name with and without the provider prefix.
     *
     * @param  array<string, array<string, mixed>>  $pricing
     * @return array<string, mixed>
     */
    private function lookupPricing(string $id, string $baseType, array $pricing): array
    {
        if ($pricing === []) {
            return [];
        }

        // The datasheet is keyed by canonical vendor type, so also try the bare
        // model and the base-type-prefixed form (a custom instance like
        // ca-east/gpt-4o still resolves to azure/gpt-4o pricing).
        $bare = Str::afterLast($id, '/');

        $candidates = [
            $id,
            $bare,
            $baseType.'/'.$bare,
        ];

        foreach ($candidates as $candidate) {
            if (isset($pricing[$candidate]) && is_array($pricing[$candidate])) {
                return $pricing[$candidate];
            }
        }

        return [];
    }

    /**
     * Derive short capability labels from a datasheet entry's mode and
     * supports_* flags.
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function capabilitiesFromPricing(array $entry): array
    {
        $mode = is_string($entry['mode'] ?? null) ? $entry['mode'] : 'chat';
        $caps = [$mode];

        $flags = [
            'supports_vision' => 'vision',
            'supports_function_calling' => 'tools',
            'supports_reasoning' => 'reasoning',
            'supports_prompt_caching' => 'caching',
            'supports_pdf_input' => 'pdf',
            'supports_audio_input' => 'audio',
            'supports_video_input' => 'video',
        ];

        foreach ($flags as $flag => $label) {
            if (($entry[$flag] ?? false) === true) {
                $caps[] = $label;
            }
        }

        return array_values(array_unique($caps));
    }

    /**
     * Bifrost's pricing datasheet keyed by model id, cached for a day. This is
     * the same sheet Bifrost itself uses for cost calculation; the management
     * API does not expose per-model pricing, so we read the sheet directly.
     *
     * @return array<string, array<string, mixed>>
     */
    private function bifrostPricing(): array
    {
        return Cache::remember('bifrost.pricing_datasheet', now()->addDay(), function (): array {
            try {
                $response = Http::acceptJson()->timeout(30)->get('https://getbifrost.ai/datasheet');
            } catch (Throwable) {
                return [];
            }

            $data = $response->successful() ? $response->json() : null;

            return is_array($data) ? $data : [];
        });
    }

    /** Base URL of the Bifrost gateway, without a trailing slash. */
    private function bifrostBaseUrl(): string
    {
        return rtrim((string) config('services.bifrost.base_url'), '/');
    }

    /** A pending HTTP request to Bifrost, carrying admin Basic auth (v2) or a bearer token when configured. */
    private function bifrostRequest(): PendingRequest
    {
        $request = Http::acceptJson();
        $username = (string) config('services.bifrost.admin_username');
        $password = (string) config('services.bifrost.admin_password');

        if ($username !== '' && $password !== '') {
            return $request->withBasicAuth($username, $password);
        }

        $apiKey = config('services.bifrost.api_key');

        return ! empty($apiKey) ? $request->withToken($apiKey) : $request;
    }

    /**
     * Register a model discovered from Bifrost as a Bifrost-backed upstream
     * target, public alias and pricing so its per-million cost is visible.
     */
    public function storeDiscoveredModel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model_id' => ['required', 'string', 'max:512'],
            'bifrost_key_id' => ['nullable', 'string', 'max:255'],
            'bifrost_key_name' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'input_cost' => ['required', 'numeric', 'min:0', 'max:100000'],
            'output_cost' => ['required', 'numeric', 'min:0', 'max:100000'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', 'max:50'],
            'context_window' => ['nullable', 'integer', 'min:1'],
            'max_output_tokens' => ['nullable', 'integer', 'min:1'],
        ]);

        if (UpstreamTarget::query()->where('provider_model_identifier', $data['model_id'])->exists()) {
            return response()->json(['error' => 'That model is already registered as an upstream target.'], 409);
        }

        if ($error = $this->providerMembershipError($data['model_id'])) {
            return response()->json(['error' => $error], 422);
        }

        $provider = $this->bifrostProviderAccount();

        // Public alias id drops the "provider/" prefix from the Bifrost model id.
        $aliasModelId = Str::afterLast($data['model_id'], '/');
        $displayName = ($data['name'] ?? null) ?: $data['model_id'];

        if (PublicModelAlias::query()->where('model_id', $aliasModelId)->exists()) {
            return response()->json(['error' => "A public alias '{$aliasModelId}' already exists."], 409);
        }

        $target = DB::transaction(function () use ($data, $provider, $aliasModelId, $displayName): UpstreamTarget {
            $capabilities = ! empty($data['capabilities']) ? array_values(array_unique($data['capabilities'])) : ['chat'];
            $contextWindow = $data['context_window'] ?? 128000;
            $maxOutputTokens = $data['max_output_tokens'] ?? 4096;

            $target = new UpstreamTarget();
            $target->fill([
                'provider_account_id' => $provider->id,
                'name' => $displayName,
                'environment' => $provider->environment ?? 'production',
                'region' => $provider->region,
                'base_url' => '', // Blank routes through the shared Bifrost base URL.
                'provider_model_identifier' => $data['model_id'],
                'bifrost_key_id' => $data['bifrost_key_id'] ?? null,
                'bifrost_key_name' => $data['bifrost_key_name'] ?? null,
                'capabilities' => $capabilities,
                'context_window' => $contextWindow,
                'max_input_tokens' => $contextWindow,
                'max_output_tokens' => $maxOutputTokens,
                'status' => 'active',
                'timeout_seconds' => 60,
                'created_by' => Auth::id(),
            ]);
            $target->health_status = 'unknown';
            $target->save();

            $alias = PublicModelAlias::create([
                'model_id' => $aliasModelId,
                'display_name' => $displayName,
                'description' => 'Served through Bifrost as '.$data['model_id'].'.',
                'capabilities' => $capabilities,
                'active_target_id' => $target->id,
                'status' => 'active',
                'created_by' => Auth::id(),
            ]);

            ModelAliasTargetVersion::create([
                'public_model_alias_id' => $alias->id,
                'configuration_version' => 1,
                'upstream_target_id' => $target->id,
                'effective_at' => now(),
                'changed_by' => Auth::id(),
            ]);

            ModelPricingVersion::create([
                'public_model_alias_id' => $alias->id,
                'effective_at' => $alias->created_at ?? now(),
                'currency' => 'USD',
                'input_cost_per_million_tokens' => $data['input_cost'],
                'output_cost_per_million_tokens' => $data['output_cost'],
                'created_by' => Auth::id(),
            ]);

            return $target;
        });

        return response()->json([
            'target' => [
                'public_id' => $target->public_id,
                'name' => $target->name,
                'provider_model_identifier' => $target->provider_model_identifier,
            ],
        ], 201);
    }

    /** The single provider account that represents the Bifrost gateway, created on first use. */
    private function bifrostProviderAccount(): ProviderAccount
    {
        return ProviderAccount::query()->firstOrCreate(
            ['type' => 'bifrost'],
            [
                'name' => 'Bifrost Gateway',
                'environment' => 'production',
                'status' => 'active',
                'created_by' => Auth::id(),
            ],
        );
    }

    /**
     * Send a single chat request to the target through the multi-provider
     * forwarder and capture the exchange for display. Works for every registered
     * provider type (Bifrost, Bedrock, OpenAI-compatible, Anthropic, Gemini, Cohere).
     *
     * @param  array<string, mixed>  $options  temperature, top_p, reasoning_effort, images, files
     * @return array<string, mixed>
     */
    private function runConverseTest(UpstreamTarget $target, string $prompt, int $maxTokens, array $options = []): array
    {
        $capabilities = (array) ($target->capabilities ?? []);
        $images = (array) ($options['images'] ?? []);
        $files = (array) ($options['files'] ?? []);
        $reasoning = $options['reasoning_effort'] ?? null;

        // Refuse features the model does not advertise so the test mirrors the
        // gateway's capability gating rather than silently dropping inputs.
        if ($images !== [] && ! in_array('vision', $capabilities, true)) {
            return $this->testCapabilityError($target, 'This model does not support image inputs.');
        }

        if ($files !== [] && ! in_array('pdf', $capabilities, true)) {
            return $this->testCapabilityError($target, 'This model does not support file or PDF inputs.');
        }

        if ($reasoning !== null && ! in_array('reasoning', $capabilities, true)) {
            return $this->testCapabilityError($target, 'This model does not support reasoning effort.');
        }

        $content = $this->buildTestContent($prompt, $images, $files);
        $messages = [['role' => 'user', 'content' => $content]];

        $forwardOptions = array_filter([
            'max_tokens' => $maxTokens,
            'temperature' => isset($options['temperature']) ? (float) $options['temperature'] : null,
            'top_p' => isset($options['top_p']) ? (float) $options['top_p'] : null,
            'reasoning_effort' => $reasoning,
        ], fn ($value) => $value !== null);

        // Summarise attachments for display instead of echoing base64 payloads.
        $requestPreview = $forwardOptions;
        if ($images !== [] || $files !== []) {
            $requestPreview['attachments'] = [
                'images' => count($images),
                'files' => array_map(fn ($f) => $f['filename'] ?? 'file', $files),
            ];
        }

        $base = [
            'target_name' => $target->name,
            'model_identifier' => $target->provider_model_identifier,
            'bifrost_key' => $target->bifrost_key_name ?: $target->bifrost_key_id,
            'provider_type' => $target->provider?->type,
            'endpoint' => $target->base_url,
            'request' => ['messages' => [['role' => 'user', 'content' => $prompt]], 'options' => $requestPreview],
        ];

        $result = $this->forwarder->forward($target, $messages, $forwardOptions);

        return [
            ...$base,
            'ok' => $result['ok'],
            'http_status' => $result['http_status'],
            'latency_ms' => $result['latency_ms'],
            'reply_text' => $result['reply_text'],
            'usage' => $result['usage'],
            'correlation_id' => $result['correlation_id'] ?? null,
            'response' => $result['ok']
                ? ['reply_text' => $result['reply_text'], 'usage' => $result['usage'], 'correlation_id' => $result['correlation_id'] ?? null]
                : ['error' => $result['error']],
        ];
    }

    /**
     * Builds the user message content for a test: a plain string when there are
     * no attachments, otherwise OpenAI content blocks (text + image_url + file).
     *
     * @param  list<string>  $images  data: URLs
     * @param  list<array{filename?: string, data?: string}>  $files
     * @return string|array<int, array<string, mixed>>
     */
    private function buildTestContent(string $prompt, array $images, array $files): string|array
    {
        if ($images === [] && $files === []) {
            return $prompt;
        }

        $blocks = [['type' => 'text', 'text' => $prompt]];

        foreach ($images as $url) {
            $blocks[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
        }

        foreach ($files as $file) {
            $blocks[] = [
                'type' => 'file',
                'file' => [
                    'filename' => $file['filename'] ?? 'document',
                    'file_data' => $file['data'] ?? '',
                ],
            ];
        }

        return $blocks;
    }

    /**
     * Shapes a failed capability check as a test result the UI can render.
     *
     * @return array<string, mixed>
     */
    private function testCapabilityError(UpstreamTarget $target, string $message): array
    {
        return [
            'target_name' => $target->name,
            'model_identifier' => $target->provider_model_identifier,
            'provider_type' => $target->provider?->type,
            'endpoint' => $target->base_url,
            'request' => null,
            'ok' => false,
            'http_status' => null,
            'latency_ms' => null,
            'reply_text' => null,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'correlation_id' => null,
            'response' => ['error' => $message],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function controlPlaneData(): array
    {
        $providers = ProviderAccount::query()->latest()->get()->map(fn (ProviderAccount $provider) => [
            'public_id' => $provider->public_id,
            'name' => $provider->name,
            'type' => $provider->type,
            'environment' => $provider->environment,
            'region' => $provider->region,
            'status' => $provider->status,
            'configuration_version' => $provider->configuration_version,
        ]);

        $targets = UpstreamTarget::query()
            ->with([
                'provider:id,public_id,name',
                'aliases:id,public_id,active_target_id',
                'aliases.pricingVersions' => fn ($query) => $query->latest('effective_at'),
            ])
            ->latest()
            ->get()
            ->map(function (UpstreamTarget $target) {
                $pricing = $target->aliases->first()?->pricingVersions->first();

                return [
                    'public_id' => $target->public_id,
                    'provider_name' => $target->provider?->name,
                    'name' => $target->name,
                    'environment' => $target->environment,
                    'base_url' => $target->base_url,
                    'provider_model_identifier' => $target->provider_model_identifier,
                    'bifrost_key_id' => $target->bifrost_key_id,
                    'bifrost_key_name' => $target->bifrost_key_name,
                    'capabilities' => $target->capabilities,
                    'context_window' => $target->context_window,
                    'max_input_tokens' => $target->max_input_tokens,
                    'max_output_tokens' => $target->max_output_tokens,
                    'timeout_seconds' => $target->timeout_seconds,
                    'health_status' => $target->health_status,
                    'status' => $target->status,
                    'configuration_version' => $target->configuration_version,
                    'has_alias' => $target->aliases->isNotEmpty(),
                    'input_cost' => $pricing?->input_cost_per_million_tokens,
                    'output_cost' => $pricing?->output_cost_per_million_tokens,
                    'cached_input_cost' => $pricing?->cached_input_cost_per_million_tokens,
                    'pricing_currency' => $pricing?->currency,
                ];
            });

        $aliases = PublicModelAlias::query()
            ->with(['activeTarget:id,public_id,name', 'pricingVersions' => fn ($query) => $query->latest('effective_at')])
            ->latest()
            ->get()
            ->map(fn (PublicModelAlias $alias) => [
                'public_id' => $alias->public_id,
                'model_id' => $alias->model_id,
                'display_name' => $alias->display_name,
                'description' => $alias->description,
                'capabilities' => $alias->capabilities,
                'active_target_public_id' => $alias->activeTarget?->public_id,
                'target_name' => $alias->activeTarget?->name,
                'status' => $alias->status,
                'configuration_version' => $alias->configuration_version,
                'pricing' => $alias->pricingVersions->first(),
            ]);

        $grants = ApplicationModelGrant::query()
            ->with(['application:id,public_id,name', 'alias:id,public_id,model_id'])
            ->latest()
            ->get()
            ->map(fn (ApplicationModelGrant $grant) => [
                'public_id' => $grant->public_id,
                'application_name' => $grant->application?->name,
                'model_id' => $grant->alias?->model_id,
                'enabled' => $grant->enabled,
                'configuration_version' => $grant->configuration_version,
            ]);

        return [
            'providers' => $providers,
            'targets' => $targets,
            'aliases' => $aliases,
            'grants' => $grants,
            'bedrockRegion' => (string) config('services.bifrost.bedrock_region', 'ca-central-1'),
            'bedrockGeo' => $this->bedrockRegionGeo((string) config('services.bifrost.bedrock_region', 'ca-central-1')),
        ];
    }
}

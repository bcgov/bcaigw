<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    providers: { type: Array, default: () => [] },
    targets: { type: Array, default: () => [] },
    aliases: { type: Array, default: () => [] },
    grants: { type: Array, default: () => [] },
    testResult: { type: Object, default: null },
    bedrockRegion: { type: String, default: 'ca-central-1' },
    bedrockGeo: { type: String, default: 'ca' },
});

const testForm = useForm({
    target_public_id: props.targets[0]?.public_id ?? '',
    prompt: 'Say hello in one short sentence.',
    max_tokens: 300,
    temperature: 0.5,
    top_p: 0.9,
    reasoning_effort: '',
    images: [],
    files: [],
});

const testingTarget = ref(null);
const testOutput = ref(null);

// Capabilities advertised by the target being tested, used to gate the
// reasoning / image / file inputs so they only appear when the model supports them.
const testCaps = computed(() => testingTarget.value?.capabilities ?? []);
const hasCap = (cap) => testCaps.value.includes(cap);

const openTest = (target) => {
    testForm.clearErrors();
    testForm.target_public_id = target.public_id;
    testForm.reasoning_effort = '';
    testForm.images = [];
    testForm.files = [];
    testOutput.value = null;
    testingTarget.value = target;
};

const readAsDataUrl = (file) => new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
});

const onImagesSelected = async (event) => {
    const files = Array.from(event.target.files ?? []);
    testForm.images = await Promise.all(files.map((f) => readAsDataUrl(f)));
};

const onFilesSelected = async (event) => {
    const files = Array.from(event.target.files ?? []);
    testForm.files = await Promise.all(files.map(async (f) => ({ filename: f.name, data: await readAsDataUrl(f) })));
};

const runTest = () => {
    // Drop an empty reasoning selection so the server default applies.
    const transform = (data) => ({
        ...data,
        reasoning_effort: data.reasoning_effort || null,
        images: hasCap('vision') ? data.images : [],
        files: hasCap('pdf') ? data.files : [],
    });
    testForm.transform(transform).post('/admin/model-control/test', {
        preserveScroll: true,
        preserveState: true,
        // Keep the browser URL on /admin/model-control; the POST /test route has
        // no GET handler, so letting Inertia adopt it would 404 later visits.
        preserveUrl: true,
        onSuccess: (page) => { testOutput.value = page.props.testResult ?? null; },
    });
};

const pretty = (value) => JSON.stringify(value, null, 2);

const toggleGrant = (grant) => {
    router.put(`/admin/model-control/grants/${grant.public_id}/toggle`, {}, { preserveScroll: true });
};

// --- Bifrost model discovery (pick an enabled provider, then discover its models) ---
const bifrostProviders = ref([]);
const loadingProviders = ref(false);
const selectedProvider = ref('');
const selectedKey = ref('');
const discovered = ref([]);
const hasDiscovered = ref(false);
const discovering = ref(false);
const discoverError = ref('');
const addingIds = ref([]);
const allowlistOpen = ref(false);
const manual = ref({ id: '', name: '', input_cost: '', output_cost: '', context_window: '', max_output_tokens: '' });
const addingManual = ref(false);

// Keys (accounts) of the provider currently chosen for discovery.
const providerKeys = computed(() => bifrostProviders.value.find((p) => p.name === selectedProvider.value)?.keys ?? []);

// Keep the key selection valid for the chosen provider, preferring an enabled key.
const syncSelectedKey = () => {
    const keys = providerKeys.value;
    if (!keys.length) {
        selectedKey.value = '';
        return;
    }
    if (!keys.some((k) => k.id === selectedKey.value)) {
        selectedKey.value = (keys.find((k) => k.enabled) ?? keys[0]).id;
    }
};

watch(selectedProvider, syncSelectedKey);

// The key (account) a newly added model is pinned to, sent as x-bf-api-key-id at call time.
const selectedKeyPayload = () => {
    const key = providerKeys.value.find((k) => k.id === selectedKey.value);
    return { bifrost_key_id: key?.id ?? null, bifrost_key_name: key?.name ?? null };
};

// Keys of the provider named by a target's "provider/model" identifier.
const keysForIdentifier = (identifier) => {
    const provider = String(identifier ?? '').split('/')[0];
    return bifrostProviders.value.find((p) => p.name === provider)?.keys ?? [];
};

const loadBifrostProviders = async () => {
    loadingProviders.value = true;
    discoverError.value = '';
    try {
        const { data } = await axios.get('/admin/model-control/bifrost/providers');
        const providers = data.providers ?? [];
        // Fetch each provider's keys so the UI can show the provider → keys →
        // models hierarchy and scope discovery to one account.
        await Promise.all(providers.map(async (p) => {
            try {
                const res = await axios.get('/admin/model-control/bifrost/keys', { params: { provider: p.name } });
                p.keys = res.data.keys ?? [];
            } catch {
                p.keys = [];
            }
        }));
        bifrostProviders.value = providers;
        if (!selectedProvider.value && providers.length) {
            selectedProvider.value = providers[0].name;
        }
        syncSelectedKey();
    } catch (error) {
        discoverError.value = error.response?.data?.error ?? 'Could not load Bifrost providers.';
    } finally {
        loadingProviders.value = false;
    }
};

const runDiscovery = async () => {
    if (!selectedProvider.value) {
        discoverError.value = 'Select a Bifrost provider first.';
        return;
    }
    discovering.value = true;
    discoverError.value = '';
    try {
        const { data } = await axios.get('/admin/model-control/discover', {
            params: { provider: selectedProvider.value, key: selectedKey.value || undefined },
        });
        discovered.value = (data.models ?? []).map((m) => ({
            ...m,
            input_cost: m.input_cost ?? '',
            output_cost: m.output_cost ?? '',
        }));
        allowlistOpen.value = data.allowlist_open ?? false;
        hasDiscovered.value = true;
    } catch (error) {
        discoverError.value = error.response?.data?.error ?? 'Discovery failed. Please try again.';
    } finally {
        discovering.value = false;
    }
};

onMounted(loadBifrostProviders);

const addModel = async (model) => {
    if (addingIds.value.includes(model.id)) {
        return;
    }
    if (model.input_cost === '' || model.output_cost === '') {
        discoverError.value = `Enter input and output cost per million tokens for ${model.id} before adding.`;
        return;
    }
    addingIds.value.push(model.id);
    discoverError.value = '';
    try {
        await axios.post('/admin/model-control/models', {
            model_id: model.id,
            ...selectedKeyPayload(),
            name: model.name ?? null,
            input_cost: model.input_cost,
            output_cost: model.output_cost,
            capabilities: model.capabilities ?? ['chat'],
            context_window: model.context_window ?? null,
            max_output_tokens: model.max_output_tokens ?? null,
        });
        // Drop the added model from the discovered list without re-running discovery.
        discovered.value = discovered.value.filter((m) => m.id !== model.id);
        // Partial reload keeps this component mounted, so the discovered list survives.
        router.reload({ only: ['targets', 'aliases'], preserveScroll: true });
    } catch (error) {
        discoverError.value = error.response?.data?.error ?? `Could not add ${model.id}.`;
    } finally {
        addingIds.value = addingIds.value.filter((id) => id !== model.id);
    }
};

const addManual = async () => {
    let id = manual.value.id.trim();
    if (!id) {
        discoverError.value = 'Enter a model identifier to add.';
        return;
    }
    // Prefix with the selected provider so it matches how the gateway calls Bifrost.
    if (selectedProvider.value && !id.includes('/')) {
        id = `${selectedProvider.value}/${id}`;
    }
    if (manual.value.input_cost === '' || manual.value.output_cost === '') {
        discoverError.value = 'Enter input and output cost per million tokens before adding.';
        return;
    }
    addingManual.value = true;
    discoverError.value = '';
    try {
        await axios.post('/admin/model-control/models', {
            model_id: id,
            ...selectedKeyPayload(),
            name: manual.value.name?.trim() || null,
            input_cost: manual.value.input_cost,
            output_cost: manual.value.output_cost,
            capabilities: ['chat'],
            context_window: manual.value.context_window || null,
            max_output_tokens: manual.value.max_output_tokens || null,
        });
        discovered.value = discovered.value.filter((m) => m.id !== id);
        manual.value = { id: '', name: '', input_cost: '', output_cost: '', context_window: '', max_output_tokens: '' };
        router.reload({ only: ['targets', 'aliases'], preserveScroll: true });
    } catch (error) {
        discoverError.value = error.response?.data?.error ?? `Could not add ${id}.`;
    } finally {
        addingManual.value = false;
    }
};

const formatTokens = (value) => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    if (value >= 1_000_000) {
        return `${(value / 1_000_000).toString()}M`;
    }
    if (value >= 1_000) {
        return `${Math.round(value / 1_000)}K`;
    }
    return `${value}`;
};

// Classify a Bedrock model id by its routing scope so admins can see at a glance
// whether it is a global cross-region profile, an in-region SKU, or bare on-demand.
const AWS_GEOS = ['us', 'us-gov', 'eu', 'apac', 'au', 'jp', 'sa', 'me', 'af', 'il', 'ca'];
const routingScope = (rawId) => {
    const id = String(rawId ?? '').replace(/^bedrock\//, '').toLowerCase();
    const token = id.split(/[./]/)[0] ?? '';
    const isRegionId = /^(us-gov|[a-z]{2})-[a-z]+-\d+$/.test(token);

    if (token === 'global') {
        return { label: 'Global', title: 'Global cross-region inference profile', variant: 'primary' };
    }
    if (token === 'ca' || token.startsWith('ca-') || (isRegionId && token.startsWith('ca'))) {
        return { label: 'In-region (ca)', title: `Pinned to ${token}`, variant: 'success' };
    }
    if (AWS_GEOS.includes(token) || isRegionId) {
        return { label: token, title: `Pinned to ${token}`, variant: 'secondary' };
    }
    return {
        label: 'On-demand',
        title: `Bare on-demand model id — availability in ${props.bedrockRegion} is not guaranteed; confirm on the AWS model card`,
        variant: 'warning',
    };
};

// Refine the routing pill for bare on-demand ids using the AWS model-card region
// cross-reference performed during discovery (m.region_supported: true/false/null).
const regionPill = (m) => {
    const base = routingScope(m?.id);
    if (base.label !== 'On-demand') {
        return base;
    }
    const supported = m?.region_supported;
    if (supported === true) {
        const via = m?.region_in_region
            ? `in-region in ${props.bedrockRegion}`
            : (m?.region_geo || m?.region_global ? `cross-region into ${props.bedrockRegion}` : `in ${props.bedrockRegion}`);
        return {
            label: `Available (${props.bedrockGeo})`,
            title: `AWS model card confirms this model is available ${via}`,
            variant: 'success',
        };
    }
    if (supported === false) {
        return {
            label: `Not in ${props.bedrockGeo}`,
            title: `AWS model card lists regions but not ${props.bedrockRegion} — not invokable there`,
            variant: 'danger',
        };
    }
    return {
        label: 'Unverified',
        title: `No AWS model card region table found — availability in ${props.bedrockRegion} could not be confirmed`,
        variant: 'warning',
    };
};

// --- Edit / update / deactivate ---
const CAPABILITIES = ['chat', 'structured_output', 'tool_use', 'embeddings'];

// Upstream targets
const editingTarget = ref(null);
const targetForm = useForm({
    name: '', base_url: '', provider_model_identifier: '', bifrost_key_id: '', bifrost_key_name: '', capabilities: [],
    context_window: 0, max_input_tokens: 0, max_output_tokens: 0, timeout_seconds: 60, status: 'active',
    input_cost: '', output_cost: '', cached_input_cost: '',
});
const openTargetEdit = (t) => {
    editingTarget.value = t;
    targetForm.clearErrors();
    targetForm.name = t.name;
    targetForm.base_url = t.base_url;
    targetForm.provider_model_identifier = t.provider_model_identifier;
    targetForm.bifrost_key_id = t.bifrost_key_id ?? '';
    targetForm.bifrost_key_name = t.bifrost_key_name ?? '';
    targetForm.capabilities = [...(t.capabilities ?? [])];
    targetForm.context_window = t.context_window;
    targetForm.max_input_tokens = t.max_input_tokens;
    targetForm.max_output_tokens = t.max_output_tokens;
    targetForm.timeout_seconds = t.timeout_seconds;
    targetForm.status = t.status;
    targetForm.input_cost = t.input_cost ?? '';
    targetForm.output_cost = t.output_cost ?? '';
    targetForm.cached_input_cost = t.cached_input_cost ?? '';
};
const saveTarget = () => {
    targetForm.put(`/admin/model-control/targets/${editingTarget.value.public_id}`, {
        preserveScroll: true,
        onSuccess: () => { editingTarget.value = null; },
    });
};
const setTargetStatus = (t, status) => {
    router.put(`/admin/model-control/targets/${t.public_id}/status`, { status }, { preserveScroll: true });
};

// Model aliases
const editingAlias = ref(null);
const aliasForm = useForm({ display_name: '', description: '', active_target_public_id: '', capabilities: [], status: 'active' });
const openAliasEdit = (a) => {
    editingAlias.value = a;
    aliasForm.clearErrors();
    aliasForm.display_name = a.display_name;
    aliasForm.description = a.description ?? '';
    aliasForm.active_target_public_id = a.active_target_public_id ?? '';
    aliasForm.capabilities = [...(a.capabilities ?? [])];
    aliasForm.status = a.status;
};
const saveAlias = () => {
    aliasForm.put(`/admin/model-control/aliases/${editingAlias.value.public_id}`, {
        preserveScroll: true,
        onSuccess: () => { editingAlias.value = null; },
    });
};
const setAliasStatus = (a, status) => {
    router.put(`/admin/model-control/aliases/${a.public_id}/status`, { status }, { preserveScroll: true });
};

// Pricing (append-only: saving records a new version)
const pricingAlias = ref(null);
const pricingForm = useForm({ input_cost: '', output_cost: '', cached_input_cost: '', effective_at: '' });
const openPricing = (a) => {
    pricingAlias.value = a;
    pricingForm.clearErrors();
    pricingForm.input_cost = a.pricing?.input_cost_per_million_tokens ?? '';
    pricingForm.output_cost = a.pricing?.output_cost_per_million_tokens ?? '';
    pricingForm.cached_input_cost = a.pricing?.cached_input_cost_per_million_tokens ?? '';
    pricingForm.effective_at = '';
};
const savePricing = () => {
    pricingForm.post(`/admin/model-control/aliases/${pricingAlias.value.public_id}/pricing`, {
        preserveScroll: true,
        onSuccess: () => { pricingAlias.value = null; },
    });
};

const statusBadgeClass = (status) => (status === 'active' ? 'text-bg-success' : 'text-bg-secondary');
</script>

<template>
    <AdminLayout>
        <h2 class="h3 fw-bold">Model control plane</h2>
        <p class="text-secondary">Manage providers, targets, aliases and pricing. Records are deactivated (not deleted) to preserve audit history.</p>

        <section class="mt-4">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h3 class="h5 fw-semibold mb-0">Providers</h3>
                <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="loadingProviders" @click="loadBifrostProviders">
                    <span v-if="loadingProviders" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                    Refresh
                </button>
            </div>
            <p class="text-secondary small mt-1">Providers currently enabled on the Bifrost gateway (<code>provider_status</code> active), managed in the Bifrost dashboard. Each provider has one or more keys (accounts); a model belongs to a key, which belongs to a provider. Every model you add or edit must belong to one of these providers.</p>

            <div v-if="bifrostProviders.length === 0" class="card shadow-sm mt-2">
                <div class="card-body text-secondary">{{ loadingProviders ? 'Loading…' : 'No providers enabled on Bifrost.' }}</div>
            </div>

            <div v-for="p in bifrostProviders" :key="p.name" class="card shadow-sm mt-2">
                <div class="card-header d-flex align-items-center flex-wrap gap-2">
                    <span class="fw-semibold">{{ p.name }}</span>
                    <span v-if="p.type && p.type !== p.name" class="text-secondary small">({{ p.type }})</span>
                    <span class="badge text-bg-success">{{ p.provider_status }}</span>
                    <span v-if="p.status" class="badge" :class="p.status === 'list_models_failed' ? 'text-bg-warning' : 'text-bg-light text-dark border'">{{ p.status }}</span>
                    <span v-if="p.description" class="text-secondary small ms-auto">{{ p.description }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Key (account)</th>
                                <th scope="col">Enabled</th>
                                <th scope="col">Status</th>
                                <th scope="col">Model allow-list</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!(p.keys ?? []).length"><td colspan="4" class="text-secondary py-2">No keys configured.</td></tr>
                            <tr v-for="k in (p.keys ?? [])" :key="k.id">
                                <td class="fw-semibold">
                                    <div>{{ k.name || k.id }}</div>
                                    <div v-if="k.endpoint" class="text-secondary small font-monospace">{{ k.endpoint }}</div>
                                </td>
                                <td><span class="badge" :class="k.enabled ? 'text-bg-success' : 'text-bg-secondary'">{{ k.enabled ? 'enabled' : 'disabled' }}</span></td>
                                <td>
                                    <span v-if="k.status" class="badge" :class="k.status === 'list_models_failed' ? 'text-bg-warning' : 'text-bg-light text-dark border'">{{ k.status }}</span>
                                    <span v-else class="text-secondary">—</span>
                                    <div v-if="k.description" class="text-secondary small">{{ k.description }}</div>
                                </td>
                                <td>
                                    <span v-if="k.allowlist_open" class="badge text-bg-light text-dark border">all models (*)</span>
                                    <template v-else>
                                        <span v-for="m in k.models" :key="m" class="badge text-bg-light text-dark border me-1">{{ m }}</span>
                                        <span v-if="!k.models.length" class="text-secondary">—</span>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="mt-4">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h3 class="h5 fw-semibold mb-0">Discover models from Bifrost</h3>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <select v-model="selectedProvider" class="form-select form-select-sm" style="min-width: 11rem;" :disabled="loadingProviders || bifrostProviders.length === 0">
                        <option v-if="bifrostProviders.length === 0" value="">No providers enabled on Bifrost</option>
                        <option v-for="p in bifrostProviders" :key="p.name" :value="p.name">
                            {{ p.name }}{{ p.type && p.type !== p.name ? ` (${p.type})` : '' }}
                        </option>
                    </select>
                    <select v-model="selectedKey" class="form-select form-select-sm" style="min-width: 12rem;" :disabled="loadingProviders || providerKeys.length === 0">
                        <option v-if="providerKeys.length === 0" value="">All keys</option>
                        <option v-for="k in providerKeys" :key="k.id" :value="k.id">
                            {{ k.name || k.id }}{{ k.enabled ? '' : ' (disabled)' }}
                        </option>
                    </select>
                    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="loadingProviders" @click="loadBifrostProviders">
                        <span v-if="loadingProviders" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                        Refresh providers
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm" :disabled="discovering || !selectedProvider" @click="runDiscovery">
                        <span v-if="discovering" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                        {{ discovering ? 'Discovering…' : (hasDiscovered ? 'Refresh discovery' : 'Run discovery') }}
                    </button>
                </div>
            </div>
            <p class="text-secondary small mt-1">Lists the models the selected key (account) can access in Bifrost that are not yet registered here. Set pricing per million tokens before adding. Bifrost does not publish costs, so enter them from your rate card. Configure provider credentials and the model allow-list in the Bifrost dashboard, or add a model by identifier below.</p>

            <div v-if="discoverError" class="alert alert-danger py-2">{{ discoverError }}</div>

            <div v-if="hasDiscovered" class="card shadow-sm mt-2">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Model identifier</th>
                                <th scope="col">Routing</th>
                                <th scope="col">Capabilities</th>
                                <th scope="col">Context</th>
                                <th scope="col" style="width: 8rem;">Input $/M</th>
                                <th scope="col" style="width: 8rem;">Output $/M</th>
                                <th scope="col" class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="discovered.length === 0">
                                <td colspan="7" class="text-secondary py-3">
                                    <template v-if="allowlistOpen">This provider serves all models on its key (no explicit allow-list), so there is nothing to enumerate. Add a model by identifier below.</template>
                                    <template v-else>No new models — the provider's allow-list is empty or every model is already registered. Add a model by identifier below.</template>
                                </td>
                            </tr>
                            <tr v-for="m in discovered" :key="m.id">
                                <td class="fw-semibold font-monospace">
                                    <div>{{ m.id }}</div>
                                    <div v-if="m.name && m.name !== m.id" class="text-secondary small">{{ m.name }}</div>
                                </td>
                                <td>
                                    <a
                                        v-if="m.card_url"
                                        :href="m.card_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="badge rounded-pill text-decoration-none"
                                        :class="`text-bg-${regionPill(m).variant}`"
                                        :title="`${regionPill(m).title} (opens AWS model card)`"
                                    >{{ regionPill(m).label }}</a>
                                    <span
                                        v-else
                                        class="badge rounded-pill"
                                        :class="`text-bg-${regionPill(m).variant}`"
                                        :title="regionPill(m).title"
                                    >{{ regionPill(m).label }}</span>
                                </td>
                                <td>
                                    <span v-for="cap in (m.capabilities ?? [])" :key="cap" class="badge text-bg-light text-dark border me-1">{{ cap }}</span>
                                    <span v-if="!(m.capabilities ?? []).length" class="text-secondary">—</span>
                                </td>
                                <td>{{ formatTokens(m.context_window) }}</td>
                                <td>
                                    <input v-model="m.input_cost" type="number" min="0" step="0.01" class="form-control form-control-sm" placeholder="0.00" />
                                </td>
                                <td>
                                    <input v-model="m.output_cost" type="number" min="0" step="0.01" class="form-control form-control-sm" placeholder="0.00" />
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-success btn-sm" :disabled="addingIds.includes(m.id)" @click="addModel(m)">
                                        <span v-if="addingIds.includes(m.id)" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                        {{ addingIds.includes(m.id) ? 'Adding…' : 'Add Model' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card shadow-sm mt-2">
                <div class="card-body">
                    <h4 class="h6 fw-semibold mb-1">Add a model by identifier</h4>
                    <p class="text-secondary small mb-3">Register a Bifrost-backed model directly. The selected provider is prefixed automatically, so enter just the model id (e.g. <code>global.amazon.nova-2-lite-v1:0</code>) or a full <code>provider/model</code> string.</p>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Model identifier</label>
                            <div class="input-group input-group-sm">
                                <span v-if="selectedProvider" class="input-group-text font-monospace">{{ selectedProvider }}/</span>
                                <input v-model="manual.id" type="text" class="form-control" placeholder="model-id" />
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Display name <span class="text-secondary">(optional)</span></label>
                            <input v-model="manual.name" type="text" class="form-control form-control-sm" placeholder="Friendly name" />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Input $/M</label>
                            <input v-model="manual.input_cost" type="number" min="0" step="0.01" class="form-control form-control-sm" placeholder="0.00" />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Output $/M</label>
                            <input v-model="manual.output_cost" type="number" min="0" step="0.01" class="form-control form-control-sm" placeholder="0.00" />
                        </div>
                        <div class="col-md-1">
                            <button type="button" class="btn btn-primary btn-sm w-100" :disabled="addingManual" @click="addManual">
                                <span v-if="addingManual" class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <span v-else>Add</span>
                            </button>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Context window <span class="text-secondary">(optional)</span></label>
                            <input v-model="manual.context_window" type="number" min="1" step="1" class="form-control form-control-sm" placeholder="e.g. 200000" />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Max output tokens <span class="text-secondary">(optional)</span></label>
                            <input v-model="manual.max_output_tokens" type="number" min="1" step="1" class="form-control form-control-sm" placeholder="e.g. 8192" />
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="mt-4">
            <h3 class="h5 fw-semibold">Upstream targets</h3>
            <div class="card shadow-sm mt-2">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Provider</th>
                                <th scope="col">Model identifier</th>
                                <th scope="col">Key (account)</th>
                                <th scope="col">Capabilities</th>
                                <th scope="col">Health</th>
                                <th scope="col">Status</th>
                                <th scope="col">Version</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="targets.length === 0"><td colspan="9" class="text-secondary py-3">None.</td></tr>
                            <tr v-for="t in targets" :key="t.public_id">
                                <td class="fw-semibold">{{ t.name }}</td>
                                <td>{{ t.provider_name }}</td>
                                <td>{{ t.provider_model_identifier }}</td>
                                <td>
                                    <span v-if="t.bifrost_key_id" :title="t.bifrost_key_id">{{ t.bifrost_key_name || t.bifrost_key_id }}</span>
                                    <span v-else class="text-secondary" title="Bifrost picks any enabled key whose allow-list covers the model">any</span>
                                </td>
                                <td>
                                    <span v-for="cap in (t.capabilities ?? [])" :key="cap" class="badge text-bg-light text-dark border me-1">{{ cap }}</span>
                                    <span v-if="!(t.capabilities ?? []).length" class="text-secondary">—</span>
                                </td>
                                <td>{{ t.health_status }}</td>
                                <td><span class="badge" :class="statusBadgeClass(t.status)">{{ t.status }}</span></td>
                                <td><span class="badge text-bg-light text-dark border">v{{ t.configuration_version }}</span></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-outline-primary btn-sm me-1" @click="openTest(t)">Test</button>
                                    <button type="button" class="btn btn-outline-primary btn-sm me-1" @click="openTargetEdit(t)">Edit</button>
                                    <button v-if="t.status === 'active'" type="button" class="btn btn-outline-danger btn-sm" @click="setTargetStatus(t, 'disabled')">Deactivate</button>
                                    <button v-else type="button" class="btn btn-outline-success btn-sm" @click="setTargetStatus(t, 'active')">Activate</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="mt-4">
            <h3 class="h5 fw-semibold">Model aliases</h3>
            <div class="card shadow-sm mt-2">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Model ID</th>
                                <th scope="col">Display name</th>
                                <th scope="col">Active target</th>
                                <th scope="col">Status</th>
                                <th scope="col">Input / output (per M)</th>
                                <th scope="col">Version</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="aliases.length === 0"><td colspan="7" class="text-secondary py-3">None.</td></tr>
                            <tr v-for="a in aliases" :key="a.public_id">
                                <td class="fw-semibold">{{ a.model_id }}</td>
                                <td>{{ a.display_name }}</td>
                                <td>{{ a.target_name ?? '—' }}</td>
                                <td><span class="badge" :class="statusBadgeClass(a.status)">{{ a.status }}</span></td>
                                <td>
                                    <span v-if="a.pricing">
                                        {{ a.pricing.input_cost_per_million_tokens }} / {{ a.pricing.output_cost_per_million_tokens }}
                                        {{ a.pricing.currency }}
                                    </span>
                                    <span v-else>—</span>
                                </td>
                                <td><span class="badge text-bg-light text-dark border">v{{ a.configuration_version }}</span></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-outline-primary btn-sm me-1" @click="openAliasEdit(a)">Edit</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm me-1" @click="openPricing(a)">Pricing</button>
                                    <button v-if="a.status === 'active'" type="button" class="btn btn-outline-danger btn-sm" @click="setAliasStatus(a, 'disabled')">Deactivate</button>
                                    <button v-else type="button" class="btn btn-outline-success btn-sm" @click="setAliasStatus(a, 'active')">Activate</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="mt-4">
            <h3 class="h5 fw-semibold">Application grants</h3>
            <div class="card shadow-sm mt-2">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Application</th>
                                <th scope="col">Model</th>
                                <th scope="col">State</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="grants.length === 0"><td colspan="4" class="text-secondary py-3">None.</td></tr>
                            <tr v-for="g in grants" :key="g.public_id">
                                <td class="fw-semibold">{{ g.application_name }}</td>
                                <td>{{ g.model_id }}</td>
                                <td>
                                    <span class="badge" :class="g.enabled ? 'text-bg-success' : 'text-bg-secondary'">
                                        {{ g.enabled ? 'Enabled' : 'Disabled' }}
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-outline-primary btn-sm" @click="toggleGrant(g)">
                                        {{ g.enabled ? 'Disable' : 'Enable' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Target test modal -->
        <div v-if="testingTarget" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,.5);">
            <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title">Test upstream target</h5>
                            <div class="small text-secondary">{{ testingTarget.name }} <span class="font-monospace">({{ testingTarget.provider_model_identifier }})</span></div>
                        </div>
                        <button type="button" class="btn-close" @click="testingTarget = null"></button>
                    </div>
                    <div class="modal-body">
                        <form class="row g-3" @submit.prevent="runTest">
                            <div class="col-md-4">
                                <label for="test-max-tokens" class="form-label">Max tokens</label>
                                <input id="test-max-tokens" v-model.number="testForm.max_tokens" type="number" min="1" max="4096" class="form-control" :class="{ 'is-invalid': testForm.errors.max_tokens }" />
                                <div v-if="testForm.errors.max_tokens" class="invalid-feedback">{{ testForm.errors.max_tokens }}</div>
                            </div>
                            <div class="col-md-4">
                                <label for="test-temperature" class="form-label">Temperature</label>
                                <input id="test-temperature" v-model.number="testForm.temperature" type="number" min="0" max="2" step="0.1" class="form-control" :class="{ 'is-invalid': testForm.errors.temperature }" />
                                <div v-if="testForm.errors.temperature" class="invalid-feedback">{{ testForm.errors.temperature }}</div>
                            </div>
                            <div class="col-md-4">
                                <label for="test-top-p" class="form-label">Top P</label>
                                <input id="test-top-p" v-model.number="testForm.top_p" type="number" min="0" max="1" step="0.01" class="form-control" :class="{ 'is-invalid': testForm.errors.top_p }" />
                                <div v-if="testForm.errors.top_p" class="invalid-feedback">{{ testForm.errors.top_p }}</div>
                            </div>
                            <div class="col-12">
                                <label for="test-prompt" class="form-label">Prompt</label>
                                <textarea id="test-prompt" v-model="testForm.prompt" rows="3" class="form-control" :class="{ 'is-invalid': testForm.errors.prompt }"></textarea>
                                <div v-if="testForm.errors.prompt" class="invalid-feedback">{{ testForm.errors.prompt }}</div>
                            </div>

                            <div v-if="hasCap('reasoning')" class="col-md-4">
                                <label for="test-reasoning" class="form-label">Reasoning effort</label>
                                <select id="test-reasoning" v-model="testForm.reasoning_effort" class="form-select" :class="{ 'is-invalid': testForm.errors.reasoning_effort }">
                                    <option value="">Default</option>
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                </select>
                                <div v-if="testForm.errors.reasoning_effort" class="invalid-feedback">{{ testForm.errors.reasoning_effort }}</div>
                            </div>

                            <div v-if="hasCap('vision')" class="col-md-4">
                                <label for="test-images" class="form-label">Images <span class="text-secondary">(vision)</span></label>
                                <input id="test-images" type="file" accept="image/*" multiple class="form-control" :class="{ 'is-invalid': testForm.errors.images }" @change="onImagesSelected" />
                                <div v-if="testForm.images.length" class="form-text">{{ testForm.images.length }} image(s) attached</div>
                                <div v-if="testForm.errors.images" class="invalid-feedback">{{ testForm.errors.images }}</div>
                            </div>

                            <div v-if="hasCap('pdf')" class="col-md-4">
                                <label for="test-files" class="form-label">Documents <span class="text-secondary">(PDF)</span></label>
                                <input id="test-files" type="file" accept="application/pdf,.pdf" multiple class="form-control" :class="{ 'is-invalid': testForm.errors.files }" @change="onFilesSelected" />
                                <div v-if="testForm.files.length" class="form-text">{{ testForm.files.map((f) => f.filename).join(', ') }}</div>
                                <div v-if="testForm.errors.files" class="invalid-feedback">{{ testForm.errors.files }}</div>
                            </div>
                        </form>

                        <div v-if="testOutput" class="mt-4 border-top pt-3">
                            <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
                                <span class="badge" :class="testOutput.ok ? 'text-bg-success' : 'text-bg-danger'">
                                    {{ testOutput.ok ? 'Success' : 'Failed' }}
                                </span>
                                <span v-if="testOutput.http_status" class="badge text-bg-secondary">HTTP {{ testOutput.http_status }}</span>
                                <span class="badge text-bg-light text-dark border">{{ testOutput.latency_ms }} ms</span>
                                <span v-if="testOutput.usage" class="badge text-bg-light text-dark border">
                                    tokens in {{ testOutput.usage.input_tokens }} / out {{ testOutput.usage.output_tokens }} / total {{ testOutput.usage.total_tokens }}
                                </span>
                                <span v-if="testOutput.provider_type" class="badge text-bg-info">{{ testOutput.provider_type }}</span>
                                <span v-if="testOutput.bifrost_key" class="badge text-bg-light text-dark border">key {{ testOutput.bifrost_key }}</span>
                                <span class="text-secondary small ms-auto font-monospace">{{ testOutput.model_identifier }}</span>
                            </div>

                            <div v-if="testOutput.reply_text" class="alert alert-success">
                                <div class="fw-semibold small text-uppercase mb-1">Model reply</div>
                                {{ testOutput.reply_text }}
                            </div>

                            <p class="small text-secondary mb-1 font-monospace text-break">{{ testOutput.provider_type }} · {{ testOutput.endpoint }}</p>

                            <div class="row g-3">
                                <div class="col-lg-6">
                                    <label class="form-label small fw-semibold text-uppercase">Request</label>
                                    <pre class="bg-light border rounded p-3 mb-0 small overflow-auto" style="max-height: 24rem;">{{ pretty(testOutput.request) }}</pre>
                                </div>
                                <div class="col-lg-6">
                                    <label class="form-label small fw-semibold text-uppercase">Response</label>
                                    <pre class="bg-light border rounded p-3 mb-0 small overflow-auto" style="max-height: 24rem;">{{ pretty(testOutput.response) }}</pre>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="testingTarget = null">Close</button>
                        <button type="button" class="btn btn-primary" :disabled="testForm.processing" @click="runTest">
                            <span v-if="testForm.processing" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                            {{ testForm.processing ? 'Running…' : 'Run test' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upstream target edit modal -->
        <div v-if="editingTarget" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,.5);">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit upstream target</h5>
                        <button type="button" class="btn-close" @click="editingTarget = null"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Name</label>
                                <input v-model="targetForm.name" type="text" class="form-control" :class="{ 'is-invalid': targetForm.errors.name }" />
                                <div class="invalid-feedback">{{ targetForm.errors.name }}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Model identifier</label>
                                <input v-model="targetForm.provider_model_identifier" type="text" class="form-control font-monospace" :class="{ 'is-invalid': targetForm.errors.provider_model_identifier }" />
                                <div class="invalid-feedback">{{ targetForm.errors.provider_model_identifier }}</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Key (account)</label>
                                <select
                                    v-model="targetForm.bifrost_key_id"
                                    class="form-select"
                                    @change="targetForm.bifrost_key_name = keysForIdentifier(targetForm.provider_model_identifier).find((k) => k.id === targetForm.bifrost_key_id)?.name ?? ''"
                                >
                                    <option value="">Any enabled key (Bifrost load-balances)</option>
                                    <option v-for="k in keysForIdentifier(targetForm.provider_model_identifier)" :key="k.id" :value="k.id">
                                        {{ k.name || k.id }}{{ k.enabled ? '' : ' (disabled)' }}
                                    </option>
                                    <option
                                        v-if="targetForm.bifrost_key_id && !keysForIdentifier(targetForm.provider_model_identifier).some((k) => k.id === targetForm.bifrost_key_id)"
                                        :value="targetForm.bifrost_key_id"
                                    >{{ targetForm.bifrost_key_name || targetForm.bifrost_key_id }} (not found on Bifrost)</option>
                                </select>
                                <div class="form-text">Sent to Bifrost as <code>x-bf-api-key-id</code> so calls use this account.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Base URL</label>
                                <input v-model="targetForm.base_url" type="text" class="form-control font-monospace" :class="{ 'is-invalid': targetForm.errors.base_url }" />
                                <div class="invalid-feedback">{{ targetForm.errors.base_url }}</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label d-block">Capabilities</label>
                                <div class="form-check form-check-inline" v-for="cap in CAPABILITIES" :key="cap">
                                    <input class="form-check-input" type="checkbox" :id="`tcap-${cap}`" :value="cap" v-model="targetForm.capabilities" />
                                    <label class="form-check-label" :for="`tcap-${cap}`">{{ cap }}</label>
                                </div>
                                <div v-if="targetForm.errors.capabilities" class="text-danger small">{{ targetForm.errors.capabilities }}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Context window</label>
                                <input v-model.number="targetForm.context_window" type="number" min="1" class="form-control" :class="{ 'is-invalid': targetForm.errors.context_window }" />
                                <div class="invalid-feedback">{{ targetForm.errors.context_window }}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Max input tokens</label>
                                <input v-model.number="targetForm.max_input_tokens" type="number" min="1" class="form-control" :class="{ 'is-invalid': targetForm.errors.max_input_tokens }" />
                                <div class="invalid-feedback">{{ targetForm.errors.max_input_tokens }}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Max output tokens</label>
                                <input v-model.number="targetForm.max_output_tokens" type="number" min="1" class="form-control" :class="{ 'is-invalid': targetForm.errors.max_output_tokens }" />
                                <div class="invalid-feedback">{{ targetForm.errors.max_output_tokens }}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Timeout (s)</label>
                                <input v-model.number="targetForm.timeout_seconds" type="number" min="1" max="600" class="form-control" :class="{ 'is-invalid': targetForm.errors.timeout_seconds }" />
                                <div class="invalid-feedback">{{ targetForm.errors.timeout_seconds }}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select v-model="targetForm.status" class="form-select">
                                    <option value="active">active</option>
                                    <option value="disabled">disabled</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <hr class="my-1" />
                                <label class="form-label d-block mb-1">Cost per million tokens ({{ editingTarget?.pricing_currency || 'USD' }})</label>
                                <p v-if="editingTarget && !editingTarget.has_alias" class="text-secondary small mb-2">
                                    This target has no public model alias yet, so pricing cannot be recorded here. Create an alias for it first.
                                </p>
                                <p v-else class="text-secondary small mb-2">
                                    Pricing is append-only. Changing a cost records a new pricing version for the alias this target backs.
                                </p>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Input cost</label>
                                <input v-model="targetForm.input_cost" type="number" min="0" step="0.01" class="form-control" :class="{ 'is-invalid': targetForm.errors.input_cost }" :disabled="editingTarget && !editingTarget.has_alias" placeholder="0.00" />
                                <div class="invalid-feedback">{{ targetForm.errors.input_cost }}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Output cost</label>
                                <input v-model="targetForm.output_cost" type="number" min="0" step="0.01" class="form-control" :class="{ 'is-invalid': targetForm.errors.output_cost }" :disabled="editingTarget && !editingTarget.has_alias" placeholder="0.00" />
                                <div class="invalid-feedback">{{ targetForm.errors.output_cost }}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Cached input cost</label>
                                <input v-model="targetForm.cached_input_cost" type="number" min="0" step="0.01" class="form-control" :class="{ 'is-invalid': targetForm.errors.cached_input_cost }" :disabled="editingTarget && !editingTarget.has_alias" placeholder="optional" />
                                <div class="invalid-feedback">{{ targetForm.errors.cached_input_cost }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="editingTarget = null">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="targetForm.processing" @click="saveTarget">Save changes</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Model alias edit modal -->
        <div v-if="editingAlias" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,.5);">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit model alias</h5>
                        <button type="button" class="btn-close" @click="editingAlias = null"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Display name</label>
                                <input v-model="aliasForm.display_name" type="text" class="form-control" :class="{ 'is-invalid': aliasForm.errors.display_name }" />
                                <div class="invalid-feedback">{{ aliasForm.errors.display_name }}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Active target</label>
                                <select v-model="aliasForm.active_target_public_id" class="form-select" :class="{ 'is-invalid': aliasForm.errors.active_target_public_id }">
                                    <option value="">— none —</option>
                                    <option v-for="t in targets" :key="t.public_id" :value="t.public_id">{{ t.name }} ({{ t.provider_model_identifier }})</option>
                                </select>
                                <div class="invalid-feedback">{{ aliasForm.errors.active_target_public_id }}</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea v-model="aliasForm.description" rows="2" class="form-control" :class="{ 'is-invalid': aliasForm.errors.description }"></textarea>
                                <div class="invalid-feedback">{{ aliasForm.errors.description }}</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label d-block">Capabilities</label>
                                <div class="form-check form-check-inline" v-for="cap in CAPABILITIES" :key="cap">
                                    <input class="form-check-input" type="checkbox" :id="`acap-${cap}`" :value="cap" v-model="aliasForm.capabilities" />
                                    <label class="form-check-label" :for="`acap-${cap}`">{{ cap }}</label>
                                </div>
                                <div v-if="aliasForm.errors.capabilities" class="text-danger small">{{ aliasForm.errors.capabilities }}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select v-model="aliasForm.status" class="form-select">
                                    <option value="active">active</option>
                                    <option value="disabled">disabled</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="editingAlias = null">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="aliasForm.processing" @click="saveAlias">Save changes</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pricing modal (append-only new version) -->
        <div v-if="pricingAlias" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,.5);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Update pricing — {{ pricingAlias.model_id }}</h5>
                        <button type="button" class="btn-close" @click="pricingAlias = null"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-secondary small">Pricing is append-only. Saving records a new version that supersedes the current one.</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Input $/M</label>
                                <input v-model="pricingForm.input_cost" type="number" min="0" step="0.01" class="form-control" :class="{ 'is-invalid': pricingForm.errors.input_cost }" />
                                <div class="invalid-feedback">{{ pricingForm.errors.input_cost }}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Output $/M</label>
                                <input v-model="pricingForm.output_cost" type="number" min="0" step="0.01" class="form-control" :class="{ 'is-invalid': pricingForm.errors.output_cost }" />
                                <div class="invalid-feedback">{{ pricingForm.errors.output_cost }}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Cached input $/M <span class="text-secondary">(optional)</span></label>
                                <input v-model="pricingForm.cached_input_cost" type="number" min="0" step="0.01" class="form-control" :class="{ 'is-invalid': pricingForm.errors.cached_input_cost }" />
                                <div class="invalid-feedback">{{ pricingForm.errors.cached_input_cost }}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Effective at <span class="text-secondary">(optional)</span></label>
                                <input v-model="pricingForm.effective_at" type="datetime-local" class="form-control" :class="{ 'is-invalid': pricingForm.errors.effective_at }" />
                                <div class="invalid-feedback">{{ pricingForm.errors.effective_at }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="pricingAlias = null">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="pricingForm.processing" @click="savePricing">Record version</button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

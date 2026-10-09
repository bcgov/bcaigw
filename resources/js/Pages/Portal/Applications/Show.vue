<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    application: { type: Object, required: true },
    grantedModels: { type: Array, default: () => [] },
    environments: { type: Array, default: () => [] },
    catalogModels: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    apiUsage: { type: Object, default: () => ({}) },
});

const statusLabel = (status) => status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

const numberFmt = (value) => Number(value || 0).toLocaleString();

// Colour the usage bar/badge by how close the application is to its monthly budget.
const usageVariant = (percent) => {
    if (percent === null || percent === undefined) return 'secondary';
    if (percent >= 90) return 'danger';
    if (percent >= 70) return 'warning';
    return 'success';
};

const barWidth = (percent) => `${Math.min(100, Math.max(0, Number(percent || 0)))}%`;

const currencyFmt = (value, currency) => new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: currency || 'CAD',
}).format(Number(value || 0));

const clientId = computed(() => props.application.api_directory_client_id || 'YOUR_API_DIRECTORY_CLIENT_ID');
const applicationId = computed(() => props.application.gateway_key || 'YOUR_BCAIGW_APPLICATION_ID');
const tokenEndpoint = computed(() => props.apiUsage.token_endpoint || 'APP_API_TOKEN_ENDPOINT');
const audience = computed(() => props.apiUsage.audience || 'APP_API_AUDIENCE');
const gatewayBaseUrl = computed(() => (props.apiUsage.base_url || 'https://bcaigw.gov.bc.ca').replace(/\/+$/, ''));
const exampleModelId = computed(() => props.grantedModels[0]?.model_id || 'GRANTED_MODEL_ID');
const exampleMaxTokens = computed(() => {
    const first = props.grantedModels[0];
    return (first && modelGeneratesText(first) && first.max_output_tokens) || 512;
});

// Only chat/completion models generate output tokens. Embedding, rerank and
// image-generation models report a sentinel limit (e.g. 1) that is meaningless
// as a max_tokens value, so the limit is only surfaced for text models.
const modelGeneratesText = (model) => (model?.capabilities || []).includes('chat');

const tokenRequestExample = computed(() => `curl -X POST '${tokenEndpoint.value}' \\
  -H 'Content-Type: application/x-www-form-urlencoded' \\
  -d 'grant_type=client_credentials' \\
  -d 'client_id=${clientId.value}' \\
  -d 'client_secret=YOUR_API_DIRECTORY_CLIENT_SECRET'`);

const invokeRequestExample = computed(() => `curl -X POST '${gatewayBaseUrl.value}/api/gateway/v1/chat/completions' \\
  -H 'Authorization: Bearer ACCESS_TOKEN_FROM_STEP_1' \\
  -H 'X-BCAIGW-Application-Id: ${applicationId.value}' \\
  -H 'Content-Type: application/json' \\
  -d '{"model":"${exampleModelId.value}","environment":"development","max_tokens":${exampleMaxTokens.value},"messages":[{"role":"user","content":"Hello"}]}'`);

// Advanced call: sampling controls, reasoning effort, and multimodal content
// blocks (image + PDF), mirroring the admin test console. Optional fields are
// only honoured when the model's capabilities allow them.
const advancedRequestBody = computed(() => JSON.stringify({
    model: exampleModelId.value,
    environment: 'development',
    max_tokens: 512,
    temperature: 0.5,
    top_p: 0.9,
    reasoning_effort: 'medium',
    messages: [
        {
            role: 'user',
            content: [
                { type: 'text', text: 'Compare the two images and summarise both documents.' },
                { type: 'image_url', image_url: { url: '<IMAGE_1_DATA_URL_FROM_CONVERTER>' } },
                { type: 'image_url', image_url: { url: '<IMAGE_2_DATA_URL_FROM_CONVERTER>' } },
                { type: 'file', file: { filename: 'report-1.pdf', file_data: '<FILE_1_DATA_URL_FROM_CONVERTER>' } },
                { type: 'file', file: { filename: 'report-2.pdf', file_data: '<FILE_2_DATA_URL_FROM_CONVERTER>' } },
            ],
        },
    ],
}, null, 2));

const advancedRequestExample = computed(() => `curl -X POST '${gatewayBaseUrl.value}/api/gateway/v1/chat/completions' \\
  -H 'Authorization: Bearer ACCESS_TOKEN_FROM_STEP_1' \\
  -H 'X-BCAIGW-Application-Id: ${applicationId.value}' \\
  -H 'Content-Type: application/json' \\
  -d '${advancedRequestBody.value}'`);

// Image generation uses a separate OpenAI-compatible endpoint and a text prompt
// instead of chat messages. Prefer a granted model that advertises the
// image_generation capability for the example.
const imageModelId = computed(
    () => props.grantedModels.find((m) => (m.capabilities || []).includes('image_generation'))?.model_id || 'IMAGE_MODEL_ID',
);

const imageRequestBody = computed(() => JSON.stringify({
    model: imageModelId.value,
    environment: 'development',
    prompt: 'A watercolour painting of the BC legislature at sunset',
    n: 1,
    size: '1024x1024',
    response_format: 'b64_json',
}, null, 2));

const imageRequestExample = computed(() => `curl -X POST '${gatewayBaseUrl.value}/api/gateway/v1/images/generations' \\
  -H 'Authorization: Bearer ACCESS_TOKEN_FROM_STEP_1' \\
  -H 'X-BCAIGW-Application-Id: ${applicationId.value}' \\
  -H 'Content-Type: application/json' \\
  -d '${imageRequestBody.value}'`);

const submitForm = useForm({ status_version: props.application.status_version, note: '' });
const submitApplication = () => submitForm.post(`/portal/applications/${props.application.public_id}/submit`);

// Promotion request (development -> test -> production). The environment card
// supplies the source environment; the backend resolves the next target.
const promoteForm = useForm({ from_environment: '' });
const promoteEnvironment = (env) => {
    promoteForm.from_environment = env.environment;
    promoteForm.post(`/portal/applications/${props.application.public_id}/promote`, { preserveScroll: true });
};

// Development model editor: change which models/capabilities development grants.
// Submitting creates a change request that needs admin approval; development
// keeps serving its current models until then.
const developmentEnv = computed(() => props.environments.find((e) => e.environment === 'development'));
const showModelEditor = ref(false);
const modelSelection = ref({});

const openModelEditor = () => {
    const current = new Map((developmentEnv.value?.models || []).map((m) => [m.alias_public_id, m]));
    const selection = {};
    for (const model of props.catalogModels) {
        const grant = current.get(model.public_id);
        selection[model.public_id] = {
            selected: !!grant,
            capabilities: grant ? [...(grant.capabilities || [])] : [...(model.capabilities || [])],
        };
    }
    modelSelection.value = selection;
    showModelEditor.value = true;
};

const selectedModelCount = computed(
    () => Object.values(modelSelection.value).filter((s) => s.selected).length,
);

const modelChangeForm = useForm({ models: [] });
const submitModelChange = () => {
    modelChangeForm.models = props.catalogModels
        .filter((m) => modelSelection.value[m.public_id]?.selected)
        .map((m) => ({
            alias_public_id: m.public_id,
            capabilities: modelSelection.value[m.public_id].capabilities,
        }));
    modelChangeForm.post(`/portal/applications/${props.application.public_id}/models`, {
        preserveScroll: true,
        onSuccess: () => { showModelEditor.value = false; },
    });
};

// --- Base64 / data URL helper (for image_url and file content blocks) ---
const b64File = ref(null);
const b64DataUrl = ref('');
const b64Copied = ref(false);

const onB64FileSelected = (event) => {
    const file = event.target.files?.[0];
    b64DataUrl.value = '';
    b64Copied.value = false;
    if (!file) { b64File.value = null; return; }
    b64File.value = { name: file.name, type: file.type, size: file.size };
    const reader = new FileReader();
    reader.onload = () => { b64DataUrl.value = reader.result; };
    reader.readAsDataURL(file);
};

const copyDataUrl = async () => {
    if (!b64DataUrl.value) return;
    await navigator.clipboard.writeText(b64DataUrl.value);
    b64Copied.value = true;
    setTimeout(() => { b64Copied.value = false; }, 1500);
};

// --- Test application console ---
const showTester = ref(false);
const test = ref({
    model: props.grantedModels[0]?.model_id || '',
    environment: props.environments[0]?.environment || 'development',
    token: '',
    message: 'Hello! Give me a one sentence greeting.',
});
const testLoading = ref(false);
const testError = ref('');
const testRaw = ref('');
const testContent = ref('');
const testStatus = ref(null);

const runTest = async () => {
    testError.value = '';
    testRaw.value = '';
    testContent.value = '';
    testStatus.value = null;

    if (!test.value.token.trim()) {
        testError.value = 'A bearer token is required.';
        return;
    }
    if (!test.value.model) {
        testError.value = 'Select a model.';
        return;
    }

    testLoading.value = true;
    try {
        const body = {
            model: test.value.model,
            messages: [{ role: 'user', content: test.value.message }],
        };
        if (test.value.environment) {
            body.environment = test.value.environment;
        }

        const res = await fetch('/api/gateway/v1/chat/completions', {
            method: 'POST',
            headers: {
                Authorization: `Bearer ${test.value.token.trim()}`,
                'X-BCAIGW-Application-Id': props.application.gateway_key || '',
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify(body),
        });

        testStatus.value = res.status;
        const text = await res.text();
        let data = null;
        try {
            data = JSON.parse(text);
            testRaw.value = JSON.stringify(data, null, 2);
        } catch {
            testRaw.value = text;
        }

        if (data?.choices) {
            testContent.value = data.choices?.[0]?.message?.content ?? '';
        }
        if (!res.ok) {
            testError.value = data?.error?.message || data?.message || `Request failed (HTTP ${res.status}).`;
        }
    } catch (e) {
        testError.value = e?.message || 'Request failed. Check the console for details.';
    } finally {
        testLoading.value = false;
    }
};
</script>

<template>
    <PortalLayout>
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div>
                <Link href="/portal/applications" class="text-bc-blue">Back to applications</Link>
                <h2 class="h3 fw-bold mt-2 mb-1">{{ application.name }}</h2>
                <p class="text-secondary mb-0">{{ application.ministry_organization }}</p>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill">
                    {{ statusLabel(application.status) }}
                </span>
                <Link
                    v-if="can.edit"
                    :href="`/portal/applications/${application.public_id}/edit`"
                    class="btn btn-outline-primary btn-sm"
                >
                    Edit
                </Link>
            </div>
        </div>

        <section class="card shadow-sm mt-4">
            <div class="card-body">
                <h3 class="h6 fw-bold mb-1">Environments</h3>
                <p class="text-secondary small mb-3">
                    Each environment has its own approval, budgets, traffic tracking and models. Promote a running
                    environment to copy its selected models to the next stage for review.
                </p>
                <p v-if="environments.length === 0" class="text-secondary mb-0">
                    Environments appear here once the application is approved and activated.
                </p>
                <div v-else class="row g-3">
                    <div v-for="env in environments" :key="env.environment" class="col-lg-4">
                        <div class="card h-100 border">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h4 class="h6 fw-bold mb-0">{{ env.label }}</h4>
                                    <div class="d-flex align-items-center gap-1 flex-wrap justify-content-end">
                                        <span
                                            class="badge rounded-pill"
                                            :class="env.status === 'active' ? 'text-bg-success' : (env.status === 'suspended' ? 'text-bg-danger' : 'text-bg-secondary')"
                                        >{{ statusLabel(env.status) }}<template v-if="(env.pending_change || env.pending_promotion) && env.status === 'active'"> (current)</template></span>
                                        <span v-if="env.pending_change || env.pending_promotion" class="badge rounded-pill text-bg-warning">Changes pending approval</span>
                                    </div>
                                </div>

                                <div v-if="env.pending_promotion" class="alert alert-info py-2 px-2 small mb-2">
                                    Promotion from {{ statusLabel(env.pending_promotion.from) }}
                                    ({{ env.pending_promotion.model_count }} models) awaiting admin approval.
                                </div>

                                <div v-if="env.pending_change" class="alert alert-info py-2 px-2 small mb-2">
                                    Model change ({{ env.pending_change.model_count }} models) awaiting admin approval.
                                    The current models below keep serving until then.
                                </div>

                                <div class="small mb-1">
                                    <div class="d-flex justify-content-between text-secondary">
                                        <span>{{ numberFmt(env.usage.tokens_used) }}<template v-if="env.usage.token_budget"> / {{ numberFmt(env.usage.token_budget) }}</template> tokens</span>
                                        <span v-if="env.usage.token_percent !== null && env.usage.token_percent !== undefined"
                                              class="badge rounded-pill" :class="`text-bg-${usageVariant(env.usage.token_percent)}`">{{ env.usage.token_percent }}%</span>
                                    </div>
                                    <div class="progress mt-1" style="height: 6px;">
                                        <div class="progress-bar" :class="`bg-${usageVariant(env.usage.token_percent)}`" :style="{ width: barWidth(env.usage.token_percent) }"></div>
                                    </div>
                                </div>

                                <div class="small mb-2">
                                    <div class="d-flex justify-content-between text-secondary">
                                        <span>{{ currencyFmt(env.usage.cost, env.usage.currency) }}<template v-if="env.usage.cost_budget"> / {{ currencyFmt(env.usage.cost_budget, env.usage.currency) }}</template></span>
                                        <span v-if="env.usage.cost_percent !== null && env.usage.cost_percent !== undefined"
                                              class="badge rounded-pill" :class="`text-bg-${usageVariant(env.usage.cost_percent)}`">{{ env.usage.cost_percent }}%</span>
                                    </div>
                                    <div class="progress mt-1" style="height: 6px;">
                                        <div class="progress-bar" :class="`bg-${usageVariant(env.usage.cost_percent)}`" :style="{ width: barWidth(env.usage.cost_percent) }"></div>
                                    </div>
                                </div>

                                <div class="small text-secondary mb-2">
                                    {{ env.models.length }} model{{ env.models.length === 1 ? '' : 's' }}
                                    · {{ numberFmt(env.budgets.rate_limit_per_minute) }} req/min
                                </div>
                                <ul v-if="env.models.length" class="list-unstyled small mb-3">
                                    <li v-for="model in env.models" :key="model.model_id" class="text-break d-flex justify-content-between align-items-center gap-2">
                                        <code>{{ model.model_id }}</code>
                                        <span v-if="model.max_output_tokens && modelGeneratesText(model)" class="badge text-bg-light border text-secondary flex-shrink-0" title="Maximum output tokens allowed for this model">
                                            max {{ numberFmt(model.max_output_tokens) }} tok
                                        </span>
                                    </li>
                                </ul>

                                <div class="mt-auto">
                                    <button
                                        v-if="env.can_manage_models"
                                        type="button"
                                        class="btn btn-outline-secondary btn-sm w-100 mb-2"
                                        @click="openModelEditor"
                                    >
                                        Change models
                                    </button>
                                    <button
                                        v-if="env.can_promote"
                                        type="button"
                                        class="btn btn-outline-primary btn-sm w-100"
                                        :disabled="promoteForm.processing"
                                        @click="promoteEnvironment(env)"
                                    >
                                        Promote to {{ env.next_label }}
                                    </button>
                                    <p v-else-if="env.next_environment && env.status !== 'active'" class="text-secondary small mb-0">
                                        Activate this environment before promoting.
                                    </p>
                                    <!-- <p v-else-if="env.next_environment && env.next_up_to_date" class="text-secondary small mb-0">
                                        {{ env.next_label }} already matches these models — nothing to promote.
                                    </p> -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section v-if="showModelEditor" class="card shadow-sm mt-4 border-top border-4 border-secondary-subtle">
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
                    <div>
                        <h3 class="h6 fw-bold mb-1">Change development models</h3>
                        <p class="text-secondary small mb-0">
                            Choose the models and capabilities for development. Submitting sends the change for admin
                            approval; development keeps serving its current models until approved.
                        </p>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0" @click="showModelEditor = false">Close</button>
                </div>

                <p v-if="catalogModels.length === 0" class="text-secondary mb-0">No models are available in the catalogue.</p>
                <div v-else class="row g-2">
                    <div v-for="model in catalogModels" :key="model.public_id" class="col-md-6">
                        <div class="border rounded p-2 h-100" :class="{ 'border-primary bg-primary-subtle': modelSelection[model.public_id]?.selected }">
                            <div class="form-check">
                                <input
                                    :id="`m-${model.public_id}`"
                                    v-model="modelSelection[model.public_id].selected"
                                    type="checkbox"
                                    class="form-check-input"
                                >
                                <label :for="`m-${model.public_id}`" class="form-check-label">
                                    <span class="fw-semibold">{{ model.display_name }}</span>
                                    <code class="small text-break d-block">{{ model.model_id }}</code>
                                    <span v-if="model.max_output_tokens && modelGeneratesText(model)" class="text-secondary small">Max output {{ numberFmt(model.max_output_tokens) }} tokens</span>
                                </label>
                            </div>
                            <div v-if="modelSelection[model.public_id]?.selected && model.capabilities.length" class="mt-1 ms-4 d-flex flex-wrap gap-2">
                                <div v-for="cap in model.capabilities" :key="cap" class="form-check">
                                    <input
                                        :id="`m-${model.public_id}-${cap}`"
                                        v-model="modelSelection[model.public_id].capabilities"
                                        :value="cap"
                                        type="checkbox"
                                        class="form-check-input"
                                    >
                                    <label :for="`m-${model.public_id}-${cap}`" class="form-check-label small">{{ cap }}</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-top pt-3 mt-3 d-flex align-items-center gap-3">
                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        :disabled="modelChangeForm.processing || selectedModelCount === 0"
                        @click="submitModelChange"
                    >
                        Submit change for approval
                    </button>
                    <span class="text-secondary small">{{ selectedModelCount }} model{{ selectedModelCount === 1 ? '' : 's' }} selected</span>
                </div>
            </div>
        </section>

        <div class="row g-4 mt-1">
            <div class="col-lg-6">
                <section class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h5 fw-bold">Details</h3>
                        <dl class="row small mb-0 mt-3">
                            <dt class="col-5 text-secondary fw-normal">Purpose</dt>
                            <dd class="col-7 text-end">{{ application.purpose_use_case }}</dd>
                            <dt class="col-5 text-secondary fw-normal">Data classification</dt>
                            <dd class="col-7 text-end">{{ statusLabel(application.data_classification) }}</dd>
                            <dt class="col-5 text-secondary fw-normal">API directory client ID</dt>
                            <dd class="col-7 text-end"><code class="text-break">{{ application.api_directory_client_id || '—' }}</code></dd>
                            <dt class="col-5 text-secondary fw-normal">BCAIGW application ID</dt>
                            <dd class="col-7 text-end"><code class="text-break">{{ application.gateway_key || '—' }}</code></dd>
                            <dt class="col-5 text-secondary fw-normal">Primary contact</dt>
                            <dd class="col-7 text-end">{{ application.primary_contact_name }} &lt;{{ application.primary_contact_email }}&gt;</dd>
                        </dl>

                        <div v-if="can.submit" class="border-top pt-3 mt-3">
                            <button type="button" :disabled="submitForm.processing" class="btn btn-primary" @click="submitApplication">
                                Submit for review
                            </button>
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-lg-6">
                <section class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h5 fw-bold">Granted models</h3>
                        <p class="text-secondary small mb-2">Use the model ID as the <code>model</code> value when calling the gateway.</p>
                        <p v-if="grantedModels.length === 0" class="text-secondary mb-0">No models granted yet.</p>
                        <ul v-else class="list-group list-group-flush">
                            <li v-for="model in grantedModels" :key="model.public_id" class="list-group-item px-0">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="fw-semibold">{{ model.display_name }}</div>
                                    <span v-if="model.max_output_tokens && modelGeneratesText(model)" class="badge text-bg-light border text-secondary flex-shrink-0" title="Maximum output tokens allowed for this model">
                                        max {{ numberFmt(model.max_output_tokens) }} tok
                                    </span>
                                </div>
                                <code class="small text-break">{{ model.model_id }}</code>
                                <div v-if="(model.capabilities || []).length" class="mt-1">
                                    <span v-for="cap in model.capabilities" :key="cap" class="badge text-bg-light border me-1">{{ cap }}</span>
                                </div>
                            </li>
                        </ul>
                    </div>
                </section>
            </div>
        </div>

        <section v-if="can.viewAdminTools" class="card shadow-sm mt-4">
            <div class="card-body">
                <h3 class="h5 fw-bold">Lifecycle history</h3>
                <ul class="list-group list-group-flush">
                    <li v-for="entry in application.lifecycle_history" :key="entry.id" class="list-group-item d-flex justify-content-between px-0">
                        <span>{{ entry.from_status ? statusLabel(entry.from_status) + ' → ' : '' }}{{ statusLabel(entry.to_status) }}</span>
                        <span class="text-secondary">{{ entry.actor?.name }} · {{ new Date(entry.created_at).toLocaleString() }}</span>
                    </li>
                    <li v-if="!application.lifecycle_history || application.lifecycle_history.length === 0" class="list-group-item text-secondary px-0">No history yet.</li>
                </ul>
            </div>
        </section>

        <template v-if="can.viewAdminTools">
        <section class="card shadow-sm mt-4 border-top border-4 border-primary-subtle">
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                    <div>
                        <h3 class="h5 fw-bold mb-1">Test application</h3>
                        <p class="text-secondary small mb-0">
                            Send a live request to BCAIGW using one of your approved models and a bearer token you obtained
                            from the API Directory. The call is made as this application
                            (<code>{{ applicationId }}</code>).
                        </p>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm flex-shrink-0" @click="showTester = !showTester">
                        {{ showTester ? 'Close' : 'Open test console' }}
                    </button>
                </div>

                <div v-if="showTester" class="border-top pt-3 mt-3">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Model</label>
                            <select v-model="test.model" class="form-select form-select-sm">
                                <option v-if="grantedModels.length === 0" value="">No granted models</option>
                                <option v-for="model in grantedModels" :key="model.public_id" :value="model.model_id">
                                    {{ model.display_name }} ({{ model.model_id }})
                                </option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Environment</label>
                            <select v-model="test.environment" class="form-select form-select-sm">
                                <option value="">(none)</option>
                                <option v-for="env in environments" :key="env.environment" :value="env.environment">{{ env.label }}</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Bearer token</label>
                            <textarea
                                v-model="test.token"
                                rows="2"
                                class="form-control form-control-sm font-monospace"
                                placeholder="Paste the access token from the API Directory (without the 'Bearer ' prefix)"
                            ></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Message</label>
                            <textarea v-model="test.message" rows="3" class="form-control form-control-sm"></textarea>
                        </div>
                        <div class="col-12">
                            <button type="button" class="btn btn-primary btn-sm" :disabled="testLoading" @click="runTest">
                                <span v-if="testLoading" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                {{ testLoading ? 'Sending…' : 'Send request' }}
                            </button>
                        </div>
                    </div>

                    <div v-if="testError" class="alert alert-danger mt-3 mb-0 py-2 small">{{ testError }}</div>

                    <div v-if="testContent" class="mt-3">
                        <label class="form-label small fw-semibold mb-1">Response · choices[0].message.content</label>
                        <div class="border rounded p-3 bg-body-tertiary" style="white-space: pre-wrap;">{{ testContent }}</div>
                    </div>

                    <div v-if="testRaw" class="mt-3">
                        <label class="form-label small fw-semibold mb-1">
                            Raw response
                            <span v-if="testStatus !== null" class="badge ms-1" :class="testStatus >= 200 && testStatus < 300 ? 'text-bg-success' : 'text-bg-danger'">HTTP {{ testStatus }}</span>
                        </label>
                        <pre class="bg-body-tertiary border rounded p-3 small mb-0" style="max-height: 20rem; overflow: auto;"><code>{{ testRaw }}</code></pre>
                    </div>
                </div>
            </div>
        </section>

        <section class="card shadow-sm mt-4 border-top border-4 border-primary-subtle">
            <div class="card-body">
                <h3 class="h5 fw-bold">Calling an LLM through BCAIGW</h3>
                <p class="text-secondary small">
                    Your application authenticates with the BC Gov API Directory, then presents that token to the
                    BCAIGW API along with your BCAIGW application ID. BCAIGW verifies the token, confirms it was
                    issued for this application, and enforces your application's limits before forwarding the
                    request to the model.
                </p>

                <ol class="mb-0">
                    <li class="mb-4">
                        <p class="fw-semibold mb-1">1. Get an access token from the API Directory</p>
                        <p class="text-secondary small mb-2">
                            Exchange your API Directory client ID
                            (<code>{{ clientId }}</code>) and its secret for a bearer token using the
                            <code>client_credentials</code> grant.
                        </p>
                        <pre class="bg-body-tertiary border rounded p-3 small mb-0"><code>{{ tokenRequestExample }}</code></pre>
                    </li>

                    <li class="mb-4">
                        <p class="fw-semibold mb-1">2. Call the BCAIGW API with the token as a bearer</p>
                        <p class="text-secondary small mb-2">
                            BCAIGW validates the JWT the same way the PDEX application does: it verifies the signature
                            against the issuer's JWKS and confirms the token's audience matches
                            <code>{{ audience }}</code>. Include your BCAIGW application ID
                            (<code>{{ applicationId }}</code>) in the <code>X-BCAIGW-Application-Id</code> header so the
                            gateway knows which application you are calling as.
                        </p>
                        <pre class="bg-body-tertiary border rounded p-3 small mb-0"><code>{{ invokeRequestExample }}</code></pre>
                    </li>

                    <li class="mb-4">
                        <p class="fw-semibold mb-1">2b. Optional parameters (sampling, reasoning, files & images)</p>
                        <p class="text-secondary small mb-2">
                            The endpoint is OpenAI-compatible. Alongside <code>messages</code> you may send:
                        </p>
                        <ul class="text-secondary small mb-2">
                            <li><code>max_tokens</code> — integer, 1–32000. Must not exceed the model's <strong>max output tokens</strong> (shown next to each model above); higher values are rejected by the upstream model.</li>
                            <li><code>temperature</code> — 0–2. <code>top_p</code> — 0–1.</li>
                            <li><code>reasoning_effort</code> — <code>low</code>, <code>medium</code> or <code>high</code> (models with the <strong>reasoning</strong> capability).</li>
                            <li><strong>Images</strong> — add an <code>image_url</code> content block whose <code>url</code> is the full data URL from the converter below (models with the <strong>vision</strong> capability).</li>
                            <li><strong>Documents</strong> — add a <code>file</code> content block with <code>filename</code> and a <code>file_data</code> set to the full data URL from the converter (models with the <strong>pdf</strong> capability).</li>
                            <li><strong>Multiple images / documents</strong> — repeat the <code>image_url</code> and/or <code>file</code> blocks in the same <code>content</code> array (as many as you need). Each file is processed in the order listed; give every <code>file</code> a distinct <code>filename</code>.</li>
                        </ul>
                        <p class="text-secondary small mb-2">
                            Paste the converter output <strong>as-is</strong> — it already starts with
                            <code>data:&lt;mime&gt;;base64,</code>. Do not add another <code>data:...;base64,</code>
                            prefix, or the file cannot be decoded and the model returns an empty reply.
                        </p>
                        <p class="text-secondary small mb-2">
                            To send an image or file, make <code>content</code> an array of blocks instead of a plain
                            string. Unsupported options for the selected model are rejected with an HTTP 422.
                        </p>
                        <pre class="bg-body-tertiary border rounded p-3 small mb-0"><code>{{ advancedRequestExample }}</code></pre>
                    </li>

                    <li class="mb-4">
                        <p class="fw-semibold mb-1">2c. Generating images</p>
                        <p class="text-secondary small mb-2">
                            Models with the <strong>image_generation</strong> capability
                            (e.g. <code>{{ imageModelId }}</code>) use a separate OpenAI-compatible endpoint,
                            <code>/api/gateway/v1/images/generations</code>. Send a text <code>prompt</code>
                            instead of <code>messages</code>. Supported fields:
                        </p>
                        <ul class="text-secondary small mb-2">
                            <li><code>prompt</code> — required text description (up to 4000 characters).</li>
                            <li><code>n</code> — number of images to generate, 1–4 (default 1).</li>
                            <li><code>size</code> — image dimensions, e.g. <code>1024x1024</code>.</li>
                            <li><code>response_format</code> — <code>b64_json</code> (default) or <code>url</code>.</li>
                        </ul>
                        <p class="text-secondary small mb-2">
                            The response contains a <code>data</code> array; each item has a
                            <code>b64_json</code> (base64-encoded image) or <code>url</code> value.
                        </p>
                        <pre class="bg-body-tertiary border rounded p-3 small mb-0"><code>{{ imageRequestExample }}</code></pre>
                    </li>

                    <li class="mb-4">
                        <p class="fw-semibold mb-1">3. BCAIGW confirms the token belongs to this application</p>
                        <p class="text-secondary small mb-0">
                            The gateway looks up the application by the <code>X-BCAIGW-Application-Id</code> you present
                            and confirms the API Directory token was issued to this application's registered client
                            (<code>{{ clientId }}</code>). The token is the security anchor — the application ID is
                            just a non-secret selector.
                        </p>
                    </li>

                    <li>
                        <p class="fw-semibold mb-1">4. Request is checked against your application's limits</p>
                        <p class="text-secondary small mb-2">
                            Once authenticated and authorized, the request only proceeds when all of the following hold:
                        </p>
                        <ul class="text-secondary small mb-0">
                            <li>The application status is <strong>Active</strong>.</li>
                            <li>The requested model is one of your <strong>granted models</strong> for this application.</li>
                            <li>The target environment is one of your <strong>approved environments</strong>
                                ({{ (application.environments || []).join(', ') || 'none' }}).</li>
                            <li>Token usage is within your <strong>per-minute rate limit</strong>
                                ({{ application.expected_requests_per_minute }} requests/min).</li>
                            <li>Token usage is within your <strong>monthly budget</strong>
                                ({{ Number(application.expected_tokens_per_month).toLocaleString() }} tokens/month).</li>
                        </ul>
                    </li>
                </ol>
            </div>
        </section>

        <section class="card shadow-sm mt-4">
            <div class="card-body">
                <h3 class="h5 fw-bold">Base64 file converter</h3>
                <p class="text-secondary small mb-3">
                    Convert an image or PDF into a base64 data URL for the <code>image_url</code> or
                    <code>file</code> content blocks shown above. Conversion runs entirely in your browser.
                    Copy the whole output (including the <code>data:...;base64,</code> prefix) and paste it directly
                    as the block's <code>url</code> / <code>file_data</code> value — don't add a second prefix.
                </p>
                <div class="row g-2 align-items-end">
                    <div class="col-sm">
                        <label class="form-label small">Choose a file</label>
                        <input type="file" class="form-control form-control-sm" @change="onB64FileSelected" />
                    </div>
                </div>
                <div v-if="b64File" class="text-secondary small mt-2">
                    {{ b64File.name }} · {{ b64File.type || 'unknown type' }} · {{ (b64File.size / 1024).toFixed(1) }} KB
                </div>
                <div v-if="b64DataUrl" class="mt-3">
                    <label class="form-label small fw-semibold mb-1 d-flex justify-content-between align-items-center">
                        <span>Data URL</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyDataUrl">{{ b64Copied ? 'Copied' : 'Copy' }}</button>
                    </label>
                    <pre class="bg-body-tertiary border rounded p-3 small mb-0" style="max-height: 12rem; overflow: auto; white-space: pre-wrap; word-break: break-all;"><code>{{ b64DataUrl }}</code></pre>
                </div>
            </div>
        </section>
        </template>
    </PortalLayout>
</template>

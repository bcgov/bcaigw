<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    application: { type: Object, required: true },
    grantedModels: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
    availableModels: { type: Array, default: () => [] },
    requestedModels: { type: Array, default: () => [] },
    usage: { type: Object, default: () => ({}) },
    environments: { type: Array, default: () => [] },
    promotions: { type: Array, default: () => [] },
    changeRequests: { type: Array, default: () => [] },
    bedrockRegion: { type: String, default: 'ca-central-1' },
    bedrockGeo: { type: String, default: 'ca' },
});

const statusLabel = (status) => status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

const fmt = (n) => Number(n || 0).toLocaleString();
const currency = () => props.usage?.currency || 'CAD';
const money = (v) => (v === null || v === undefined ? '—' : `${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency()}`);
const limitText = (v) => (v === null || v === undefined ? 'Not set' : fmt(v));
const pct = (used, limit) => (limit && limit > 0 ? Math.min(100, Math.round(((used || 0) / limit) * 100)) : null);
const pctClass = (p) => (p === null ? 'bg-secondary' : p >= 90 ? 'bg-danger' : p >= 75 ? 'bg-warning' : 'bg-success');

// Classify a Bedrock model id by routing scope (global / in-region / on-demand).
const AWS_GEOS = ['us', 'us-gov', 'eu', 'apac', 'au', 'jp', 'sa', 'me', 'af', 'il', 'ca'];
const routingScope = (rawId) => {
    const id = String(rawId ?? '').replace(/^bedrock\//, '').toLowerCase();
    const token = id.split(/[./]/)[0] ?? '';
    const isRegionId = /^(us-gov|[a-z]{2})-[a-z]+-\d+$/.test(token);

    if (token === 'global') {
        return { label: 'Global', title: 'Global cross-region inference profile', variant: 'primary' };
    }
    if (token === 'ca' || token.startsWith('ca-')) {
        return { label: 'In-region (ca)', title: `Pinned to ${token}`, variant: 'success' };
    }
    if (AWS_GEOS.includes(token) || isRegionId) {
        return { label: token, title: `Pinned to ${token}`, variant: 'secondary' };
    }
    return {
        label: 'On-demand',
        title: 'Bare on-demand model id — regional availability not guaranteed; confirm on the AWS model card',
        variant: 'warning',
    };
};

// Refine the routing pill for bare on-demand ids using the AWS model-card region
// cross-reference attached by the controller (m.region_supported: true/false/null).
const regionPill = (m) => {
    const base = routingScope(m?.model_id);
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

const form = useForm({
    to_status: '',
    status_version: props.application.status_version,
    note: '',
});

const submitTransition = () => {
    form.post(`/admin/applications/${props.application.public_id}/transition`, {
        preserveScroll: true,
        onSuccess: () => form.reset('to_status', 'note'),
    });
};

// Grant an additional (enabled) model to this application.
const grantForm = useForm({ alias_public_id: '', capabilities: [] });

const selectedModel = computed(() =>
    props.availableModels.find((m) => m.public_id === grantForm.alias_public_id) || null,
);

// Default to granting every capability the selected model advertises.
watch(selectedModel, (model) => {
    grantForm.capabilities = model ? [...(model.capabilities || [])] : [];
});

const submitGrant = () => {
    grantForm.post(`/admin/applications/${props.application.public_id}/grants`, {
        preserveScroll: true,
        onSuccess: () => grantForm.reset('alias_public_id', 'capabilities'),
    });
};

// Toggle a granted model on/off. Track in-flight ids to disable the button.
const togglingGrants = ref([]);
const toggleGrant = (grant) => {
    if (togglingGrants.value.includes(grant.public_id)) {
        return;
    }
    togglingGrants.value.push(grant.public_id);
    router.put(`/admin/applications/${props.application.public_id}/grants/${grant.public_id}/toggle`, {}, {
        preserveScroll: true,
        onFinish: () => {
            togglingGrants.value = togglingGrants.value.filter((id) => id !== grant.public_id);
        },
    });
};

// Promotion review: approve (with optional budget overrides + note) or reject.
const pendingPromotions = computed(() => props.promotions.filter((p) => p.is_pending));
const pendingChangeRequests = computed(() => props.changeRequests.filter((p) => p.is_pending));
const reviewForms = ref({});
const reviewFormFor = (publicId) => {
    if (!reviewForms.value[publicId]) {
        reviewForms.value[publicId] = useForm({
            note: '',
            rate_limit_per_minute: null,
            token_budget_monthly: null,
            cost_budget_monthly: null,
        });
    }
    return reviewForms.value[publicId];
};

const approvePromotion = (promotion) => {
    reviewFormFor(promotion.public_id).post(
        `/admin/applications/${props.application.public_id}/promotions/${promotion.public_id}/approve`,
        { preserveScroll: true },
    );
};

const rejectPromotion = (promotion) => {
    reviewFormFor(promotion.public_id).post(
        `/admin/applications/${props.application.public_id}/promotions/${promotion.public_id}/reject`,
        { preserveScroll: true },
    );
};
</script>

<template>
    <AdminLayout>
        <Link href="/admin/applications" class="text-bc-blue">← Applications</Link>
        <div class="d-flex align-items-center justify-content-between mt-2 flex-wrap gap-2">
            <h2 class="h3 fw-bold mb-0">{{ application.name }}</h2>
            <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill">
                {{ statusLabel(application.status) }}
            </span>
        </div>

        <div class="row g-4 mt-1">
            <div class="col-lg-8">
                <section class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h5 fw-semibold">Details</h3>
                        <dl class="row mt-3 mb-0">
                            <div class="col-sm-6 mb-2"><dt class="small text-secondary fw-normal">Ministry</dt><dd class="mb-0">{{ application.ministry_organization }}</dd></div>
                            <div class="col-sm-6 mb-2"><dt class="small text-secondary fw-normal">Owner</dt><dd class="mb-0">{{ application.creator?.name }}</dd></div>
                            <div class="col-sm-6 mb-2"><dt class="small text-secondary fw-normal">Primary contact</dt><dd class="mb-0">{{ application.primary_contact_name }}</dd></div>
                            <div class="col-sm-6 mb-2"><dt class="small text-secondary fw-normal">Technical contact</dt><dd class="mb-0">{{ application.technical_contact_name }}</dd></div>
                            <div class="col-sm-6 mb-2"><dt class="small text-secondary fw-normal">Data classification</dt><dd class="mb-0">{{ application.data_classification }}</dd></div>
                            <div class="col-sm-6 mb-2"><dt class="small text-secondary fw-normal">API directory client ID</dt><dd class="mb-0 text-break"><code>{{ application.api_directory_client_id }}</code></dd></div>
                            <div class="col-sm-6 mb-2"><dt class="small text-secondary fw-normal">BCAIGW application ID</dt><dd class="mb-0 text-break"><code>{{ application.gateway_key }}</code></dd></div>
                        </dl>
                        <div class="mt-2">
                            <dt class="small text-secondary fw-normal">Purpose / use case</dt>
                            <dd class="mt-1 mb-0" style="white-space: pre-line;">{{ application.purpose_use_case }}</dd>
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-lg-4">
                <section class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h5 fw-semibold">Change status</h3>
                        <form v-if="availableTransitions.length" class="mt-3" @submit.prevent="submitTransition">
                            <div class="mb-3">
                                <label for="to-status" class="form-label">New status</label>
                                <select id="to-status" v-model="form.to_status" required class="form-select" :class="{ 'is-invalid': form.errors.to_status }">
                                    <option value="" disabled>Select…</option>
                                    <option v-for="t in availableTransitions" :key="t" :value="t">{{ statusLabel(t) }}</option>
                                </select>
                                <div v-if="form.errors.to_status" class="invalid-feedback">{{ form.errors.to_status }}</div>
                            </div>
                            <div class="mb-3">
                                <label for="note" class="form-label">Note (optional)</label>
                                <textarea id="note" v-model="form.note" rows="3" class="form-control"></textarea>
                            </div>
                            <button type="submit" :disabled="form.processing || !form.to_status" class="btn btn-primary w-100">
                                Apply
                            </button>
                        </form>
                        <p v-else class="text-secondary mb-0 mt-3">No status changes available from the current status.</p>
                    </div>
                </section>
            </div>
        </div>

        <section class="card shadow-sm mt-4">
            <div class="card-body">
                <h3 class="h5 fw-semibold">Requested models</h3>
                <p class="text-secondary small mb-2">Models and capabilities the applicant asked for. Review these before approving; grants are provisioned on approval.</p>
                <ul v-if="requestedModels.length" class="list-group list-group-flush">
                    <li v-for="model in requestedModels" :key="model.model_id" class="list-group-item px-0">
                        <div class="fw-semibold">{{ model.display_name }}</div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <code class="small text-break">{{ model.model_id }}</code>
                            <a
                                v-if="model.card_url"
                                :href="model.card_url"
                                target="_blank"
                                rel="noopener"
                                class="badge rounded-pill text-decoration-none"
                                :class="`text-bg-${regionPill(model).variant}`"
                                :title="`${regionPill(model).title} (opens AWS model card)`"
                            >{{ regionPill(model).label }}</a>
                            <span
                                v-else
                                class="badge rounded-pill"
                                :class="`text-bg-${regionPill(model).variant}`"
                                :title="regionPill(model).title"
                            >{{ regionPill(model).label }}</span>
                            <span v-if="!model.available" class="badge rounded-pill text-bg-danger" title="This model is not currently active in the catalogue and cannot be granted.">Unavailable</span>
                        </div>
                        <div v-if="model.capabilities?.length" class="mt-1">
                            <span v-for="cap in model.capabilities" :key="cap" class="badge text-bg-light border me-1">{{ cap }}</span>
                        </div>
                    </li>
                </ul>
                <p v-else class="text-secondary mb-0">No models were requested.</p>
            </div>
        </section>

        <section class="card shadow-sm mt-4">
            <div class="card-body">
                <h3 class="h5 fw-semibold">Granted models</h3>
                <p class="text-secondary small mb-2">Use the model ID as the <code>model</code> value when calling the gateway.</p>
                <ul v-if="grantedModels.length" class="list-group list-group-flush">
                    <li v-for="grant in grantedModels" :key="grant.public_id" class="list-group-item px-0">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold">{{ grant.display_name }}</div>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <code class="small text-break">{{ grant.model_id }}</code>
                                    <a
                                        v-if="grant.card_url"
                                        :href="grant.card_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="badge rounded-pill text-decoration-none"
                                        :class="`text-bg-${regionPill(grant).variant}`"
                                        :title="`${regionPill(grant).title} (opens AWS model card)`"
                                    >{{ regionPill(grant).label }}</a>
                                    <span
                                        v-else
                                        class="badge rounded-pill"
                                        :class="`text-bg-${regionPill(grant).variant}`"
                                        :title="regionPill(grant).title"
                                    >{{ regionPill(grant).label }}</span>
                                </div>
                                <div v-if="grant.capabilities?.length" class="mt-1">
                                    <span v-for="cap in grant.capabilities" :key="cap" class="badge text-bg-light border me-1">{{ cap }}</span>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                <span class="badge" :class="grant.enabled ? 'text-bg-success' : 'text-bg-secondary'">
                                    {{ grant.enabled ? 'Enabled' : 'Disabled' }}
                                </span>
                                <button
                                    type="button"
                                    class="btn btn-sm"
                                    :class="grant.enabled ? 'btn-outline-danger' : 'btn-outline-success'"
                                    :disabled="togglingGrants.includes(grant.public_id)"
                                    @click="toggleGrant(grant)"
                                >
                                    <span v-if="togglingGrants.includes(grant.public_id)" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                    {{ grant.enabled ? 'Disable' : 'Enable' }}
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>
                <p v-else class="text-secondary mb-0">No model grants.</p>

                <div class="border-top pt-3 mt-3">
                    <h4 class="h6 fw-semibold">Grant an additional model</h4>
                    <p class="text-secondary small mb-2">Only enabled (active) models that aren't already granted are listed.</p>
                    <form v-if="availableModels.length" @submit.prevent="submitGrant">
                        <div class="row g-2 align-items-start">
                            <div class="col-md-7">
                                <label for="grant-model" class="form-label small">Model</label>
                                <select id="grant-model" v-model="grantForm.alias_public_id" required class="form-select" :class="{ 'is-invalid': grantForm.errors.alias_public_id }">
                                    <option value="" disabled>Select a model…</option>
                                    <option v-for="model in availableModels" :key="model.public_id" :value="model.public_id">
                                        {{ model.display_name }} ({{ model.model_id }})
                                    </option>
                                </select>
                                <div v-if="grantForm.errors.alias_public_id" class="invalid-feedback">{{ grantForm.errors.alias_public_id }}</div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small">Capabilities</label>
                                <div v-if="selectedModel" class="d-flex flex-wrap gap-2">
                                    <div v-for="cap in selectedModel.capabilities" :key="cap" class="form-check">
                                        <input :id="`cap-${cap}`" v-model="grantForm.capabilities" :value="cap" type="checkbox" class="form-check-input" />
                                        <label :for="`cap-${cap}`" class="form-check-label small">{{ cap }}</label>
                                    </div>
                                    <span v-if="!selectedModel.capabilities?.length" class="text-secondary small">No capabilities defined.</span>
                                </div>
                                <span v-else class="text-secondary small">Select a model to choose capabilities.</span>
                            </div>
                        </div>
                        <button type="submit" :disabled="grantForm.processing || !grantForm.alias_public_id" class="btn btn-primary btn-sm mt-3">
                            Grant model
                        </button>
                    </form>
                    <p v-else class="text-secondary small mb-0">All enabled models are already granted to this application.</p>
                </div>
            </div>
        </section>

        <section class="card shadow-sm mt-4">
            <div class="card-body">
                <h3 class="h5 fw-semibold">Usage &amp; limits</h3>
                <p class="text-secondary small mb-3">Usage is aggregated from gateway call rollups. Budgets and rate limits are configured on the application.</p>

                <div class="row g-3">
                    <div v-for="period in [{ key: 'today', label: 'Today' }, { key: 'month', label: 'This month' }, { key: 'total', label: 'All time' }]" :key="period.key" class="col-md-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-secondary small text-uppercase fw-semibold">{{ period.label }}</div>
                            <div class="d-flex justify-content-between mt-2">
                                <span class="text-secondary">Requests</span>
                                <span class="fw-semibold">{{ fmt(usage[period.key]?.requests) }}</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-secondary">Failures</span>
                                <span class="fw-semibold" :class="usage[period.key]?.failures ? 'text-danger' : ''">{{ fmt(usage[period.key]?.failures) }}</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-secondary">Total tokens</span>
                                <span class="fw-semibold">{{ fmt(usage[period.key]?.total_tokens) }}</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-secondary">In / Out</span>
                                <span>{{ fmt(usage[period.key]?.input_tokens) }} / {{ fmt(usage[period.key]?.output_tokens) }}</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-secondary">Cost</span>
                                <span class="fw-semibold">{{ money(usage[period.key]?.cost) }}</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-secondary">Avg latency</span>
                                <span>{{ fmt(usage[period.key]?.avg_latency_ms) }} ms</span>
                            </div>
                        </div>
                    </div>
                </div>

                <h4 class="h6 fw-semibold mt-4">Budgets</h4>
                <p class="text-secondary small mb-2">Enforced limits fall back to the approved values (expected tokens / month and expected requests / min) unless an explicit override is configured.</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between small">
                            <span>Monthly tokens (enforced)</span>
                            <span class="text-secondary">
                                {{ fmt(usage.month?.total_tokens) }} /
                                {{ usage.limits?.effective_token_budget_monthly ? fmt(usage.limits.effective_token_budget_monthly) : '∞' }}
                            </span>
                        </div>
                        <div class="progress mt-1" style="height: 8px;">
                            <div
                                class="progress-bar"
                                :class="pctClass(pct(usage.month?.total_tokens, usage.limits?.effective_token_budget_monthly))"
                                :style="{ width: (pct(usage.month?.total_tokens, usage.limits?.effective_token_budget_monthly) ?? 0) + '%' }"
                            ></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between small">
                            <span>Daily tokens</span>
                            <span class="text-secondary">
                                {{ fmt(usage.today?.total_tokens) }} /
                                {{ usage.limits?.token_budget_daily ? fmt(usage.limits.token_budget_daily) : '∞' }}
                            </span>
                        </div>
                        <div class="progress mt-1" style="height: 8px;">
                            <div
                                class="progress-bar"
                                :class="pctClass(pct(usage.today?.total_tokens, usage.limits?.token_budget_daily))"
                                :style="{ width: (pct(usage.today?.total_tokens, usage.limits?.token_budget_daily) ?? 0) + '%' }"
                            ></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between small">
                            <span>Monthly cost{{ usage.limits?.cost_budget_monthly ? '' : ' (derived)' }}</span>
                            <span class="text-secondary">
                                {{ money(usage.month?.cost) }} /
                                {{ usage.limits?.effective_cost_budget_monthly ? money(usage.limits.effective_cost_budget_monthly) : '∞' }}
                            </span>
                        </div>
                        <div class="progress mt-1" style="height: 8px;">
                            <div
                                class="progress-bar"
                                :class="pctClass(pct(usage.month?.cost, usage.limits?.effective_cost_budget_monthly))"
                                :style="{ width: (pct(usage.month?.cost, usage.limits?.effective_cost_budget_monthly) ?? 0) + '%' }"
                            ></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between small">
                            <span>Daily cost</span>
                            <span class="text-secondary">
                                {{ money(usage.today?.cost) }} /
                                {{ usage.limits?.cost_budget_daily ? money(usage.limits.cost_budget_daily) : '∞' }}
                            </span>
                        </div>
                        <div class="progress mt-1" style="height: 8px;">
                            <div
                                class="progress-bar"
                                :class="pctClass(pct(usage.today?.cost, usage.limits?.cost_budget_daily))"
                                :style="{ width: (pct(usage.today?.cost, usage.limits?.cost_budget_daily) ?? 0) + '%' }"
                            ></div>
                        </div>
                    </div>
                </div>

                <h4 class="h6 fw-semibold mt-4">Configured limits</h4>
                <dl class="row mb-0">
                    <div class="col-sm-6 col-lg-3 mb-2">
                        <dt class="small text-secondary fw-normal">Rate limit / min (enforced)</dt>
                        <dd class="mb-0">
                            {{ limitText(usage.limits?.effective_rate_limit_per_minute) }}
                            <span v-if="usage.limits?.effective_rate_limit_per_minute && usage.limits?.rate_limit_per_minute === null" class="small text-secondary">· from approved</span>
                        </dd>
                    </div>
                    <div class="col-sm-6 col-lg-3 mb-2">
                        <dt class="small text-secondary fw-normal">Token budget (monthly, enforced)</dt>
                        <dd class="mb-0">
                            {{ limitText(usage.limits?.effective_token_budget_monthly) }}
                            <span v-if="usage.limits?.effective_token_budget_monthly && usage.limits?.token_budget_monthly === null" class="small text-secondary">· from approved</span>
                        </dd>
                    </div>
                    <div class="col-sm-6 col-lg-3 mb-2">
                        <dt class="small text-secondary fw-normal">Derived monthly cost</dt>
                        <dd class="mb-0">{{ usage.limits?.derived_cost_budget_monthly ? money(usage.limits.derived_cost_budget_monthly) : 'No priced model' }}</dd>
                    </div>
                    <div class="col-sm-6 col-lg-3 mb-2"><dt class="small text-secondary fw-normal">Token rate / min</dt><dd class="mb-0">{{ limitText(usage.limits?.token_rate_per_minute) }}</dd></div>
                    <div class="col-sm-6 col-lg-3 mb-2"><dt class="small text-secondary fw-normal">Token budget (daily)</dt><dd class="mb-0">{{ limitText(usage.limits?.token_budget_daily) }}</dd></div>
                    <div class="col-sm-6 col-lg-3 mb-2"><dt class="small text-secondary fw-normal">Cost budget (daily)</dt><dd class="mb-0">{{ usage.limits?.cost_budget_daily ? money(usage.limits.cost_budget_daily) : 'Not set' }}</dd></div>
                    <div class="col-sm-6 col-lg-3 mb-2"><dt class="small text-secondary fw-normal">Approved req / min</dt><dd class="mb-0">{{ limitText(usage.limits?.expected_requests_per_minute) }}</dd></div>
                    <div class="col-sm-6 col-lg-3 mb-2"><dt class="small text-secondary fw-normal">Approved tokens / month</dt><dd class="mb-0">{{ limitText(usage.limits?.expected_tokens_per_month) }}</dd></div>
                </dl>

                <h4 class="h6 fw-semibold mt-4">By model (this month)</h4>
                <div v-if="usage.by_model?.length" class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Model</th>
                                <th scope="col" class="text-end">Requests</th>
                                <th scope="col" class="text-end">Failures</th>
                                <th scope="col" class="text-end">Total tokens</th>
                                <th scope="col" class="text-end">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in usage.by_model" :key="i">
                                <td>
                                    <div class="fw-semibold">{{ row.display_name || '—' }}</div>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <code class="small text-break">{{ row.model_id }}</code>
                                        <a
                                            v-if="row.card_url"
                                            :href="row.card_url"
                                            target="_blank"
                                            rel="noopener"
                                            class="badge rounded-pill text-decoration-none"
                                            :class="`text-bg-${regionPill(row).variant}`"
                                            :title="`${regionPill(row).title} (opens AWS model card)`"
                                        >{{ regionPill(row).label }}</a>
                                        <span
                                            v-else
                                            class="badge rounded-pill"
                                            :class="`text-bg-${regionPill(row).variant}`"
                                            :title="regionPill(row).title"
                                        >{{ regionPill(row).label }}</span>
                                    </div>
                                </td>
                                <td class="text-end">{{ fmt(row.requests) }}</td>
                                <td class="text-end" :class="row.failures ? 'text-danger' : ''">{{ fmt(row.failures) }}</td>
                                <td class="text-end">{{ fmt(row.total_tokens) }}</td>
                                <td class="text-end">{{ money(row.cost) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-secondary mb-0">No usage recorded this month.</p>
            </div>
        </section>

        <section class="card shadow-sm mt-4">
            <div class="card-body">
                <h3 class="h5 fw-semibold">Environments</h3>
                <p class="text-secondary small">Per-environment status, budgets and enabled models.</p>
                <div v-if="environments.length" class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Environment</th>
                                <th>Status</th>
                                <th class="text-end">Req/min</th>
                                <th class="text-end">Token budget/mo</th>
                                <th class="text-end">Cost budget/mo</th>
                                <th class="text-end">Models</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="env in environments" :key="env.environment">
                                <td class="fw-semibold">{{ env.label }}</td>
                                <td>
                                    <span class="badge rounded-pill"
                                          :class="env.status === 'active' ? 'text-bg-success' : (env.status === 'suspended' ? 'text-bg-danger' : 'text-bg-secondary')">
                                        {{ statusLabel(env.status) }}
                                    </span>
                                </td>
                                <td class="text-end">{{ limitText(env.rate_limit_per_minute) }}</td>
                                <td class="text-end">{{ limitText(env.token_budget_monthly) }}</td>
                                <td class="text-end">{{ money(env.cost_budget_monthly) }}</td>
                                <td class="text-end">{{ env.model_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-secondary mb-0">No environments yet. They are created when the application is activated.</p>

                <div v-for="env in environments" :key="`usage-${env.environment}`" class="mt-4">
                    <h4 class="h6 fw-semibold d-flex align-items-center gap-2">
                        {{ env.label }} usage
                        <span class="badge rounded-pill"
                              :class="env.status === 'active' ? 'text-bg-success' : (env.status === 'suspended' ? 'text-bg-danger' : 'text-bg-secondary')">
                            {{ statusLabel(env.status) }}
                        </span>
                    </h4>
                    <div class="row g-3">
                        <div v-for="period in [{ key: 'today', label: 'Today' }, { key: 'month', label: 'This month' }, { key: 'total', label: 'All time' }]" :key="period.key" class="col-md-4">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="text-secondary small text-uppercase fw-semibold">{{ period.label }}</div>
                                <div class="d-flex justify-content-between mt-2">
                                    <span class="text-secondary">Requests</span>
                                    <span class="fw-semibold">{{ fmt(env.usage[period.key]?.requests) }}</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-secondary">Failures</span>
                                    <span class="fw-semibold" :class="env.usage[period.key]?.failures ? 'text-danger' : ''">{{ fmt(env.usage[period.key]?.failures) }}</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-secondary">Total tokens</span>
                                    <span class="fw-semibold">{{ fmt(env.usage[period.key]?.total_tokens) }}</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-secondary">In / Out</span>
                                    <span>{{ fmt(env.usage[period.key]?.input_tokens) }} / {{ fmt(env.usage[period.key]?.output_tokens) }}</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-secondary">Cost</span>
                                    <span class="fw-semibold">{{ money(env.usage[period.key]?.cost) }}</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-secondary">Avg latency</span>
                                    <span>{{ fmt(env.usage[period.key]?.avg_latency_ms) }} ms</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="card shadow-sm mt-4">
            <div class="card-body">
                <h3 class="h5 fw-semibold">Model change requests</h3>
                <p class="text-secondary small">Developer-requested changes to the development environment's models. Development keeps serving its current models until you approve.</p>

                <div v-if="pendingChangeRequests.length" class="vstack gap-3 mb-4">
                    <div v-for="change in pendingChangeRequests" :key="change.public_id" class="border rounded p-3 bg-warning-subtle">
                        <p class="fw-semibold mb-1">
                            Development model change
                            <span class="badge text-bg-warning ms-1">Pending</span>
                        </p>
                        <p class="text-secondary small mb-2">
                            Requested by {{ change.requested_by }} · {{ new Date(change.requested_at).toLocaleString() }}
                        </p>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="small text-secondary text-uppercase fw-semibold mb-1">Current models</div>
                                <ul class="list-unstyled small mb-0">
                                    <li v-for="m in change.current_models" :key="m.model_id" class="text-break"
                                        :class="{ 'text-danger text-decoration-line-through': change.removed.includes(m.model_id) }">
                                        <code>{{ m.model_id }}</code>
                                    </li>
                                    <li v-if="!change.current_models.length" class="text-secondary">None</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <div class="small text-secondary text-uppercase fw-semibold mb-1">New models</div>
                                <ul class="list-unstyled small mb-0">
                                    <li v-for="m in change.new_models" :key="m.model_id" class="text-break"
                                        :class="{ 'text-success fw-semibold': change.added.includes(m.model_id) }">
                                        <code>{{ m.model_id }}</code>
                                        <span v-if="change.added.includes(m.model_id)" class="badge text-bg-success ms-1">added</span>
                                        <span v-if="(m.capabilities || []).length" class="ms-1">
                                            <span v-for="cap in m.capabilities" :key="cap" class="badge text-bg-light border me-1">{{ cap }}</span>
                                        </span>
                                    </li>
                                    <li v-if="!change.new_models.length" class="text-secondary">None</li>
                                </ul>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label small mb-1">Note</label>
                            <input v-model="reviewFormFor(change.public_id).note" type="text" class="form-control form-control-sm" placeholder="optional">
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-success btn-sm"
                                    :disabled="reviewFormFor(change.public_id).processing"
                                    @click="approvePromotion(change)">Approve change</button>
                            <button type="button" class="btn btn-outline-danger btn-sm"
                                    :disabled="reviewFormFor(change.public_id).processing"
                                    @click="rejectPromotion(change)">Reject</button>
                        </div>
                    </div>
                </div>
                <p v-else class="text-secondary">No pending model change requests.</p>
            </div>
        </section>

        <section class="card shadow-sm mt-4">
            <div class="card-body">
                <h3 class="h5 fw-semibold">Promotion requests</h3>
                <p class="text-secondary small">Approve to apply the source environment's models to the target and activate it. You may override budgets before approving.</p>

                <div v-if="pendingPromotions.length" class="vstack gap-3 mb-4">
                    <div v-for="promotion in pendingPromotions" :key="promotion.public_id" class="border rounded p-3 bg-warning-subtle">
                        <div class="d-flex justify-content-between flex-wrap gap-2">
                            <div>
                                <p class="fw-semibold mb-1">
                                    {{ statusLabel(promotion.from_environment) }} → {{ promotion.to_label }}
                                    <span class="badge text-bg-warning ms-1">Pending</span>
                                </p>
                                <p class="text-secondary small mb-1">
                                    Requested by {{ promotion.requested_by }} · {{ new Date(promotion.requested_at).toLocaleString() }}
                                    · {{ promotion.model_count }} model{{ promotion.model_count === 1 ? '' : 's' }}
                                </p>
                                <p v-if="promotion.models.length" class="small mb-0">
                                    <code v-for="m in promotion.models" :key="m" class="me-2 text-break">{{ m }}</code>
                                </p>
                            </div>
                        </div>

                        <div class="row g-2 mt-2">
                            <div class="col-md-3">
                                <label class="form-label small mb-1">Req/min override</label>
                                <input v-model.number="reviewFormFor(promotion.public_id).rate_limit_per_minute" type="number" min="0" class="form-control form-control-sm" placeholder="keep proposed">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1">Token budget/mo</label>
                                <input v-model.number="reviewFormFor(promotion.public_id).token_budget_monthly" type="number" min="0" class="form-control form-control-sm" placeholder="keep proposed">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1">Cost budget/mo</label>
                                <input v-model.number="reviewFormFor(promotion.public_id).cost_budget_monthly" type="number" min="0" step="0.01" class="form-control form-control-sm" placeholder="keep proposed">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1">Note</label>
                                <input v-model="reviewFormFor(promotion.public_id).note" type="text" class="form-control form-control-sm" placeholder="optional">
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-success btn-sm"
                                    :disabled="reviewFormFor(promotion.public_id).processing"
                                    @click="approvePromotion(promotion)">Approve &amp; activate</button>
                            <button type="button" class="btn btn-outline-danger btn-sm"
                                    :disabled="reviewFormFor(promotion.public_id).processing"
                                    @click="rejectPromotion(promotion)">Reject</button>
                        </div>
                    </div>
                </div>
                <p v-else class="text-secondary">No pending promotion requests.</p>

                <div v-if="promotions.length" class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Promotion</th>
                                <th>Status</th>
                                <th>Requested by</th>
                                <th>Reviewed by</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="promotion in promotions" :key="promotion.public_id">
                                <td>{{ statusLabel(promotion.from_environment) }} → {{ promotion.to_label }}</td>
                                <td>{{ statusLabel(promotion.status) }}</td>
                                <td>{{ promotion.requested_by }}</td>
                                <td>{{ promotion.reviewed_by || '—' }}</td>
                                <td class="text-break">{{ promotion.review_note || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="card shadow-sm mt-4">
            <div class="card-body">
                <h3 class="h5 fw-semibold">Lifecycle history</h3>
                <ol v-if="application.lifecycle_history?.length" class="list-unstyled mb-0 mt-2">
                    <li v-for="entry in application.lifecycle_history" :key="entry.id" class="border-start border-2 ps-3 mb-3">
                        <p class="fw-semibold mb-0">{{ statusLabel(entry.from_status) }} → {{ statusLabel(entry.to_status) }}</p>
                        <p class="text-secondary small mb-0">{{ entry.actor?.name }} · {{ new Date(entry.created_at).toLocaleString() }}</p>
                        <p v-if="entry.note" class="mt-1 mb-0">{{ entry.note }}</p>
                    </li>
                </ol>
                <p v-else class="text-secondary mb-0">No history yet.</p>
            </div>
        </section>
    </AdminLayout>
</template>

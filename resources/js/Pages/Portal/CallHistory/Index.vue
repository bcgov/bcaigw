<script setup>
import { Link, router } from '@inertiajs/vue3';
import { reactive, computed } from 'vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    applications: { type: Array, default: () => [] },
    calls: { type: Object, required: true },
    summary: { type: Object, required: true },
    isAdministrator: { type: Boolean, default: false },
});

const form = reactive({
    application: props.filters.application ?? '',
    status: props.filters.status ?? '',
    operation: props.filters.operation ?? '',
    model: props.filters.model ?? '',
    request_id: props.filters.request_id ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const query = computed(() =>
    Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '' && value !== null)),
);

const applyFilters = () => {
    router.get('/portal/calls', query.value, { preserveState: true, preserveScroll: true });
};

const money = (microunits, currency) => {
    if (microunits === null || microunits === undefined) return '—';
    return `${(microunits / 1000000).toFixed(6)} ${currency ?? ''}`.trim();
};

const contentLabel = {
    not_retained: 'Not retained',
    unavailable: 'Unavailable',
    stored: 'Stored',
    deleted: 'Deleted',
};
</script>

<template>
    <PortalLayout>
        <h2 class="h3 fw-bold">Call history</h2>
        <p class="text-secondary">
            Metadata for every gateway call your applications made. Prompt and response content is never shown here.
        </p>

        <section aria-labelledby="summary-heading" class="row g-3 mt-1">
            <h3 id="summary-heading" class="visually-hidden">Usage summary</h3>
            <div class="col-6 col-lg">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-secondary small mb-1">Requests</p>
                        <p class="h4 fw-bold mb-0">{{ summary.totals.requests }}</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-secondary small mb-1">Failures</p>
                        <p class="h4 fw-bold mb-0">{{ summary.totals.failures }}</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-secondary small mb-1">Tokens</p>
                        <p class="h4 fw-bold mb-0">{{ summary.totals.total_tokens }}</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-secondary small mb-1">Cost</p>
                        <p class="h4 fw-bold mb-0">{{ money(summary.totals.cost_microunits, 'CAD') }}</p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-secondary small mb-1">Avg latency</p>
                        <p class="h4 fw-bold mb-0">{{ summary.totals.average_latency_ms }} ms</p>
                    </div>
                </div>
            </div>
        </section>

        <form class="card shadow-sm mt-4" @submit.prevent="applyFilters">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="filter-application" class="form-label">Application</label>
                        <select id="filter-application" v-model="form.application" class="form-select">
                            <option value="">All applications</option>
                            <option v-for="app in applications" :key="app.public_id" :value="app.public_id">{{ app.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="filter-status" class="form-label">Outcome</label>
                        <select id="filter-status" v-model="form.status" class="form-select">
                            <option value="">Any</option>
                            <option value="succeeded">Succeeded</option>
                            <option value="failed">Failed</option>
                            <option value="partial">Partial</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="started">In flight</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="filter-model" class="form-label">Model</label>
                        <input id="filter-model" v-model="form.model" type="text" class="form-control" />
                    </div>
                    <div class="col-md-3">
                        <label for="filter-request-id" class="form-label">Request ID</label>
                        <input id="filter-request-id" v-model="form.request_id" type="text" class="form-control" />
                    </div>
                    <div class="col-md-3">
                        <label for="filter-from" class="form-label">From (UTC)</label>
                        <input id="filter-from" v-model="form.from" type="datetime-local" class="form-control" />
                    </div>
                    <div class="col-md-3">
                        <label for="filter-to" class="form-label">To (UTC)</label>
                        <input id="filter-to" v-model="form.to" type="datetime-local" class="form-control" />
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary">Apply</button>
                    </div>
                </div>
            </div>
        </form>

        <div class="card shadow-sm mt-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Gateway calls</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Started</th>
                            <th scope="col">Application</th>
                            <th scope="col">Model</th>
                            <th scope="col">Operation</th>
                            <th scope="col">Outcome</th>
                            <th scope="col">Tokens</th>
                            <th scope="col">Cost</th>
                            <th scope="col">Latency</th>
                            <th scope="col">Content</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="call in calls.data" :key="call.request_id">
                            <td>
                                <Link :href="`/portal/calls/${call.request_id}`" class="text-bc-blue">
                                    {{ call.started_at }}
                                </Link>
                            </td>
                            <td>{{ call.application_name }}</td>
                            <td>{{ call.resolved_model_alias }}</td>
                            <td>{{ call.operation }}</td>
                            <td>
                                {{ call.status }}
                                <span v-if="call.error_category" class="text-secondary">({{ call.error_category }})</span>
                            </td>
                            <td>{{ call.total_tokens ?? '—' }}</td>
                            <td>{{ money(call.cost_microunits, call.cost_currency) }}</td>
                            <td>{{ call.total_latency_ms ?? '—' }} ms</td>
                            <td>{{ contentLabel[call.content_state] ?? call.content_state }}</td>
                        </tr>
                        <tr v-if="calls.data.length === 0">
                            <td colspan="9" class="text-center text-secondary py-4">No calls match these filters.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <nav v-if="calls.links" class="mt-4" aria-label="Pagination">
            <ul class="pagination flex-wrap mb-0">
                <li
                    v-for="link in calls.links"
                    :key="link.label"
                    class="page-item"
                    :class="{ active: link.active, disabled: !link.url }"
                >
                    <Link
                        class="page-link"
                        :href="link.url ?? '#'"
                        :aria-current="link.active ? 'page' : undefined"
                        v-html="link.label"
                    />
                </li>
            </ul>
        </nav>
    </PortalLayout>
</template>

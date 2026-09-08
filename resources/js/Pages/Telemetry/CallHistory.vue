<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, computed } from 'vue';

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
    error_category: props.filters.error_category ?? '',
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

const exportUrl = (format) => {
    const params = new URLSearchParams({ ...query.value, format });
    return `/portal/calls/export?${params.toString()}`;
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
    <Head title="Call history" />
    <main class="mx-auto max-w-7xl px-6 py-10">
        <Link href="/portal" class="text-bc-blue underline">Portal</Link>
        <h1 class="mt-2 text-3xl font-bold">Call history</h1>
        <p class="mt-2 max-w-3xl text-slate-600">
            Metadata for every gateway call your applications made. Prompt and response content is never shown in this
            list; open a call and use the explicit reveal action, which is recorded in the security audit log.
        </p>

        <section aria-labelledby="summary-heading" class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <h2 id="summary-heading" class="sr-only">Usage summary</h2>
            <div class="rounded-lg bg-white p-4 shadow-sm">
                <p class="text-sm text-slate-500">Requests</p>
                <p class="text-2xl font-bold">{{ summary.totals.requests }}</p>
            </div>
            <div class="rounded-lg bg-white p-4 shadow-sm">
                <p class="text-sm text-slate-500">Failures</p>
                <p class="text-2xl font-bold">{{ summary.totals.failures }}</p>
            </div>
            <div class="rounded-lg bg-white p-4 shadow-sm">
                <p class="text-sm text-slate-500">Tokens</p>
                <p class="text-2xl font-bold">{{ summary.totals.total_tokens }}</p>
            </div>
            <div class="rounded-lg bg-white p-4 shadow-sm">
                <p class="text-sm text-slate-500">Cost</p>
                <p class="text-2xl font-bold">{{ money(summary.totals.cost_microunits, 'CAD') }}</p>
            </div>
            <div class="rounded-lg bg-white p-4 shadow-sm">
                <p class="text-sm text-slate-500">Avg latency</p>
                <p class="text-2xl font-bold">{{ summary.totals.average_latency_ms }} ms</p>
            </div>
        </section>

        <form class="mt-8 grid gap-4 rounded-lg bg-white p-6 shadow-sm md:grid-cols-4" @submit.prevent="applyFilters">
            <div>
                <label for="filter-application" class="block text-sm font-semibold">Application</label>
                <select id="filter-application" v-model="form.application" class="mt-1 w-full rounded border-slate-300">
                    <option value="">All applications</option>
                    <option v-for="app in applications" :key="app.public_id" :value="app.public_id">{{ app.name }}</option>
                </select>
            </div>
            <div>
                <label for="filter-status" class="block text-sm font-semibold">Outcome</label>
                <select id="filter-status" v-model="form.status" class="mt-1 w-full rounded border-slate-300">
                    <option value="">Any</option>
                    <option value="succeeded">Succeeded</option>
                    <option value="failed">Failed</option>
                    <option value="partial">Partial</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="started">In flight</option>
                </select>
            </div>
            <div>
                <label for="filter-operation" class="block text-sm font-semibold">Operation</label>
                <select id="filter-operation" v-model="form.operation" class="mt-1 w-full rounded border-slate-300">
                    <option value="">Any</option>
                    <option value="chat.completions">chat.completions</option>
                    <option value="responses">responses</option>
                    <option value="embeddings">embeddings</option>
                </select>
            </div>
            <div>
                <label for="filter-model" class="block text-sm font-semibold">Model</label>
                <input id="filter-model" v-model="form.model" type="text" class="mt-1 w-full rounded border-slate-300" />
            </div>
            <div>
                <label for="filter-request-id" class="block text-sm font-semibold">Request ID</label>
                <input id="filter-request-id" v-model="form.request_id" type="text" class="mt-1 w-full rounded border-slate-300" />
            </div>
            <div>
                <label for="filter-from" class="block text-sm font-semibold">From (UTC)</label>
                <input id="filter-from" v-model="form.from" type="datetime-local" class="mt-1 w-full rounded border-slate-300" />
            </div>
            <div>
                <label for="filter-to" class="block text-sm font-semibold">To (UTC)</label>
                <input id="filter-to" v-model="form.to" type="datetime-local" class="mt-1 w-full rounded border-slate-300" />
            </div>
            <div class="flex items-end gap-3">
                <button type="submit" class="rounded bg-bc-blue px-4 py-2 font-semibold text-white">Apply</button>
                <a :href="exportUrl('csv')" class="text-bc-blue underline">CSV</a>
                <a :href="exportUrl('json')" class="text-bc-blue underline">JSON</a>
            </div>
        </form>

        <div class="mt-8 overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Gateway calls</caption>
                <thead class="bg-slate-100">
                    <tr>
                        <th scope="col" class="p-3">Started</th>
                        <th scope="col" class="p-3">Application</th>
                        <th scope="col" class="p-3">Model</th>
                        <th scope="col" class="p-3">Operation</th>
                        <th scope="col" class="p-3">Outcome</th>
                        <th scope="col" class="p-3">Tokens</th>
                        <th scope="col" class="p-3">Cost</th>
                        <th scope="col" class="p-3">Latency</th>
                        <th scope="col" class="p-3">Content</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="call in calls.data" :key="call.request_id" class="border-t">
                        <td class="p-3">
                            <Link :href="`/portal/calls/${call.request_id}`" class="text-bc-blue underline">
                                {{ call.started_at }}
                            </Link>
                        </td>
                        <td class="p-3">{{ call.application_name }}</td>
                        <td class="p-3">{{ call.resolved_model_alias }}</td>
                        <td class="p-3">{{ call.operation }}</td>
                        <td class="p-3">
                            {{ call.status }}
                            <span v-if="call.error_category" class="text-slate-500">({{ call.error_category }})</span>
                        </td>
                        <td class="p-3">{{ call.total_tokens ?? '—' }}</td>
                        <td class="p-3">{{ money(call.cost_microunits, call.cost_currency) }}</td>
                        <td class="p-3">{{ call.total_latency_ms ?? '—' }} ms</td>
                        <td class="p-3">{{ contentLabel[call.content_state] ?? call.content_state }}</td>
                    </tr>
                    <tr v-if="calls.data.length === 0">
                        <td colspan="9" class="p-6 text-center text-slate-500">No calls match these filters.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="calls.links" class="mt-6 flex flex-wrap gap-2" aria-label="Pagination">
            <Link
                v-for="link in calls.links"
                :key="link.label"
                :href="link.url ?? '#'"
                :aria-current="link.active ? 'page' : undefined"
                :class="[
                    'rounded border px-3 py-1',
                    link.active ? 'bg-bc-blue text-white' : 'bg-white',
                    link.url ? '' : 'pointer-events-none opacity-40',
                ]"
                v-html="link.label"
            />
        </nav>
    </main>
</template>

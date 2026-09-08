<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    deletions: { type: Array, default: () => [] },
    applications: { type: Array, default: () => [] },
});

const page = usePage();
const status = computed(() => page.props.flash?.status ?? null);

const form = useForm({
    scope: 'application',
    mode: 'content',
    application: '',
    request_ids: [],
    range_from: '',
    range_to: '',
    reason: '',
    confirmation: '',
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        request_ids: typeof data.request_ids === 'string'
            ? data.request_ids.split(/[\s,]+/).filter(Boolean)
            : data.request_ids,
    })).post('/portal/admin/telemetry/deletions', {
        preserveScroll: true,
        onSuccess: () => form.reset('reason', 'confirmation', 'request_ids'),
    });
};
</script>

<template>
    <Head title="Telemetry deletion" />
    <main class="mx-auto max-w-6xl px-6 py-10">
        <Link href="/portal/admin" class="text-bc-blue underline">Administration</Link>
        <h1 class="mt-2 text-3xl font-bold">Telemetry content deletion</h1>

        <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">
            <p class="font-semibold">Prompt and response retention is enabled by default and kept indefinitely.</p>
            <p class="mt-1">
                Deletion is irreversible. Destroying a content record destroys its wrapped encryption key, which
                crypto-shreds the stored payload. Accounting records, quota ledger entries and aggregate usage totals
                are preserved; a non-content tombstone remains on every affected call.
            </p>
        </div>

        <p v-if="status" role="status" class="mt-4 rounded bg-green-50 p-4 text-sm font-semibold text-green-800">
            {{ status }}
        </p>

        <form class="mt-8 grid gap-4 rounded-lg bg-white p-6 shadow-sm md:grid-cols-2" @submit.prevent="submit">
            <div>
                <label for="scope" class="block text-sm font-semibold">Scope</label>
                <select id="scope" v-model="form.scope" class="mt-1 w-full rounded border-slate-300">
                    <option value="selected">Selected request IDs</option>
                    <option value="application">Entire application</option>
                    <option value="range">Date range</option>
                </select>
            </div>
            <div>
                <label for="mode" class="block text-sm font-semibold">What to delete</label>
                <select id="mode" v-model="form.mode" class="mt-1 w-full rounded border-slate-300">
                    <option value="content">Retained content only</option>
                    <option value="content_and_details">Content and detailed records</option>
                </select>
            </div>
            <div>
                <label for="application" class="block text-sm font-semibold">Application</label>
                <select id="application" v-model="form.application" class="mt-1 w-full rounded border-slate-300">
                    <option value="">Not scoped to an application</option>
                    <option v-for="app in applications" :key="app.public_id" :value="app.public_id">{{ app.name }}</option>
                </select>
                <p v-if="form.errors.application" role="alert" class="mt-1 text-sm text-red-700">{{ form.errors.application }}</p>
            </div>
            <div>
                <label for="request-ids" class="block text-sm font-semibold">Request IDs (comma or newline separated)</label>
                <textarea id="request-ids" v-model="form.request_ids" rows="3" class="mt-1 w-full rounded border-slate-300"></textarea>
                <p v-if="form.errors.request_ids" role="alert" class="mt-1 text-sm text-red-700">{{ form.errors.request_ids }}</p>
            </div>
            <div>
                <label for="range-from" class="block text-sm font-semibold">Range from (UTC)</label>
                <input id="range-from" v-model="form.range_from" type="datetime-local" class="mt-1 w-full rounded border-slate-300" />
                <p v-if="form.errors.range_from" role="alert" class="mt-1 text-sm text-red-700">{{ form.errors.range_from }}</p>
            </div>
            <div>
                <label for="range-to" class="block text-sm font-semibold">Range to (UTC)</label>
                <input id="range-to" v-model="form.range_to" type="datetime-local" class="mt-1 w-full rounded border-slate-300" />
                <p v-if="form.errors.range_to" role="alert" class="mt-1 text-sm text-red-700">{{ form.errors.range_to }}</p>
            </div>
            <div class="md:col-span-2">
                <label for="reason" class="block text-sm font-semibold">Reason (recorded permanently)</label>
                <textarea id="reason" v-model="form.reason" rows="2" required class="mt-1 w-full rounded border-slate-300"></textarea>
                <p v-if="form.errors.reason" role="alert" class="mt-1 text-sm text-red-700">{{ form.errors.reason }}</p>
            </div>
            <div>
                <label for="confirmation" class="block text-sm font-semibold">Type DELETE to confirm</label>
                <input id="confirmation" v-model="form.confirmation" type="text" required class="mt-1 w-full rounded border-slate-300" />
                <p v-if="form.errors.confirmation" role="alert" class="mt-1 text-sm text-red-700">{{ form.errors.confirmation }}</p>
            </div>
            <div class="flex items-end">
                <button type="submit" :disabled="form.processing" class="rounded bg-red-700 px-4 py-2 font-semibold text-white disabled:opacity-50">
                    Delete telemetry
                </button>
            </div>
        </form>

        <h2 class="mt-10 text-xl font-bold">Deletion history</h2>
        <div class="mt-4 overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Telemetry deletion records</caption>
                <thead class="bg-slate-100">
                    <tr>
                        <th scope="col" class="p-3">When</th>
                        <th scope="col" class="p-3">Actor</th>
                        <th scope="col" class="p-3">Scope</th>
                        <th scope="col" class="p-3">Mode</th>
                        <th scope="col" class="p-3">Application</th>
                        <th scope="col" class="p-3">Matched</th>
                        <th scope="col" class="p-3">Content destroyed</th>
                        <th scope="col" class="p-3">Details redacted</th>
                        <th scope="col" class="p-3">Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="deletion in deletions" :key="deletion.public_id" class="border-t">
                        <td class="p-3">{{ deletion.created_at }}</td>
                        <td class="p-3">{{ deletion.actor }}</td>
                        <td class="p-3">{{ deletion.scope }}</td>
                        <td class="p-3">{{ deletion.mode }}</td>
                        <td class="p-3">{{ deletion.application ?? '—' }}</td>
                        <td class="p-3">{{ deletion.matched_attempts }}</td>
                        <td class="p-3">{{ deletion.content_records_destroyed }}</td>
                        <td class="p-3">{{ deletion.details_redacted }}</td>
                        <td class="p-3">{{ deletion.reason }}</td>
                    </tr>
                    <tr v-if="deletions.length === 0">
                        <td colspan="9" class="p-6 text-center text-slate-500">No deletions have been performed.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </main>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    call: { type: Object, required: true },
    canReveal: { type: Boolean, default: false },
});

const reason = ref('');
const revealed = ref(null);
const error = ref('');
const busy = ref(false);

const reveal = async () => {
    error.value = '';
    busy.value = true;
    try {
        const response = await fetch(`/portal/calls/${props.call.request_id}/reveal`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    (document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? ['', ''])[1],
                ),
            },
            body: JSON.stringify({ reason: reason.value }),
        });
        const payload = await response.json();
        if (!response.ok) {
            error.value = payload.message ?? 'Unable to reveal content.';
            return;
        }
        revealed.value = payload.content;
    } catch {
        error.value = 'Unable to reveal content.';
    } finally {
        busy.value = false;
    }
};

const rows = [
    ['Request ID', 'request_id'],
    ['Application', 'application_name'],
    ['Credential', 'credential_public_id'],
    ['Operation', 'operation'],
    ['Requested model', 'requested_model'],
    ['Resolved alias', 'resolved_model_alias'],
    ['Provider', 'provider_type'],
    ['Provider account', 'provider_public_id'],
    ['Target', 'target_public_id'],
    ['Streaming', 'streaming'],
    ['Outcome', 'status'],
    ['HTTP status', 'http_status'],
    ['Error category', 'error_category'],
    ['Error code', 'error_code'],
    ['Prompt tokens', 'prompt_tokens'],
    ['Completion tokens', 'completion_tokens'],
    ['Cached input tokens', 'cached_input_tokens'],
    ['Total tokens', 'total_tokens'],
    ['Cost (microunits)', 'cost_microunits'],
    ['Currency', 'cost_currency'],
    ['Queue latency (ms)', 'queue_latency_ms'],
    ['Upstream latency (ms)', 'upstream_latency_ms'],
    ['Total latency (ms)', 'total_latency_ms'],
    ['Time to first token (ms)', 'time_to_first_token_ms'],
    ['Retries', 'retry_count'],
    ['Client cancelled', 'client_cancelled'],
    ['Partial response', 'partial_response'],
    ['Upstream correlation ID', 'upstream_correlation_id'],
    ['Alias config version', 'alias_configuration_version'],
    ['Target config version', 'config_version'],
    ['Grant config version', 'grant_configuration_version'],
    ['Application status version', 'application_status_version'],
    ['Pricing version', 'model_pricing_version_id'],
    ['Content state', 'content_state'],
    ['Started at', 'started_at'],
    ['Completed at', 'completed_at'],
];
</script>

<template>
    <Head :title="`Call ${call.request_id}`" />
    <main class="mx-auto max-w-5xl px-6 py-10">
        <Link href="/portal/calls" class="text-bc-blue underline">Call history</Link>
        <h1 class="mt-2 break-all text-2xl font-bold">Call {{ call.request_id }}</h1>

        <dl class="mt-8 grid gap-x-8 gap-y-2 rounded-lg bg-white p-6 shadow-sm sm:grid-cols-2">
            <div v-for="[label, key] in rows" :key="key" class="flex justify-between gap-4 border-b border-slate-100 py-1">
                <dt class="text-sm text-slate-500">{{ label }}</dt>
                <dd class="text-sm font-medium">{{ call[key] === null || call[key] === undefined ? '—' : String(call[key]) }}</dd>
            </div>
        </dl>

        <section v-if="call.client_metadata" class="mt-8 rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">Client metadata</h2>
            <dl class="mt-2">
                <div v-for="(value, key) in call.client_metadata" :key="key" class="flex justify-between border-b border-slate-100 py-1">
                    <dt class="text-sm text-slate-500">{{ key }}</dt>
                    <dd class="text-sm">{{ value }}</dd>
                </div>
            </dl>
        </section>

        <section v-if="call.deletion" class="mt-8 rounded-lg border border-amber-300 bg-amber-50 p-6">
            <h2 class="text-lg font-semibold">Content deleted</h2>
            <p class="mt-1 text-sm">
                Retained content for this call was destroyed on {{ call.deletion.created_at }} under deletion record
                {{ call.deletion.public_id }}. Accounting totals are preserved.
            </p>
        </section>

        <section class="mt-8 rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">Retained content</h2>
            <p v-if="!canReveal" class="mt-2 text-sm text-slate-600">
                No retained content is available to reveal for this call, or you are not authorized to reveal it.
            </p>
            <form v-else class="mt-4 space-y-3" @submit.prevent="reveal">
                <p class="text-sm text-slate-600">
                    Revealing content discloses the exact prompt and response this application exchanged with the model.
                    The disclosure is recorded in the security audit log with your identity and the reason below.
                </p>
                <label for="reveal-reason" class="block text-sm font-semibold">Reason for reveal</label>
                <input
                    id="reveal-reason"
                    v-model="reason"
                    type="text"
                    required
                    minlength="5"
                    maxlength="200"
                    class="w-full rounded border-slate-300"
                    aria-describedby="reveal-error"
                />
                <p v-if="error" id="reveal-error" role="alert" class="text-sm font-semibold text-red-700">{{ error }}</p>
                <button type="submit" :disabled="busy" class="rounded bg-bc-blue px-4 py-2 font-semibold text-white disabled:opacity-50">
                    {{ busy ? 'Revealing…' : 'Reveal content' }}
                </button>
            </form>

            <div v-if="revealed" class="mt-6 space-y-4">
                <div>
                    <h3 class="font-semibold">Request <span v-if="revealed.request_truncated" class="text-amber-700">(truncated)</span></h3>
                    <pre class="mt-1 max-h-96 overflow-auto rounded bg-slate-900 p-4 text-xs text-slate-100">{{ revealed.request }}</pre>
                </div>
                <div>
                    <h3 class="font-semibold">Response <span v-if="revealed.response_truncated" class="text-amber-700">(truncated)</span></h3>
                    <pre class="mt-1 max-h-96 overflow-auto rounded bg-slate-900 p-4 text-xs text-slate-100">{{ revealed.response }}</pre>
                </div>
            </div>
        </section>
    </main>
</template>

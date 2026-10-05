<script setup>
import { Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';

defineProps({
    call: { type: Object, required: true },
});

const rows = [
    ['Request ID', 'request_id'],
    ['Application', 'application_name'],
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
    <PortalLayout>
        <Link href="/portal/calls" class="text-bc-blue">← Call history</Link>
        <h2 class="h3 fw-bold mt-2 text-break">Call {{ call.request_id }}</h2>

        <div class="card shadow-sm mt-4">
            <div class="card-body">
                <dl class="row mb-0">
                    <template v-for="[label, key] in rows" :key="key">
                        <dt class="col-6 col-lg-3 text-secondary fw-normal small border-bottom py-2">{{ label }}</dt>
                        <dd class="col-6 col-lg-3 fw-medium small border-bottom py-2">{{ call[key] === null || call[key] === undefined ? '—' : String(call[key]) }}</dd>
                    </template>
                </dl>
            </div>
        </div>

        <section v-if="call.client_metadata" class="card shadow-sm mt-4">
            <div class="card-body">
                <h3 class="h5 fw-semibold">Client metadata</h3>
                <dl class="row mb-0 mt-2">
                    <template v-for="(value, key) in call.client_metadata" :key="key">
                        <dt class="col-6 text-secondary fw-normal small border-bottom py-1">{{ key }}</dt>
                        <dd class="col-6 small border-bottom py-1">{{ value }}</dd>
                    </template>
                </dl>
            </div>
        </section>

        <section v-if="call.deletion" class="alert alert-warning mt-4">
            <h3 class="h5 fw-semibold">Content deleted</h3>
            <p class="mb-0 small">
                Retained content for this call was destroyed on {{ call.deletion.created_at }} under deletion record
                {{ call.deletion.public_id }}. Accounting totals are preserved.
            </p>
        </section>
    </PortalLayout>
</template>

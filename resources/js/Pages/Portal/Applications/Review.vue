<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import MachineCredentials from '../../../Components/MachineCredentials.vue';
const props = defineProps({
    application: { type: Object, required: true },
    transitions: { type: Array, required: true },
    machineCredentials: { type: Array, required: true },
    machineAbilities: { type: Object, required: true },
    defaultRotationOverlapSeconds: { type: Number, required: true },
    usageProjection: { type: Object, required: true },
});
const transitionForm = useForm({ transition: 'approve', note: '', status_version: props.application.status_version });
const configurationForm = useForm({
    status_version: props.application.status_version,
    prompt_response_retention_enabled: props.application.prompt_response_retention_enabled,
    rate_limit_per_minute: props.application.rate_limit_per_minute,
    token_rate_per_minute: props.application.token_rate_per_minute,
    token_budget_daily: props.application.token_budget_daily,
    token_budget_monthly: props.application.token_budget_monthly,
    cost_budget_daily: props.application.cost_budget_daily,
    cost_budget_monthly: props.application.cost_budget_monthly,
    budget_currency: props.application.budget_currency,
    configuration_ready: props.application.configuration_ready,
});
const adjustmentForm = useForm({ token_adjustment: null, cost_adjustment: null, reason: '' });
const transition = () => transitionForm.post(`/portal/admin/applications/${props.application.public_id}/transitions`);
const configure = () => configurationForm.patch(`/portal/admin/applications/${props.application.public_id}/configuration`);
const adjustQuota = () => adjustmentForm.post(`/portal/admin/applications/${props.application.public_id}/quota-adjustments`, {
    onSuccess: () => adjustmentForm.reset(),
});

watch(() => props.application, (application) => {
    transitionForm.status_version = application.status_version;
    configurationForm.status_version = application.status_version;
    configurationForm.prompt_response_retention_enabled = application.prompt_response_retention_enabled;
    configurationForm.rate_limit_per_minute = application.rate_limit_per_minute;
    configurationForm.token_rate_per_minute = application.token_rate_per_minute;
    configurationForm.token_budget_daily = application.token_budget_daily;
    configurationForm.token_budget_monthly = application.token_budget_monthly;
    configurationForm.cost_budget_daily = application.cost_budget_daily;
    configurationForm.cost_budget_monthly = application.cost_budget_monthly;
    configurationForm.budget_currency = application.budget_currency;
    configurationForm.configuration_ready = application.configuration_ready;
}, { deep: true });
</script>
<template>
    <Head :title="`Review ${application.name}`" />
    <main class="mx-auto max-w-5xl px-6 py-10"><Link href="/portal/admin/applications" class="text-bc-blue underline">Application review</Link><h1 class="mt-2 text-3xl font-bold">{{ application.name }}</h1><p class="mt-2">{{ application.status }} · version {{ application.status_version }}</p>
        <section class="mt-8 rounded-lg bg-white p-6 shadow-sm"><h2 class="text-xl font-bold">Request</h2><p class="mt-3 whitespace-pre-wrap">{{ application.purpose_use_case }}</p><p class="mt-3"><strong>API Directory client ID:</strong> {{ application.api_directory_client_id }}</p><p class="mt-3">Models: {{ application.requested_models.join(', ') }}</p><p>Capabilities: {{ application.requested_capabilities.join(', ') }}</p></section>
        <form class="mt-8 space-y-4 rounded-lg bg-white p-6 shadow-sm" @submit.prevent="configure"><h2 class="text-xl font-bold">Administrator configuration</h2>
            <label class="block"><input v-model="configurationForm.prompt_response_retention_enabled" type="checkbox"> Prompt/response retention enabled</label>
            <label class="block">Rate limit per minute <input v-model="configurationForm.rate_limit_per_minute" type="number" class="ml-2 rounded border p-2"></label>
            <label class="block">Token rate per minute <input v-model="configurationForm.token_rate_per_minute" type="number" class="ml-2 rounded border p-2"></label>
            <label class="block">Daily token budget <input v-model="configurationForm.token_budget_daily" type="number" class="ml-2 rounded border p-2"></label>
            <label class="block">Monthly token budget <input v-model="configurationForm.token_budget_monthly" type="number" class="ml-2 rounded border p-2"></label>
            <label class="block">Daily cost budget <input v-model="configurationForm.cost_budget_daily" type="number" step="0.01" class="ml-2 rounded border p-2"></label>
            <label class="block">Monthly cost budget <input v-model="configurationForm.cost_budget_monthly" type="number" step="0.01" class="ml-2 rounded border p-2"></label>
            <label class="block">Budget currency <input v-model="configurationForm.budget_currency" maxlength="3" class="ml-2 rounded border p-2 uppercase"></label>
            <label class="block"><input v-model="configurationForm.configuration_ready" type="checkbox"> Configuration ready for activation</label>
            <p v-if="Object.keys(configurationForm.errors).length" class="text-red-700" role="alert">{{ Object.values(configurationForm.errors)[0] }}</p><button class="rounded bg-bc-blue px-4 py-2 font-semibold text-white">Save configuration</button>
        </form>
        <section class="mt-8 rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-xl font-bold">Current usage ({{ usageProjection.timezone }})</h2>
            <p class="mt-2">Daily actual/projected: {{ usageProjection.daily.tokens }} / {{ usageProjection.daily.projected_tokens }} tokens, {{ usageProjection.daily.cost }} / {{ usageProjection.daily.projected_cost }} {{ usageProjection.currency }}</p>
            <p>Monthly actual/projected: {{ usageProjection.monthly.tokens }} / {{ usageProjection.monthly.projected_tokens }} tokens, {{ usageProjection.monthly.cost }} / {{ usageProjection.monthly.projected_cost }} {{ usageProjection.currency }}</p>
        </section>
        <form class="mt-8 space-y-4 rounded-lg bg-white p-6 shadow-sm" @submit.prevent="adjustQuota">
            <h2 class="text-xl font-bold">Audited quota adjustment</h2>
            <p class="text-sm text-slate-600">Signed adjustments apply to the current UTC daily and monthly counters without changing historical entries.</p>
            <label class="block">Token adjustment <input v-model="adjustmentForm.token_adjustment" type="number" class="ml-2 rounded border p-2"></label>
            <label class="block">Cost adjustment ({{ application.budget_currency }}) <input v-model="adjustmentForm.cost_adjustment" type="number" step="0.000001" class="ml-2 rounded border p-2"></label>
            <label class="block">Reason<textarea v-model="adjustmentForm.reason" rows="3" class="mt-1 w-full rounded border p-2" /></label>
            <p v-if="Object.keys(adjustmentForm.errors).length" class="text-red-700" role="alert">{{ Object.values(adjustmentForm.errors)[0] }}</p>
            <button class="rounded bg-bc-blue px-4 py-2 font-semibold text-white">Apply adjustment</button>
        </form>
        <form class="mt-8 space-y-4 rounded-lg bg-white p-6 shadow-sm" @submit.prevent="transition"><h2 class="text-xl font-bold">Lifecycle action</h2>
            <select v-model="transitionForm.transition" class="rounded border p-2"><option v-for="value in transitions" :key="value" :value="value">{{ value }}</option></select>
            <label class="block">Review note<textarea v-model="transitionForm.note" rows="4" class="mt-1 w-full rounded border p-2" /></label>
            <p v-if="Object.keys(transitionForm.errors).length" class="text-red-700" role="alert">{{ Object.values(transitionForm.errors)[0] }}</p><button class="rounded bg-bc-blue px-4 py-2 font-semibold text-white">Apply transition</button>
        </form>
        <MachineCredentials
            :application="application"
            :credentials="machineCredentials"
            :abilities="machineAbilities"
            :can-manage="true"
            :default-rotation-overlap-seconds="defaultRotationOverlapSeconds"
        />
    </main>
</template>

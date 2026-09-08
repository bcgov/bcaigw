<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    providers: { type: Array, required: true },
    targets: { type: Array, required: true },
    aliases: { type: Array, required: true },
    grants: { type: Array, required: true },
    applications: { type: Array, required: true },
    options: { type: Object, required: true },
});

const providerForm = useForm({
    name: '',
    type: 'aws_bedrock',
    environment: 'development',
    region: '',
    secret_reference: '',
    configuration_json: '{}',
    sensitive_configuration_json: '',
    status: 'active',
});
const targetForm = useForm({
    provider_public_id: '',
    name: '',
    environment: 'development',
    region: '',
    base_url: '',
    provider_model_identifier: '',
    capabilities: ['chat'],
    context_window: 128000,
    max_input_tokens: 120000,
    max_output_tokens: 8000,
    status: 'active',
    timeout_seconds: 30,
    verify_tls: true,
    max_connections: 20,
});
const aliasForm = useForm({
    model_id: '',
    display_name: '',
    description: '',
    capabilities: ['chat'],
    target_public_id: '',
    status: 'disabled',
});
const pricingForm = useForm({
    model_alias_public_id: '',
    effective_at: '',
    currency: 'CAD',
    input_cost_per_million_tokens: '',
    output_cost_per_million_tokens: '',
    cached_input_cost_per_million_tokens: '',
});
const grantForm = useForm({
    application_public_id: '',
    model_alias_public_id: '',
    capabilities: ['chat'],
    enabled: true,
    configuration_version: 0,
});
const aliasTargets = reactive(Object.fromEntries(
    props.aliases.map((alias) => [alias.public_id, alias.target_public_id || '']),
));

const providerPayload = (provider, status) => ({
    name: provider.name,
    type: provider.type,
    environment: provider.environment,
    region: provider.region,
    secret_reference: provider.secret_reference,
    configuration_json: JSON.stringify(provider.configuration || {}),
    sensitive_configuration_json: '',
    status,
    configuration_version: provider.configuration_version,
});
const targetPayload = (target, status) => ({
    provider_public_id: target.provider_public_id,
    name: target.name,
    environment: target.environment,
    region: target.region,
    base_url: target.base_url,
    provider_model_identifier: target.provider_model_identifier,
    capabilities: target.capabilities,
    context_window: target.context_window,
    max_input_tokens: target.max_input_tokens,
    max_output_tokens: target.max_output_tokens,
    status,
    timeout_seconds: target.timeout_seconds,
    verify_tls: true,
    max_connections: target.connection_settings?.max_connections || 20,
    configuration_version: target.configuration_version,
});
const aliasPayload = (alias, status = alias.status) => ({
    model_id: alias.model_id,
    display_name: alias.display_name,
    description: alias.description,
    capabilities: alias.capabilities,
    target_public_id: aliasTargets[alias.public_id],
    status,
    configuration_version: alias.configuration_version,
});
const updateProvider = (provider, status) => router.patch(
    `/portal/admin/model-control/providers/${provider.public_id}`,
    providerPayload(provider, status),
);
const updateTarget = (target, status) => router.patch(
    `/portal/admin/model-control/targets/${target.public_id}`,
    targetPayload(target, status),
);
const updateAlias = (alias, status = alias.status) => router.patch(
    `/portal/admin/model-control/aliases/${alias.public_id}`,
    aliasPayload(alias, status),
);
const checkHealth = (target) => router.post(
    `/portal/admin/model-control/targets/${target.public_id}/health`,
);
const toggleGrant = (grant) => router.post('/portal/admin/model-control/grants', {
    application_public_id: grant.application_public_id,
    model_alias_public_id: grant.model_alias_public_id,
    capabilities: grant.capabilities,
    enabled: !grant.enabled,
    configuration_version: grant.configuration_version,
});
const submitPricing = () => pricingForm.post(
    `/portal/admin/model-control/aliases/${pricingForm.model_alias_public_id}/pricing`,
);
</script>

<template>
    <Head title="Model control plane" />
    <main class="mx-auto max-w-7xl px-6 py-10">
        <Link href="/portal/admin" class="text-bc-blue underline">Administration</Link>
        <h1 class="mt-2 text-3xl font-bold">Model control plane</h1>

        <section class="mt-8 rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-xl font-bold">Provider accounts</h2>
            <form class="mt-4 grid gap-3 md:grid-cols-3" @submit.prevent="providerForm.post('/portal/admin/model-control/providers')">
                <label>Name <input v-model="providerForm.name" required class="block w-full rounded border p-2"></label>
                <label>Type <select v-model="providerForm.type" class="block w-full rounded border p-2"><option v-for="value in options.providerTypes" :key="value">{{ value }}</option></select></label>
                <label>Environment <select v-model="providerForm.environment" class="block w-full rounded border p-2"><option v-for="value in options.environments" :key="value">{{ value }}</option></select></label>
                <label>Region <input v-model="providerForm.region" class="block w-full rounded border p-2"></label>
                <label>Workload identity / secret reference <input v-model="providerForm.secret_reference" class="block w-full rounded border p-2"></label>
                <label>Non-sensitive configuration JSON <textarea v-model="providerForm.configuration_json" class="block w-full rounded border p-2" /></label>
                <label>Controlled encrypted fallback JSON <textarea v-model="providerForm.sensitive_configuration_json" class="block w-full rounded border p-2" /></label>
                <button class="rounded bg-bc-blue px-4 py-2 font-semibold text-white">Create provider</button>
                <p v-if="Object.keys(providerForm.errors).length" class="text-red-700" role="alert">{{ Object.values(providerForm.errors)[0] }}</p>
            </form>
            <div class="mt-5 space-y-3">
                <article v-for="provider in providers" :key="provider.public_id" class="rounded border p-3">
                    <strong>{{ provider.name }}</strong> · {{ provider.type }} · {{ provider.environment }} · {{ provider.status }} · v{{ provider.configuration_version }}
                    <span v-if="provider.has_encrypted_fallback" class="ml-2 text-amber-800">encrypted fallback configured</span>
                    <div class="mt-2 flex gap-3">
                        <button class="text-bc-blue underline" @click="updateProvider(provider, 'active')">Enable</button>
                        <button class="text-bc-blue underline" @click="updateProvider(provider, 'disabled')">Disable</button>
                        <button class="text-red-700 underline" @click="updateProvider(provider, 'retired')">Retire</button>
                    </div>
                </article>
            </div>
        </section>

        <section class="mt-8 rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-xl font-bold">Upstream targets</h2>
            <form class="mt-4 grid gap-3 md:grid-cols-3" @submit.prevent="targetForm.post('/portal/admin/model-control/targets')">
                <label>Provider <select v-model="targetForm.provider_public_id" required class="block w-full rounded border p-2"><option value="" disabled>Select</option><option v-for="provider in providers" :key="provider.public_id" :value="provider.public_id">{{ provider.name }}</option></select></label>
                <label>Name <input v-model="targetForm.name" required class="block w-full rounded border p-2"></label>
                <label>Environment <select v-model="targetForm.environment" class="block w-full rounded border p-2"><option v-for="value in options.environments" :key="value">{{ value }}</option></select></label>
                <label>Region <input v-model="targetForm.region" class="block w-full rounded border p-2"></label>
                <label>HTTPS base URL <input v-model="targetForm.base_url" required class="block w-full rounded border p-2"></label>
                <label>Provider model/deployment ID <input v-model="targetForm.provider_model_identifier" required class="block w-full rounded border p-2"></label>
                <fieldset><legend>Capabilities</legend><label v-for="capability in options.capabilities" :key="capability" class="mr-3"><input v-model="targetForm.capabilities" type="checkbox" :value="capability"> {{ capability }}</label></fieldset>
                <label>Context window <input v-model.number="targetForm.context_window" type="number" class="block w-full rounded border p-2"></label>
                <label>Max input <input v-model.number="targetForm.max_input_tokens" type="number" class="block w-full rounded border p-2"></label>
                <label>Max output <input v-model.number="targetForm.max_output_tokens" type="number" class="block w-full rounded border p-2"></label>
                <label>Timeout seconds <input v-model.number="targetForm.timeout_seconds" type="number" class="block w-full rounded border p-2"></label>
                <label>Max connections <input v-model.number="targetForm.max_connections" type="number" class="block w-full rounded border p-2"></label>
                <button class="rounded bg-bc-blue px-4 py-2 font-semibold text-white">Create target</button>
                <p v-if="Object.keys(targetForm.errors).length" class="text-red-700" role="alert">{{ Object.values(targetForm.errors)[0] }}</p>
            </form>
            <div class="mt-5 space-y-3">
                <article v-for="target in targets" :key="target.public_id" class="rounded border p-3">
                    <strong>{{ target.name }}</strong> · {{ target.provider_name }} · {{ target.status }} · health {{ target.health_status }} · v{{ target.configuration_version }}
                    <p class="break-all text-sm">{{ target.base_url }} · {{ target.provider_model_identifier }} · {{ target.capabilities.join(', ') }}</p>
                    <div class="mt-2 flex gap-3">
                        <button class="text-bc-blue underline" @click="checkHealth(target)">Check health</button>
                        <button class="text-bc-blue underline" @click="updateTarget(target, 'active')">Enable</button>
                        <button class="text-bc-blue underline" @click="updateTarget(target, 'disabled')">Disable</button>
                        <button class="text-red-700 underline" @click="updateTarget(target, 'retired')">Retire</button>
                    </div>
                </article>
            </div>
        </section>

        <section class="mt-8 rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-xl font-bold">Public model aliases</h2>
            <form class="mt-4 grid gap-3 md:grid-cols-3" @submit.prevent="aliasForm.post('/portal/admin/model-control/aliases')">
                <label>OpenAI-compatible model ID <input v-model="aliasForm.model_id" required class="block w-full rounded border p-2"></label>
                <label>Display name <input v-model="aliasForm.display_name" required class="block w-full rounded border p-2"></label>
                <label>Target <select v-model="aliasForm.target_public_id" class="block w-full rounded border p-2"><option value="">None</option><option v-for="target in targets" :key="target.public_id" :value="target.public_id">{{ target.name }}</option></select></label>
                <fieldset><legend>Capabilities</legend><label v-for="capability in options.capabilities" :key="capability" class="mr-3"><input v-model="aliasForm.capabilities" type="checkbox" :value="capability"> {{ capability }}</label></fieldset>
                <label>Status <select v-model="aliasForm.status" class="block w-full rounded border p-2"><option v-for="value in options.statuses" :key="value">{{ value }}</option></select></label>
                <label>Description <textarea v-model="aliasForm.description" class="block w-full rounded border p-2" /></label>
                <button class="rounded bg-bc-blue px-4 py-2 font-semibold text-white">Create alias</button>
                <p v-if="Object.keys(aliasForm.errors).length" class="text-red-700" role="alert">{{ Object.values(aliasForm.errors)[0] }}</p>
            </form>
            <div class="mt-5 space-y-3">
                <article v-for="alias in aliases" :key="alias.public_id" class="rounded border p-3">
                    <strong>{{ alias.display_name }}</strong> · <span class="font-mono">{{ alias.model_id }}</span> · {{ alias.status }} · v{{ alias.configuration_version }}
                    <div class="mt-2 flex flex-wrap gap-3">
                        <select v-model="aliasTargets[alias.public_id]" class="rounded border p-2"><option value="">None</option><option v-for="target in targets" :key="target.public_id" :value="target.public_id">{{ target.name }}</option></select>
                        <button class="text-bc-blue underline" @click="updateAlias(alias)">Save mapping</button>
                        <button class="text-bc-blue underline" @click="updateAlias(alias, 'active')">Activate</button>
                        <button class="text-bc-blue underline" @click="updateAlias(alias, 'disabled')">Disable</button>
                        <button class="text-red-700 underline" @click="updateAlias(alias, 'retired')">Retire</button>
                    </div>
                </article>
            </div>
        </section>

        <section class="mt-8 grid gap-8 lg:grid-cols-2">
            <form class="space-y-3 rounded-lg bg-white p-6 shadow-sm" @submit.prevent="submitPricing">
                <h2 class="text-xl font-bold">Add pricing version</h2>
                <label>Alias <select v-model="pricingForm.model_alias_public_id" required class="block w-full rounded border p-2"><option v-for="alias in aliases" :key="alias.public_id" :value="alias.public_id">{{ alias.model_id }}</option></select></label>
                <label>Effective at <input v-model="pricingForm.effective_at" required type="datetime-local" class="block w-full rounded border p-2"></label>
                <label>Currency <input v-model="pricingForm.currency" required class="block w-full rounded border p-2"></label>
                <label>Input / 1M tokens <input v-model="pricingForm.input_cost_per_million_tokens" required type="number" step="0.00000001" class="block w-full rounded border p-2"></label>
                <label>Output / 1M tokens <input v-model="pricingForm.output_cost_per_million_tokens" required type="number" step="0.00000001" class="block w-full rounded border p-2"></label>
                <label>Cached input / 1M tokens <input v-model="pricingForm.cached_input_cost_per_million_tokens" type="number" step="0.00000001" class="block w-full rounded border p-2"></label>
                <button class="rounded bg-bc-blue px-4 py-2 font-semibold text-white">Add immutable pricing</button>
                <p v-if="Object.keys(pricingForm.errors).length" class="text-red-700" role="alert">{{ Object.values(pricingForm.errors)[0] }}</p>
            </form>
            <form class="space-y-3 rounded-lg bg-white p-6 shadow-sm" @submit.prevent="grantForm.post('/portal/admin/model-control/grants')">
                <h2 class="text-xl font-bold">Grant model to application</h2>
                <label>Application <select v-model="grantForm.application_public_id" required class="block w-full rounded border p-2"><option v-for="application in applications" :key="application.public_id" :value="application.public_id">{{ application.name }} · {{ application.status }}</option></select></label>
                <label>Alias <select v-model="grantForm.model_alias_public_id" required class="block w-full rounded border p-2"><option v-for="alias in aliases" :key="alias.public_id" :value="alias.public_id">{{ alias.model_id }}</option></select></label>
                <fieldset><legend>Capabilities</legend><label v-for="capability in options.capabilities" :key="capability" class="mr-3"><input v-model="grantForm.capabilities" type="checkbox" :value="capability"> {{ capability }}</label></fieldset>
                <label><input v-model="grantForm.enabled" type="checkbox"> Enabled</label>
                <button class="rounded bg-bc-blue px-4 py-2 font-semibold text-white">Save grant</button>
                <p v-if="Object.keys(grantForm.errors).length" class="text-red-700" role="alert">{{ Object.values(grantForm.errors)[0] }}</p>
            </form>
        </section>

        <section class="mt-8 rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-xl font-bold">Application grants</h2>
            <ul class="mt-3 divide-y"><li v-for="grant in grants" :key="grant.public_id" class="flex justify-between py-3"><span>{{ grant.application_name }} · {{ grant.model_id }} · {{ grant.capabilities.join(', ') }} · {{ grant.enabled ? 'enabled' : 'disabled' }} · v{{ grant.configuration_version }}</span><button class="text-bc-blue underline" @click="toggleGrant(grant)">{{ grant.enabled ? 'Disable' : 'Enable' }}</button></li></ul>
        </section>
    </main>
</template>

<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    application: { type: Object, default: null },
    options: { type: Object, required: true },
});

const form = useForm({
    api_directory_client_id: props.application?.api_directory_client_id ?? '',
    name: props.application?.name ?? '',
    ministry_organization: props.application?.ministry_organization ?? '',
    purpose_use_case: props.application?.purpose_use_case ?? '',
    primary_contact_name: props.application?.primary_contact_name ?? '',
    primary_contact_email: props.application?.primary_contact_email ?? '',
    technical_contact_name: props.application?.technical_contact_name ?? '',
    technical_contact_email: props.application?.technical_contact_email ?? '',
    environments: props.application?.environments ?? [],
    expected_requests_per_minute: props.application?.expected_requests_per_minute ?? 60,
    expected_tokens_per_month: props.application?.expected_tokens_per_month ?? 1000000,
    data_classification: props.application?.data_classification ?? 'internal',
    requested_models: props.application?.requested_models ?? [],
    requested_capabilities: props.application?.requested_capabilities ?? [],
    status_version: props.application?.status_version ?? undefined,
});

const submit = () => {
    if (props.application) {
        form.put(`/portal/applications/${props.application.public_id}`);
    } else {
        form.post('/portal/applications');
    }
};
</script>

<template>
    <Head :title="application ? 'Edit application' : 'Create application'" />
    <main class="mx-auto max-w-3xl px-6 py-10">
        <Link href="/portal/applications" class="text-bc-blue underline">Applications</Link>
        <h1 class="mt-2 text-3xl font-bold">{{ application ? 'Edit application' : 'Create application' }}</h1>
        <form class="mt-8 space-y-6 rounded-lg bg-white p-8 shadow-sm" @submit.prevent="submit">
            <div>
                <label for="api_directory_client_id" class="block font-semibold">API Directory client ID</label>
                <input id="api_directory_client_id" v-model="form.api_directory_client_id" class="mt-1 w-full rounded border p-2" :aria-invalid="Boolean(form.errors.api_directory_client_id)">
                <p class="mt-1 text-sm text-slate-600">Enter the CLIENT_ID issued when API access is registered through the BC Data API Directory.</p>
                <p v-if="form.errors.api_directory_client_id" class="mt-1 text-sm text-red-700" role="alert">{{ form.errors.api_directory_client_id }}</p>
            </div>
            <div v-for="field in ['name', 'ministry_organization', 'primary_contact_name', 'primary_contact_email', 'technical_contact_name', 'technical_contact_email']" :key="field">
                <label :for="field" class="block font-semibold">{{ field.replaceAll('_', ' ') }}</label>
                <input :id="field" v-model="form[field]" class="mt-1 w-full rounded border p-2" :aria-invalid="Boolean(form.errors[field])">
                <p v-if="form.errors[field]" class="mt-1 text-sm text-red-700" role="alert">{{ form.errors[field] }}</p>
            </div>
            <div>
                <label for="purpose_use_case" class="block font-semibold">Purpose and use case</label>
                <textarea id="purpose_use_case" v-model="form.purpose_use_case" rows="6" class="mt-1 w-full rounded border p-2" />
                <p v-if="form.errors.purpose_use_case" class="text-sm text-red-700" role="alert">{{ form.errors.purpose_use_case }}</p>
            </div>
            <fieldset>
                <legend class="font-semibold">Environments</legend>
                <label v-for="environment in options.environments" :key="environment" class="mr-5 inline-flex gap-2"><input v-model="form.environments" type="checkbox" :value="environment">{{ environment }}</label>
                <p v-if="form.errors.environments" class="text-sm text-red-700" role="alert">{{ form.errors.environments }}</p>
            </fieldset>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="font-semibold">Expected requests per minute<input v-model="form.expected_requests_per_minute" type="number" class="mt-1 w-full rounded border p-2"></label>
                <label class="font-semibold">Expected tokens per month<input v-model="form.expected_tokens_per_month" type="number" class="mt-1 w-full rounded border p-2"></label>
            </div>
            <label class="block font-semibold">Data classification
                <select v-model="form.data_classification" class="mt-1 w-full rounded border p-2"><option v-for="value in options.classifications" :key="value" :value="value">{{ value }}</option></select>
            </label>
            <fieldset><legend class="font-semibold">Requested models</legend><label v-for="(label, value) in options.models" :key="value" class="block"><input v-model="form.requested_models" type="checkbox" :value="value"> {{ label }}</label></fieldset>
            <fieldset><legend class="font-semibold">Capabilities</legend><label v-for="(label, value) in options.capabilities" :key="value" class="block"><input v-model="form.requested_capabilities" type="checkbox" :value="value"> {{ label }}</label></fieldset>
            <div v-if="Object.keys(form.errors).length" class="rounded border border-red-300 bg-red-50 p-4 text-red-800" role="alert">Review the highlighted fields.</div>
            <button :disabled="form.processing" class="rounded bg-bc-blue px-5 py-3 font-semibold text-white disabled:opacity-50">Save application</button>
        </form>
    </main>
</template>

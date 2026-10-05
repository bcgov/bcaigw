<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    application: { type: Object, default: null },
    options: {
        type: Object,
        default: () => ({ environments: {}, classifications: {}, models: [] }),
    },
});

const isEdit = Boolean(props.application);

const initialCapabilities =
    props.application && !Array.isArray(props.application.requested_capabilities)
        ? { ...props.application.requested_capabilities }
        : {};

const form = useForm({
    name: props.application?.name ?? '',
    api_directory_client_id: props.application?.api_directory_client_id ?? '',
    ministry_organization: props.application?.ministry_organization ?? '',
    purpose_use_case: props.application?.purpose_use_case ?? '',
    primary_contact_name: props.application?.primary_contact_name ?? '',
    primary_contact_email: props.application?.primary_contact_email ?? '',
    technical_contact_name: props.application?.technical_contact_name ?? '',
    technical_contact_email: props.application?.technical_contact_email ?? '',
    environments: props.application?.environments ?? [],
    expected_requests_per_minute: props.application?.expected_requests_per_minute ?? 60,
    expected_tokens_per_month: props.application?.expected_tokens_per_month ?? 1000000,
    data_classification: props.application?.data_classification ?? '',
    requested_models: props.application?.requested_models ?? [],
    requested_capabilities: initialCapabilities,
    status_version: props.application?.status_version ?? 0,
});

// Keep per-model capability selections aligned with the selected models.
watch(
    () => form.requested_models.slice(),
    (models) => {
        const next = {};
        for (const id of models) {
            next[id] = Array.isArray(form.requested_capabilities[id]) ? form.requested_capabilities[id] : [];
        }
        form.requested_capabilities = next;
    },
    { immediate: true },
);

const submit = () => {
    if (isEdit) {
        form.put(`/portal/applications/${props.application.public_id}`);
    } else {
        form.post('/portal/applications');
    }
};
</script>

<template>
    <PortalLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h2 class="h3 fw-bold mb-0">{{ isEdit ? 'Edit application' : 'New application' }}</h2>
            <Link href="/portal/applications" class="text-bc-blue">Back to applications</Link>
        </div>

        <form class="mt-4" @submit.prevent="submit">
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h3 class="h5 fw-bold">Details</h3>
                    <div class="row g-3 mt-1">
                        <div class="col-sm-6">
                            <label class="form-label">Name</label>
                            <input v-model="form.name" type="text" class="form-control" :class="{ 'is-invalid': form.errors.name }" />
                            <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">API directory client ID</label>
                            <input v-model="form.api_directory_client_id" type="text" class="form-control" :class="{ 'is-invalid': form.errors.api_directory_client_id }" />
                            <div v-if="form.errors.api_directory_client_id" class="invalid-feedback">{{ form.errors.api_directory_client_id }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Ministry / organization</label>
                            <input v-model="form.ministry_organization" type="text" class="form-control" :class="{ 'is-invalid': form.errors.ministry_organization }" />
                            <div v-if="form.errors.ministry_organization" class="invalid-feedback">{{ form.errors.ministry_organization }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Purpose / use case</label>
                            <textarea v-model="form.purpose_use_case" rows="4" class="form-control" :class="{ 'is-invalid': form.errors.purpose_use_case }"></textarea>
                            <div v-if="form.errors.purpose_use_case" class="invalid-feedback">{{ form.errors.purpose_use_case }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h3 class="h5 fw-bold">Contacts</h3>
                    <div class="row g-3 mt-1">
                        <div class="col-sm-6">
                            <label class="form-label">Primary contact name</label>
                            <input v-model="form.primary_contact_name" type="text" class="form-control" :class="{ 'is-invalid': form.errors.primary_contact_name }" />
                            <div v-if="form.errors.primary_contact_name" class="invalid-feedback">{{ form.errors.primary_contact_name }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Primary contact email</label>
                            <input v-model="form.primary_contact_email" type="email" class="form-control" :class="{ 'is-invalid': form.errors.primary_contact_email }" />
                            <div v-if="form.errors.primary_contact_email" class="invalid-feedback">{{ form.errors.primary_contact_email }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Technical contact name</label>
                            <input v-model="form.technical_contact_name" type="text" class="form-control" />
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Technical contact email</label>
                            <input v-model="form.technical_contact_email" type="email" class="form-control" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h3 class="h5 fw-bold">Usage &amp; classification</h3>
                    <div class="row g-3 mt-1">
                        <div class="col-sm-6">
                            <label class="form-label">Expected requests / minute</label>
                            <input v-model.number="form.expected_requests_per_minute" type="number" min="1" class="form-control" :class="{ 'is-invalid': form.errors.expected_requests_per_minute }" />
                            <div v-if="form.errors.expected_requests_per_minute" class="invalid-feedback">{{ form.errors.expected_requests_per_minute }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Expected tokens / month</label>
                            <input v-model.number="form.expected_tokens_per_month" type="number" min="1" class="form-control" :class="{ 'is-invalid': form.errors.expected_tokens_per_month }" />
                            <div v-if="form.errors.expected_tokens_per_month" class="invalid-feedback">{{ form.errors.expected_tokens_per_month }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Data classification</label>
                            <select v-model="form.data_classification" class="form-select" :class="{ 'is-invalid': form.errors.data_classification }">
                                <option value="" disabled>Select a classification</option>
                                <option v-for="(label, key) in options.classifications" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <div v-if="form.errors.data_classification" class="invalid-feedback">{{ form.errors.data_classification }}</div>
                        </div>
                    </div>

                    <fieldset class="mt-4">
                        <legend class="form-label">Environments</legend>
                        <div class="d-flex flex-wrap gap-3">
                            <div v-for="(label, key) in options.environments" :key="key" class="form-check">
                                <input :id="`env-${key}`" v-model="form.environments" type="checkbox" :value="key" class="form-check-input" />
                                <label :for="`env-${key}`" class="form-check-label">{{ label }}</label>
                            </div>
                        </div>
                        <div v-if="form.errors.environments" class="text-danger small mt-1">{{ form.errors.environments }}</div>
                    </fieldset>

                    <fieldset class="mt-4">
                        <legend class="form-label">Requested models &amp; capabilities</legend>
                        <p class="text-secondary small mt-n1">Select the models your application needs, then choose the capabilities you'll use for each. This list reflects the models enabled by administrators, and available capabilities differ per model.</p>
                        <div v-if="options.models.length === 0" class="text-secondary small">
                            No models are currently available. Please contact an administrator.
                        </div>
                        <div v-else class="vstack gap-3">
                            <div v-for="model in options.models" :key="model.id" class="border rounded p-3">
                                <div class="form-check">
                                    <input :id="`model-${model.id}`" v-model="form.requested_models" type="checkbox" :value="model.id" class="form-check-input" />
                                    <label :for="`model-${model.id}`" class="form-check-label fw-semibold">{{ model.name }}</label>
                                </div>
                                <div v-if="form.requested_models.includes(model.id)" class="ms-4 mt-2">
                                    <div class="text-secondary small mb-1">Capabilities</div>
                                    <div class="d-flex flex-wrap gap-3">
                                        <div v-for="(label, key) in model.capabilities" :key="key" class="form-check">
                                            <input :id="`cap-${model.id}-${key}`" v-model="form.requested_capabilities[model.id]" type="checkbox" :value="key" class="form-check-input" />
                                            <label :for="`cap-${model.id}-${key}`" class="form-check-label">{{ label }}</label>
                                        </div>
                                    </div>
                                    <div v-if="form.errors[`requested_capabilities.${model.id}`]" class="text-danger small mt-1">{{ form.errors[`requested_capabilities.${model.id}`] }}</div>
                                </div>
                            </div>
                        </div>
                        <div v-if="form.errors.requested_models" class="text-danger small mt-1">{{ form.errors.requested_models }}</div>
                        <div v-if="form.errors.requested_capabilities" class="text-danger small mt-1">{{ form.errors.requested_capabilities }}</div>
                    </fieldset>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3">
                <button type="submit" :disabled="form.processing" class="btn btn-primary">
                    {{ isEdit ? 'Save changes' : 'Create application' }}
                </button>
                <Link href="/portal/applications" class="text-secondary">Cancel</Link>
            </div>
        </form>
    </PortalLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';

defineProps({
    applications: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({ total: 0, draft: 0, submitted: 0, active: 0 }) },
});

const statusLabel = (status) => status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
</script>

<template>
    <PortalLayout>
        <template #default="{ user }">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <p class="bcgov-eyebrow text-bc-blue mb-1">Portal</p>
                    <h2 class="h3 fw-bold mb-0">Welcome{{ user ? `, ${user.name}` : '' }}.</h2>
                </div>
                <Link href="/portal/applications/create" class="btn btn-primary">New application</Link>
            </div>

            <div class="row g-3 mt-3">
                <div class="col-6 col-sm-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body">
                            <p class="display-6 fw-bold mb-0">{{ stats.total }}</p>
                            <p class="text-secondary small mb-0">Total</p>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body">
                            <p class="display-6 fw-bold mb-0">{{ stats.draft }}</p>
                            <p class="text-secondary small mb-0">Draft</p>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body">
                            <p class="display-6 fw-bold mb-0">{{ stats.submitted }}</p>
                            <p class="text-secondary small mb-0">Submitted</p>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body">
                            <p class="display-6 fw-bold mb-0">{{ stats.active }}</p>
                            <p class="text-secondary small mb-0">Active</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mt-4">
                <div class="card-body">
                    <h3 class="h5 fw-bold">Your applications</h3>
                    <p v-if="applications.length === 0" class="text-secondary mb-0">
                        You have no applications yet.
                        <Link href="/portal/applications/create" class="text-bc-blue">Create one</Link>.
                    </p>
                    <ul v-else class="list-group list-group-flush">
                        <li
                            v-for="application in applications"
                            :key="application.public_id"
                            class="list-group-item d-flex align-items-center justify-content-between px-0"
                        >
                            <div>
                                <Link :href="`/portal/applications/${application.public_id}`" class="fw-semibold text-bc-blue">
                                    {{ application.name }}
                                </Link>
                                <p class="text-secondary small mb-0">{{ application.ministry_organization }}</p>
                            </div>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill">
                                {{ statusLabel(application.status) }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </template>
    </PortalLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';

defineProps({
    applications: { type: Array, default: () => [] },
});

const statusLabel = (status) => status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

const numberFmt = (value) => Number(value || 0).toLocaleString();

// Colour the usage bar by how close the application is to its monthly budget.
const usageVariant = (percent) => {
    if (percent === null || percent === undefined) return 'secondary';
    if (percent >= 90) return 'danger';
    if (percent >= 70) return 'warning';
    return 'success';
};

const barWidth = (percent) => `${Math.min(100, Math.max(0, Number(percent || 0)))}%`;
</script>

<template>
    <PortalLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h2 class="h3 fw-bold mb-0">Applications</h2>
            <Link href="/portal/applications/create" class="btn btn-primary">New application</Link>
        </div>

        <div class="card shadow-sm mt-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Ministry</th>
                            <th scope="col">Status</th>
                            <th scope="col" style="min-width: 220px;">Usage (this month)</th>
                            <th scope="col">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="applications.length === 0">
                            <td colspan="5" class="text-secondary py-4">No applications yet.</td>
                        </tr>
                        <tr v-for="application in applications" :key="application.public_id">
                            <td>
                                <Link :href="`/portal/applications/${application.public_id}`" class="fw-semibold text-bc-blue">
                                    {{ application.name }}
                                </Link>
                            </td>
                            <td class="text-secondary">{{ application.ministry_organization }}</td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill">
                                    {{ statusLabel(application.status) }}
                                </span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center justify-content-between small mb-1">
                                    <span class="text-secondary">
                                        {{ numberFmt(application.usage?.tokens_used) }}
                                        <template v-if="application.usage?.token_budget">
                                            / {{ numberFmt(application.usage.token_budget) }}
                                        </template>
                                        tokens
                                    </span>
                                    <span
                                        v-if="application.usage?.token_percent !== null && application.usage?.token_percent !== undefined"
                                        class="badge rounded-pill"
                                        :class="`text-bg-${usageVariant(application.usage.token_percent)}`"
                                    >
                                        {{ application.usage.token_percent }}%
                                    </span>
                                    <span v-else class="badge rounded-pill text-bg-secondary">no budget</span>
                                </div>
                                <div class="progress" style="height: 6px;" role="progressbar"
                                     :aria-valuenow="application.usage?.token_percent || 0" aria-valuemin="0" aria-valuemax="100">
                                    <div
                                        class="progress-bar"
                                        :class="`bg-${usageVariant(application.usage?.token_percent)}`"
                                        :style="{ width: barWidth(application.usage?.token_percent) }"
                                    ></div>
                                </div>
                            </td>
                            <td class="text-secondary">{{ new Date(application.updated_at).toLocaleDateString() }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </PortalLayout>
</template>


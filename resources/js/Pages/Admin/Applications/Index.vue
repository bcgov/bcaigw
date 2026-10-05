<script setup>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({
    applications: { type: Array, default: () => [] },
});

const statusLabel = (status) => status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
const fmt = (n) => Number(n || 0).toLocaleString();
const effectiveRate = (app) => app.rate_limit_per_minute ?? app.expected_requests_per_minute ?? null;
const tokenBudget = (app) => app.token_budget_monthly ?? app.expected_tokens_per_month ?? null;
const pct = (used, limit) => (limit && limit > 0 ? Math.min(100, Math.round(((used || 0) / limit) * 100)) : null);
const pctClass = (p) => (p === null ? 'bg-secondary' : p >= 90 ? 'bg-danger' : p >= 75 ? 'bg-warning' : 'bg-success');
const money = (v, currency) => (v === null || v === undefined ? '—' : `${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency || 'CAD'}`);
</script>

<template>
    <AdminLayout>
        <h2 class="h3 fw-bold">Applications</h2>
        <p class="text-secondary">Review submitted applications, monitor month-to-date usage, and manage their lifecycle.</p>

        <div class="card shadow-sm mt-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Ministry</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Rate/min</th>
                            <th scope="col" class="text-end">Requests (mo)</th>
                            <th scope="col">Tokens (mo)</th>
                            <th scope="col" class="text-end">Cost (mo)</th>
                            <th scope="col">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="applications.length === 0">
                            <td colspan="8" class="text-secondary py-4">No applications yet.</td>
                        </tr>
                        <tr v-for="application in applications" :key="application.public_id">
                            <td>
                                <Link :href="`/admin/applications/${application.public_id}`" class="fw-semibold text-bc-blue">
                                    {{ application.name }}
                                </Link>
                                <div class="small text-secondary">{{ application.creator?.name }}</div>
                            </td>
                            <td class="text-secondary">{{ application.ministry_organization }}</td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill">
                                    {{ statusLabel(application.status) }}
                                </span>
                            </td>
                            <td class="text-end">{{ effectiveRate(application) !== null ? fmt(effectiveRate(application)) : '—' }}</td>
                            <td class="text-end">
                                {{ fmt(application.usage?.month.requests) }}
                                <span v-if="application.usage?.month.failures" class="badge bg-danger-subtle text-danger-emphasis ms-1">
                                    {{ fmt(application.usage.month.failures) }} err
                                </span>
                            </td>
                            <td style="min-width: 160px;">
                                <div class="d-flex justify-content-between small">
                                    <span>{{ fmt(application.usage?.month.total_tokens) }}</span>
                                    <span class="text-secondary">{{ tokenBudget(application) ? fmt(tokenBudget(application)) : 'no budget' }}</span>
                                </div>
                                <div
                                    v-if="pct(application.usage?.month.total_tokens, tokenBudget(application)) !== null"
                                    class="progress mt-1"
                                    style="height: 5px;"
                                >
                                    <div
                                        class="progress-bar"
                                        :class="pctClass(pct(application.usage?.month.total_tokens, tokenBudget(application)))"
                                        :style="{ width: pct(application.usage?.month.total_tokens, tokenBudget(application)) + '%' }"
                                    ></div>
                                </div>
                            </td>
                            <td class="text-end">
                                {{ money(application.usage?.month.cost, application.budget_currency) }}
                                <div v-if="application.cost_budget_monthly" class="small text-secondary">
                                    / {{ money(application.cost_budget_monthly, application.budget_currency) }}
                                </div>
                            </td>
                            <td class="text-secondary">{{ new Date(application.updated_at).toLocaleDateString() }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>

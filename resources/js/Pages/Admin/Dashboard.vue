<script setup>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    appName: { type: String, default: 'BC AI Gateway' },
    stats: { type: Object, default: () => ({}) },
    usage: { type: Object, default: () => ({}) },
});

const cards = [
    ['Users', 'users', '/admin/users'],
    ['Applications', 'applications', '/admin/applications'],
    ['Awaiting review', 'submitted', '/admin/applications'],
    ['Active', 'active', '/admin/applications'],
    ['Providers', 'providers', '/admin/model-control'],
    ['Model aliases', 'aliases', '/admin/model-control'],
    ['Active grants', 'grants', '/admin/model-control'],
];

const fmt = (n) => Number(n || 0).toLocaleString();
const money = (v) => `${Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${props.usage?.currency || 'CAD'}`;
const errorRate = (bucket) => {
    const r = Number(bucket?.requests || 0);
    return r > 0 ? ((Number(bucket?.failures || 0) / r) * 100).toFixed(1) + '%' : '0%';
};
</script>

<template>
    <AdminLayout v-slot="{ user }">
        <h2 class="h3 fw-bold">Admin dashboard</h2>
        <p class="text-secondary">Signed in as {{ user?.name }}.</p>

        <h3 class="h5 fw-semibold mt-4">This month at a glance</h3>
        <section class="row g-3 mt-0">
            <div class="col-sm-6 col-lg-3">
                <div class="card shadow-sm h-100 border-start border-4 border-primary">
                    <div class="card-body">
                        <p class="text-secondary small mb-1">Tokens consumed (month)</p>
                        <p class="h2 fw-bold text-bc-blue mb-1">{{ fmt(usage.month?.total_tokens) }}</p>
                        <p class="small text-secondary mb-0">Today: {{ fmt(usage.today?.total_tokens) }}</p>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-secondary small mb-1">Requests (month)</p>
                        <p class="h2 fw-bold mb-1">{{ fmt(usage.month?.requests) }}</p>
                        <p class="small text-secondary mb-0">Today: {{ fmt(usage.today?.requests) }}</p>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-secondary small mb-1">Estimated cost (month)</p>
                        <p class="h2 fw-bold mb-1">{{ money(usage.month?.cost) }}</p>
                        <p class="small text-secondary mb-0">Today: {{ money(usage.today?.cost) }}</p>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-secondary small mb-1">Failures (month)</p>
                        <p class="h2 fw-bold mb-1" :class="usage.month?.failures ? 'text-danger' : ''">{{ fmt(usage.month?.failures) }}</p>
                        <p class="small text-secondary mb-0">Error rate: {{ errorRate(usage.month) }} · {{ fmt(usage.active_applications) }} active apps</p>
                    </div>
                </div>
            </div>
        </section>

        <div class="row g-3 mt-1">
            <div class="col-lg-6">
                <section class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <h3 class="h6 fw-semibold mb-0">Top consuming applications (month)</h3>
                            <Link href="/admin/applications" class="small text-bc-blue">View all</Link>
                        </div>
                        <div v-if="usage.top_applications?.length" class="table-responsive mt-2">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Application</th>
                                        <th scope="col" class="text-end">Tokens</th>
                                        <th scope="col" class="text-end">Requests</th>
                                        <th scope="col" class="text-end">Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="app in usage.top_applications" :key="app.public_id">
                                        <td>
                                            <Link :href="`/admin/applications/${app.public_id}`" class="fw-semibold text-bc-blue text-decoration-none">
                                                {{ app.name }}
                                            </Link>
                                        </td>
                                        <td class="text-end">{{ fmt(app.total_tokens) }}</td>
                                        <td class="text-end">{{ fmt(app.requests) }}</td>
                                        <td class="text-end">{{ money(app.cost) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="text-secondary mb-0 mt-2">No usage recorded this month.</p>
                    </div>
                </section>
            </div>
            <div class="col-lg-6">
                <section class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 fw-semibold mb-0">Top models (month)</h3>
                        <div v-if="usage.top_models?.length" class="table-responsive mt-2">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Model</th>
                                        <th scope="col" class="text-end">Tokens</th>
                                        <th scope="col" class="text-end">Requests</th>
                                        <th scope="col" class="text-end">Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(m, i) in usage.top_models" :key="i">
                                        <td>
                                            <div class="fw-semibold">{{ m.display_name || '—' }}</div>
                                            <code class="small text-break">{{ m.model_id }}</code>
                                        </td>
                                        <td class="text-end">{{ fmt(m.total_tokens) }}</td>
                                        <td class="text-end">{{ fmt(m.requests) }}</td>
                                        <td class="text-end">{{ money(m.cost) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="text-secondary mb-0 mt-2">No usage recorded this month.</p>
                    </div>
                </section>
            </div>
        </div>

        <h3 class="h5 fw-semibold mt-4">Platform</h3>
        <section class="row g-3 mt-0">
            <div v-for="[label, key, href] in cards" :key="label" class="col-sm-6 col-lg-3">
                <Link :href="href" class="card shadow-sm h-100 text-decoration-none">
                    <div class="card-body">
                        <p class="text-secondary small mb-1">{{ label }}</p>
                        <p class="display-6 fw-bold text-bc-blue mb-0">{{ stats[key] ?? 0 }}</p>
                    </div>
                </Link>
            </div>
        </section>
    </AdminLayout>
</template>


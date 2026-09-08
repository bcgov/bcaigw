<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({ applications: { type: Array, required: true } });
</script>

<template>
    <Head title="Applications" />
    <main class="mx-auto max-w-6xl px-6 py-10">
        <div class="flex items-center justify-between">
            <div>
                <Link href="/portal" class="text-bc-blue underline">Portal</Link>
                <h1 class="mt-2 text-3xl font-bold">My applications</h1>
            </div>
            <Link href="/portal/applications/create" class="rounded bg-bc-blue px-5 py-3 font-semibold text-white">
                Create application
            </Link>
        </div>
        <div class="mt-8 overflow-hidden rounded-lg bg-white shadow-sm">
            <table class="w-full text-left">
                <thead class="bg-slate-100"><tr><th class="p-4">Name</th><th class="p-4">Organization</th><th class="p-4">Status</th></tr></thead>
                <tbody>
                    <tr v-for="application in applications" :key="application.public_id" class="border-t">
                        <td class="p-4"><Link :href="`/portal/applications/${application.public_id}`" class="font-semibold text-bc-blue underline">{{ application.name }}</Link></td>
                        <td class="p-4">{{ application.ministry_organization }}</td>
                        <td class="p-4">{{ application.status }}</td>
                    </tr>
                    <tr v-if="applications.length === 0"><td colspan="3" class="p-8 text-center text-slate-600">No applications yet.</td></tr>
                </tbody>
            </table>
        </div>
    </main>
</template>

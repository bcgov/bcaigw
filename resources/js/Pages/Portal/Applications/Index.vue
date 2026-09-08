<script setup>
import { Head, Link } from '@inertiajs/vue3';
defineProps({ applications: { type: Array, required: true } });
</script>
<template>
    <Head title="Application review" />
    <main class="mx-auto max-w-6xl px-6 py-10"><Link href="/portal/admin" class="text-bc-blue underline">Administration</Link><h1 class="mt-2 text-3xl font-bold">Application review</h1>
        <div class="mt-8 overflow-hidden rounded-lg bg-white shadow-sm"><table class="w-full text-left"><thead class="bg-slate-100"><tr><th class="p-4">Application</th><th class="p-4">Owner</th><th class="p-4">Status</th></tr></thead><tbody>
            <tr v-for="application in applications" :key="application.public_id" class="border-t"><td class="p-4"><Link :href="`/portal/admin/applications/${application.public_id}`" class="font-semibold text-bc-blue underline">{{ application.name }}</Link></td><td class="p-4">{{ application.creator.name }}</td><td class="p-4">{{ application.status }}</td></tr>
        </tbody></table></div>
    </main>
</template>

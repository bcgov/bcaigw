<script setup>
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({
    users: {
        type: Array,
        required: true,
    },
});

const updateRole = (user, portalRole) => {
    router.patch(`/portal/admin/users/${user.id}/role`, {
        portal_role: portalRole,
    }, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Administration" />
    <main class="min-h-screen bg-slate-50">
        <header class="border-b-4 border-bc-gold bg-bc-blue px-6 py-5 text-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between">
                <h1 class="text-2xl font-bold">Portal administration</h1>
                <Link href="/portal" class="font-semibold underline">Owner portal</Link>
            </div>
        </header>
        <section class="mx-auto max-w-5xl px-6 py-12">
            <div class="mb-6 flex gap-6">
                <Link href="/portal/admin/applications" class="font-semibold text-bc-blue underline">Review applications</Link>
                <Link href="/portal/admin/model-control" class="font-semibold text-bc-blue underline">Model control plane</Link>
            </div>
            <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                <table class="w-full text-left">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-5 py-3">User</th>
                            <th class="px-5 py-3">IDIR</th>
                            <th class="px-5 py-3">Role</th>
                            <th class="px-5 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in users" :key="user.id" class="border-t">
                            <td class="px-5 py-4">{{ user.name }}</td>
                            <td class="px-5 py-4">{{ user.idir_username }}</td>
                            <td class="px-5 py-4">{{ user.portal_role }}</td>
                            <td class="flex gap-3 px-5 py-4">
                                <button
                                    class="font-semibold text-bc-blue underline"
                                    @click="updateRole(user, 'application_owner')"
                                >
                                    Make owner
                                </button>
                                <button
                                    class="font-semibold text-bc-blue underline"
                                    @click="updateRole(user, 'administrator')"
                                >
                                    Make administrator
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</template>

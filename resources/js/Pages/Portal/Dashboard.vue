<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Portal" />
    <main class="min-h-screen bg-slate-50">
        <header class="border-b-4 border-bc-gold bg-bc-blue px-6 py-5 text-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wider">BC AI Gateway</p>
                    <h1 class="text-2xl font-bold">Application owner portal</h1>
                </div>
                <Link href="/portal/logout" method="post" as="button" class="rounded border px-4 py-2 font-semibold">
                    Sign out
                </Link>
            </div>
        </header>
        <section class="mx-auto max-w-5xl px-6 py-12">
            <div class="rounded-lg bg-white p-8 shadow-sm">
                <h2 class="text-xl font-bold">Welcome, {{ user.name }}</h2>
                <p class="mt-2 text-slate-700">IDIR: {{ user.idir_username }}</p>
                <p class="mt-1 text-slate-700">Portal role: {{ user.portal_role }}</p>
                <Link href="/portal/applications" class="mt-6 mr-6 inline-flex font-semibold text-bc-blue underline">
                    My applications
                </Link>
                <Link
                    v-if="user.portal_role === 'administrator'"
                    href="/portal/admin"
                    class="mt-6 inline-flex font-semibold text-bc-blue underline"
                >
                    Administration
                </Link>
            </div>
        </section>
    </main>
</template>

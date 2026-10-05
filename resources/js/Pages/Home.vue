<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    appName: {
        type: String,
        default: 'BC AI Gateway',
    },
});

const page = usePage();
const user = computed(() => page.props.auth?.user);
</script>

<template>
    <div class="min-vh-100">
        <nav class="navbar bcgov-navbar">
            <div class="container">
                <span class="navbar-brand d-flex flex-column lh-1 py-2">
                    <span class="bcgov-eyebrow">Province of British Columbia</span>
                    <span class="fs-5 fw-bold mt-1">{{ appName }}</span>
                </span>
                <Link href="/logout" method="post" as="button" class="btn btn-outline-light btn-sm">
                    Sign out
                </Link>
            </div>
        </nav>

        <main class="container py-5">
            <div class="card shadow-sm">
                <div class="card-body p-4 p-md-5">
                    <h2 class="h3 fw-bold">Welcome{{ user ? `, ${user.name}` : '' }}.</h2>
                    <p class="mt-3 text-secondary">
                        You are signed in to the BC AI Gateway portal.
                    </p>
                </div>
            </div>
        </main>
    </div>
</template>

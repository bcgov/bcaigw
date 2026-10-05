<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    appName: {
        type: String,
        default: 'BC AI Gateway',
    },
});

const page = usePage();
const authError = computed(() => page.props.flash?.error);
</script>

<template>
    <div class="min-vh-100">
        <nav class="navbar bcgov-navbar">
            <div class="container">
                <span class="navbar-brand d-flex flex-column lh-1 py-2">
                    <span class="bcgov-eyebrow">Province of British Columbia</span>
                    <span class="fs-5 fw-bold mt-1">{{ appName }}</span>
                </span>
            </div>
        </nav>

        <main class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-7">
                    <div class="card shadow-sm">
                        <div class="card-body p-4 p-md-5">
                            <p class="bcgov-eyebrow text-bc-blue mb-1">Secure portal</p>
                            <h2 class="h3 fw-bold">Sign in with your IDIR account.</h2>
                            <p class="mt-3 text-secondary">
                                Access is restricted to identities asserted as IDIR by the Government of British
                                Columbia identity provider.
                            </p>

                            <div v-if="authError" class="alert alert-danger mt-4" role="alert">
                                {{ authError }}
                            </div>

                            <!--
                                A plain anchor, not an Inertia <Link>: this navigation leaves
                                the SPA for the identity provider, and a full page load is the
                                only thing that reliably carries the browser there.
                            -->
                            <a href="/applogin" class="btn btn-primary btn-lg mt-4">
                                Sign in with IDIR
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    appName: {
        type: String,
        default: 'BC AI Gateway',
    },
    flash: {
        type: Object,
        default: () => ({}),
    },
    idir: {
        type: Object,
        default: () => ({ configured: true }),
    },
});

// When Keycloak is not configured the sign-in route can only bounce straight
// back with a generic failure, so the button is presented as unavailable rather
// than inviting a click that cannot succeed.
const signInAvailable = computed(() => props.idir?.configured !== false);
</script>

<template>
    <main class="min-h-screen">
        <header class="border-b-4 border-bc-gold bg-bc-blue px-6 py-5 text-white">
            <div class="mx-auto max-w-5xl">
                <p class="text-sm font-semibold uppercase tracking-wider">Province of British Columbia</p>
                <h1 class="mt-1 text-3xl font-bold">{{ appName }}</h1>
            </div>
        </header>

        <section class="mx-auto max-w-5xl px-6 py-16">
            <div class="rounded-lg bg-white p-8 shadow-sm">
                <p class="text-sm font-semibold uppercase tracking-wide text-bc-blue">Secure portal</p>
                <h2 class="mt-2 text-2xl font-bold">Sign in with your IDIR account.</h2>
                <p class="mt-4 max-w-2xl leading-7 text-slate-700">
                    Portal access is restricted to identities explicitly asserted as IDIR by the
                    Government of British Columbia identity provider.
                </p>

                <p
                    v-if="flash.authError"
                    class="mt-6 rounded border border-red-300 bg-red-50 p-4 text-red-800"
                    role="alert"
                >
                    {{ flash.authError }}
                    <span v-if="flash.authErrorReason" class="mt-2 block font-mono text-sm">
                        Reason: {{ flash.authErrorReason }}
                    </span>
                </p>

                <div
                    v-if="!signInAvailable"
                    class="mt-6 rounded border border-amber-300 bg-amber-50 p-4 text-amber-900"
                    role="status"
                >
                    <p class="font-semibold">IDIR sign-in is not configured on this environment.</p>
                    <p class="mt-2 leading-6">
                        There is no local IDIR. Signing in requires a Common Hosted Single Sign-On
                        (CSS) integration request, then setting
                        <code class="font-mono">KEYCLOAK_AUTH_SERVER_URL</code>,
                        <code class="font-mono">KEYCLOAK_REALM</code>,
                        <code class="font-mono">KEYCLOAK_CLIENT_ID</code> and
                        <code class="font-mono">KEYCLOAK_CLIENT_SECRET</code>.
                        See <code class="font-mono">docs/idir-sign-in.md</code>.
                    </p>
                </div>

                <!--
                    A plain anchor, not an Inertia <Link>: this navigation leaves
                    the SPA for the identity provider, and a full page load is
                    the only thing that reliably carries the browser there.
                -->
                <a
                    v-if="signInAvailable"
                    href="/auth/idir"
                    class="mt-8 inline-flex rounded bg-bc-blue px-5 py-3 font-semibold text-white hover:bg-blue-900"
                >
                    Sign in with IDIR
                </a>
                <span
                    v-else
                    class="mt-8 inline-flex cursor-not-allowed rounded bg-slate-300 px-5 py-3 font-semibold text-slate-600"
                    aria-disabled="true"
                >
                    Sign in with IDIR
                </span>
            </div>
        </section>
    </main>
</template>

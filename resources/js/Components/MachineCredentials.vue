<script setup>
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    application: { type: Object, required: true },
    credentials: { type: Array, required: true },
    abilities: { type: Object, required: true },
    canManage: { type: Boolean, required: true },
    defaultRotationOverlapSeconds: { type: Number, required: true },
});

const page = usePage();
const oneTimeCredential = computed(() => {
    const issued = page.props.flash?.machineCredential;
    return issued?.application_public_id === props.application.public_id ? issued : null;
});
const issuanceAvailable = computed(() => (
    props.application.status === 'active' && props.application.configuration_ready
));
const createForm = useForm({
    name: '',
    abilities: ['gateway.invoke'],
    expires_at: '',
});
const overlapSeconds = ref(props.defaultRotationOverlapSeconds);

const createCredential = () => createForm.post(
    `/portal/applications/${props.application.public_id}/credentials`,
    { onSuccess: () => createForm.reset() },
);
const rotate = (credential) => router.post(
    `/portal/applications/${props.application.public_id}/credentials/${credential.public_id}/rotate`,
    { overlap_seconds: overlapSeconds.value, name: credential.name, expires_at: '' },
);
const revoke = (credential) => router.delete(
    `/portal/applications/${props.application.public_id}/credentials/${credential.public_id}`,
);
const isCurrent = (credential) => (
    !credential.revoked_at
    && (!credential.expires_at || new Date(credential.expires_at) > new Date())
    && (!credential.rotation_overlap_ends_at || new Date(credential.rotation_overlap_ends_at) > new Date())
);

onMounted(() => {
    if (oneTimeCredential.value) {
        router.clearHistory();
    }
});
</script>

<template>
    <section v-if="canManage" class="mt-8 rounded-lg bg-white p-6 shadow-sm">
        <h2 class="text-xl font-bold">Machine credentials</h2>
        <p class="mt-2 text-slate-700">
            Credentials use the OAuth client credentials flow. The secret is shown only once.
        </p>

        <div
            v-if="oneTimeCredential"
            class="mt-5 rounded border-2 border-bc-gold bg-amber-50 p-4"
            role="status"
        >
            <h3 class="font-bold">Copy this credential now</h3>
            <p class="mt-1 text-sm">The client secret cannot be recovered after leaving this page.</p>
            <dl class="mt-3 break-all font-mono text-sm">
                <dt class="font-bold">API Directory client ID</dt><dd>{{ oneTimeCredential.api_directory_client_id }}</dd>
                <dt class="mt-2 font-bold">BCAIGW client ID</dt><dd>{{ oneTimeCredential.client_id }}</dd>
                <dt class="mt-2 font-bold">BCAIGW client secret</dt><dd>{{ oneTimeCredential.client_secret }}</dd>
            </dl>
        </div>

        <ul class="mt-5 divide-y">
            <li v-for="credential in credentials" :key="credential.public_id" class="py-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ credential.name }}</p>
                        <p class="break-all font-mono text-sm">{{ credential.client_identifier }}</p>
                        <p class="mt-1 text-sm text-slate-600">
                            Scopes: {{ credential.abilities.join(' ') }}
                            · Expires: {{ credential.expires_at || 'never' }}
                            · Last used: {{ credential.last_used_at || 'never' }}
                        </p>
                        <p v-if="credential.revoked_at" class="font-semibold text-red-700">Revoked</p>
                        <p v-else-if="credential.rotation_overlap_ends_at" class="text-amber-800">
                            Rotation overlap ends {{ credential.rotation_overlap_ends_at }}
                        </p>
                    </div>
                    <div v-if="isCurrent(credential)" class="flex gap-3">
                        <button class="text-bc-blue underline" type="button" @click="rotate(credential)">Rotate</button>
                        <button class="text-red-700 underline" type="button" @click="revoke(credential)">Revoke</button>
                    </div>
                </div>
            </li>
        </ul>

        <template v-if="issuanceAvailable">
            <label class="mt-4 block">
                Rotation overlap seconds
                <input v-model.number="overlapSeconds" type="number" min="0" class="ml-2 rounded border p-2">
            </label>
            <form class="mt-6 space-y-4 border-t pt-5" @submit.prevent="createCredential">
                <h3 class="font-bold">Create credential</h3>
                <label class="block">Name <input v-model="createForm.name" required class="mt-1 w-full rounded border p-2"></label>
                <fieldset>
                    <legend class="font-semibold">Scopes</legend>
                    <label v-for="(label, ability) in abilities" :key="ability" class="mt-2 block">
                        <input v-model="createForm.abilities" type="checkbox" :value="ability"> {{ ability }} — {{ label }}
                    </label>
                </fieldset>
                <label class="block">Optional expiry <input v-model="createForm.expires_at" type="datetime-local" class="mt-1 rounded border p-2"></label>
                <p v-if="Object.keys(createForm.errors).length" class="text-red-700" role="alert">
                    {{ Object.values(createForm.errors)[0] }}
                </p>
                <button class="rounded bg-bc-blue px-4 py-2 font-semibold text-white">Create credential</button>
            </form>
        </template>
        <p v-else class="mt-5 rounded bg-slate-100 p-4">
            Credential issuance is available only while the application is active and configuration-ready.
        </p>
    </section>
</template>

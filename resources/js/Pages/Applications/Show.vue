<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import MachineCredentials from '../../Components/MachineCredentials.vue';

const props = defineProps({
    application: { type: Object, required: true },
    can: { type: Object, required: true },
    machineCredentials: { type: Array, required: true },
    machineAbilities: { type: Object, required: true },
    defaultRotationOverlapSeconds: { type: Number, required: true },
    grantedModels: { type: Array, required: true },
    usageProjection: { type: Object, required: true },
});

const memberForm = useForm({ idir_username: '', role: 'member' });
const submit = () => router.post(`/portal/applications/${props.application.public_id}/submit`, {
    transition: 'submit',
    status_version: props.application.status_version,
});
const addMember = () => memberForm.post(`/portal/applications/${props.application.public_id}/members`);
const removeMember = (user) => router.delete(`/portal/applications/${props.application.public_id}/members/${user.id}`);
</script>

<template>
    <Head :title="application.name" />
    <main class="mx-auto max-w-5xl px-6 py-10">
        <Link href="/portal/applications" class="text-bc-blue underline">Applications</Link>
        <div class="mt-3 flex items-start justify-between">
            <div><h1 class="text-3xl font-bold">{{ application.name }}</h1><p class="mt-2">{{ application.ministry_organization }} · {{ application.status }}</p></div>
            <Link v-if="can.edit" :href="`/portal/applications/${application.public_id}/edit`" class="rounded border px-4 py-2 font-semibold">Edit</Link>
        </div>
        <section class="mt-8 rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-xl font-bold">Purpose</h2><p class="mt-3 whitespace-pre-wrap">{{ application.purpose_use_case }}</p>
            <p class="mt-3"><strong>API Directory client ID:</strong> {{ application.api_directory_client_id }}</p>
            <button v-if="can.submit" class="mt-6 rounded bg-bc-blue px-5 py-3 font-semibold text-white" @click="submit">Submit for approval</button>
        </section>
        <section class="mt-8 rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-xl font-bold">Usage and budgets ({{ usageProjection.timezone }})</h2>
            <p class="mt-2">Daily projected: {{ usageProjection.daily.projected_tokens }} / {{ usageProjection.daily.token_limit ?? 'unlimited' }} tokens; {{ usageProjection.daily.projected_cost }} / {{ usageProjection.daily.cost_limit ?? 'unlimited' }} {{ usageProjection.currency }}</p>
            <p>Monthly projected: {{ usageProjection.monthly.projected_tokens }} / {{ usageProjection.monthly.token_limit ?? 'unlimited' }} tokens; {{ usageProjection.monthly.projected_cost }} / {{ usageProjection.monthly.cost_limit ?? 'unlimited' }} {{ usageProjection.currency }}</p>
        </section>
        <section class="mt-8 rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-xl font-bold">Members</h2>
            <ul class="mt-3 divide-y"><li v-for="user in application.users" :key="user.id" class="flex justify-between py-3"><span>{{ user.name }} ({{ user.idir_username }}) · {{ user.pivot.role }}</span><button v-if="can.manageMembers" class="text-red-700 underline" @click="removeMember(user)">Remove</button></li></ul>
            <form v-if="can.manageMembers" class="mt-5 flex flex-wrap gap-3" @submit.prevent="addMember">
                <label>IDIR username <input v-model="memberForm.idir_username" class="ml-2 rounded border p-2"></label>
                <select v-model="memberForm.role" class="rounded border p-2"><option value="member">Member</option><option value="owner">Owner</option></select>
                <button class="rounded bg-bc-blue px-4 py-2 font-semibold text-white">Add or update</button>
                <p v-if="memberForm.errors.idir_username || memberForm.errors.member" class="w-full text-red-700" role="alert">{{ memberForm.errors.idir_username || memberForm.errors.member }}</p>
            </form>
        </section>
        <section class="mt-8 rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-xl font-bold">Granted models</h2>
            <p v-if="grantedModels.length === 0" class="mt-2 text-slate-600">No models are currently granted.</p>
            <ul v-else class="mt-3 divide-y">
                <li v-for="model in grantedModels" :key="model.public_id" class="py-3">
                    <p class="font-semibold">{{ model.display_name }} <span class="font-mono text-sm">({{ model.model_id }})</span></p>
                    <p class="text-sm text-slate-600">Capabilities: {{ model.capabilities.join(', ') }} · {{ model.status }}</p>
                </li>
            </ul>
        </section>
        <MachineCredentials
            :application="application"
            :credentials="machineCredentials"
            :abilities="machineAbilities"
            :can-manage="can.manageCredentials"
            :default-rotation-overlap-seconds="defaultRotationOverlapSeconds"
        />
        <section class="mt-8 rounded-lg bg-white p-6 shadow-sm"><h2 class="text-xl font-bold">Lifecycle history</h2><ol class="mt-3 space-y-3"><li v-for="event in application.lifecycle_history" :key="event.id">{{ event.from_status || 'created' }} → {{ event.to_status }} by {{ event.actor?.name || 'system' }}<p v-if="event.note" class="text-slate-600">{{ event.note }}</p></li></ol></section>
    </main>
</template>

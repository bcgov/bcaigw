<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    users: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
});

const forms = {};
for (const user of props.users) {
    forms[user.id] = useForm({ role: user.role ?? '' });
}

const updateRole = (user) => {
    forms[user.id].put(`/admin/users/${user.id}/role`, { preserveScroll: true });
};
</script>

<template>
    <AdminLayout>
        <h2 class="h3 fw-bold">Users &amp; roles</h2>
        <p class="text-secondary">Assign a portal role to each user. The final administrator cannot be demoted.</p>

        <div class="card shadow-sm mt-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">IDIR</th>
                            <th scope="col">Email</th>
                            <th scope="col">Role</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="users.length === 0">
                            <td colspan="5" class="text-secondary py-4">No users found.</td>
                        </tr>
                        <tr v-for="user in users" :key="user.id">
                            <td class="fw-semibold">{{ user.name }}</td>
                            <td class="text-secondary">{{ user.idir_username }}</td>
                            <td class="text-secondary">{{ user.email }}</td>
                            <td style="min-width: 12rem;">
                                <select v-model="forms[user.id].role" class="form-select form-select-sm" :class="{ 'is-invalid': forms[user.id].errors.role }">
                                    <option value="" disabled>Select role</option>
                                    <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                                </select>
                                <div v-if="forms[user.id].errors.role" class="invalid-feedback">
                                    {{ forms[user.id].errors.role }}
                                </div>
                            </td>
                            <td>
                                <button
                                    type="button"
                                    :disabled="forms[user.id].processing || !forms[user.id].role"
                                    class="btn btn-primary btn-sm"
                                    @click="updateRole(user)"
                                >
                                    Save
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>

<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const appName = computed(() => page.props.appName ?? 'BC AI Gateway');
const user = computed(() => page.props.auth?.user);
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);

const isActive = (path) => page.url === path || page.url.startsWith(path + '/');
</script>

<template>
    <div class="min-vh-100">
        <nav class="navbar navbar-expand-lg bcgov-navbar">
            <div class="container">
                <Link class="navbar-brand d-flex flex-column lh-1 py-2" href="/admin">
                    <span class="bcgov-eyebrow">Province of British Columbia</span>
                    <span class="fs-5 fw-bold mt-1">{{ appName }} — Admin</span>
                </Link>
                <button
                    class="navbar-toggler"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#adminNav"
                    aria-controls="adminNav"
                    aria-expanded="false"
                    aria-label="Toggle navigation"
                >
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div id="adminNav" class="collapse navbar-collapse">
                    <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                        <li class="nav-item">
                            <Link class="nav-link" :class="{ active: page.url === '/admin' }" href="/admin">Dashboard</Link>
                        </li>
                        <li class="nav-item">
                            <Link class="nav-link" :class="{ active: isActive('/admin/users') }" href="/admin/users">Users</Link>
                        </li>
                        <li class="nav-item">
                            <Link class="nav-link" :class="{ active: isActive('/admin/applications') }" href="/admin/applications">Applications</Link>
                        </li>
                        <li class="nav-item">
                            <Link class="nav-link" :class="{ active: isActive('/admin/model-control') }" href="/admin/model-control">Model control</Link>
                        </li>
                        <li class="nav-item">
                            <Link class="nav-link" href="/portal">Portal</Link>
                        </li>
                        <li class="nav-item ms-lg-2">
                            <Link href="/logout" method="post" as="button" class="btn btn-outline-light btn-sm">Sign out</Link>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <main class="container py-4">
            <div v-if="flashSuccess" class="alert alert-success" role="alert">{{ flashSuccess }}</div>
            <div v-if="flashError" class="alert alert-danger" role="alert">{{ flashError }}</div>

            <slot :user="user" />
        </main>
    </div>
</template>

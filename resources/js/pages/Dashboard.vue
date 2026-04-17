<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const page = usePage<SharedData>();
const restaurantName = computed(() => page.props.restaurant?.name ?? '');
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <header data-testid="restaurant-header">
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ restaurantName }}
                </h1>
                <p class="text-sm text-muted-foreground">Willkommen im Reservierungs-Dashboard.</p>
            </header>

            <section
                data-testid="reservations-empty-state"
                class="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center dark:border-sidebar-border"
            >
                <p class="text-lg font-medium">Noch keine Reservierungen</p>
                <p class="mt-1 text-sm text-muted-foreground">Sobald eine Anfrage eingeht, erscheint sie hier.</p>
            </section>
        </div>
    </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

interface ReservationProp {
    id: number;
    guest_name: string;
    guest_email: string;
    party_size: number;
    desired_at: string | null;
    status: string;
    source: string;
    message: string | null;
}

defineProps<{
    reservation: ReservationProp;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Reservierung', href: '#' },
];
</script>

<template>
    <Head :title="`Reservierung #${reservation.id}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6" data-testid="reservation-detail">
            <header>
                <h1 class="text-2xl font-semibold tracking-tight">Reservierung #{{ reservation.id }}</h1>
                <p class="text-sm text-muted-foreground">{{ reservation.guest_name }} · {{ reservation.party_size }} Personen</p>
            </header>

            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-muted-foreground">Status</dt>
                    <dd class="font-medium">{{ reservation.status }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">Quelle</dt>
                    <dd class="font-medium">{{ reservation.source }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">E-Mail</dt>
                    <dd class="font-medium">{{ reservation.guest_email }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">Wunschzeit</dt>
                    <dd class="font-medium">{{ reservation.desired_at ?? '—' }}</dd>
                </div>
            </dl>

            <section v-if="reservation.message">
                <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">Nachricht</h2>
                <p class="whitespace-pre-line text-sm">{{ reservation.message }}</p>
            </section>
        </div>
    </AppLayout>
</template>

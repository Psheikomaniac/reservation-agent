<script setup lang="ts">
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{ reason: string }>();

const MESSAGE: Record<string, string> = {
    invalid: 'Dieser Einladungs-Link ist ungültig.',
    expired: 'Dieser Einladungs-Link ist abgelaufen. Bitte fordern Sie einen neuen an.',
    accepted: 'Diese Einladung wurde bereits angenommen. Bitte melden Sie sich an.',
};
</script>

<template>
    <OnboardingLayout title="Einladung">
        <Head title="Einladung" />

        <p class="text-muted-foreground">{{ MESSAGE[reason] ?? MESSAGE.invalid }}</p>

        <Link :href="route('login')" class="mt-6 inline-block text-sm font-medium underline">Zur Anmeldung</Link>
    </OnboardingLayout>
</template>

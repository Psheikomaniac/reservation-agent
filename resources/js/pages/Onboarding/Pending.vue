<script setup lang="ts">
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <OnboardingLayout title="Fast bereit">
        <Head title="Wird eingerichtet" />
        <p class="text-muted-foreground">
            Dieses Restaurant wird gerade von der Inhaberin oder dem Inhaber eingerichtet. Sobald es bereit ist, können Sie hier loslegen.
        </p>
    </OnboardingLayout>
</template>

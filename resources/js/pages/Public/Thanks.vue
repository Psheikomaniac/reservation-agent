<script setup lang="ts">
import PublicLayout from '@/layouts/PublicLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    restaurant: {
        name: string;
    };
}>();
</script>

<template>
    <PublicLayout :heading="`Vielen Dank für Ihre Anfrage bei ${restaurant.name}`" description="Wir melden uns so schnell wie möglich bei Ihnen.">
        <Head :title="`Reservierung bestätigt – ${restaurant.name}`" />

        <p class="text-sm text-muted-foreground">
            Ihre Reservierungsanfrage ist bei uns eingegangen. Sobald das Team Ihre Wunschzeit geprüft hat, erhalten Sie eine Rückmeldung per E-Mail.
        </p>
    </PublicLayout>
</template>

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
    <PublicLayout heading="Deine Daten wurden gelöscht" description="Die Löschung ist abgeschlossen.">
        <Head title="Daten gelöscht" />

        <p class="text-sm text-muted-foreground">Wir haben alle gespeicherten Daten zu deiner Reservierung bei {{ restaurant.name }} entfernt.</p>
    </PublicLayout>
</template>

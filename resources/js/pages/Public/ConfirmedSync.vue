<script setup lang="ts">
import PublicLayout from '@/layouts/PublicLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    restaurant: {
        name: string;
    };
    reservation: {
        date: string;
        time: string;
        party_size: number;
        guest_email_masked: string;
    };
}>();

// The server sends the date already localised to the restaurant timezone as
// `YYYY-MM-DD`; reformat to German `DD.MM.YYYY` by string split rather than
// re-parsing through Date(), which would re-introduce a timezone shift.
const germanDate = computed(() => {
    const [year, month, day] = props.reservation.date.split('-');

    return year && month && day ? `${day}.${month}.${year}` : props.reservation.date;
});
</script>

<template>
    <PublicLayout :heading="`Reservierung bei ${restaurant.name} bestätigt`" description="Ihr Tisch ist reserviert.">
        <Head :title="`Reservierung bestätigt – ${restaurant.name}`" />

        <dl class="space-y-3 text-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">Datum</dt>
                <dd class="font-medium">{{ germanDate }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">Uhrzeit</dt>
                <dd class="font-medium">{{ reservation.time }} Uhr</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">Personen</dt>
                <dd class="font-medium">{{ reservation.party_size }}</dd>
            </div>
        </dl>

        <p class="mt-6 text-sm text-muted-foreground">Eine Bestätigung haben wir an {{ reservation.guest_email_masked }} geschickt.</p>

        <p class="mt-4 border-t border-border pt-4 text-xs text-muted-foreground">
            Diese Bestätigung wurde automatisch ausgesprochen, weil Ihr Wunschtermin frei war.
        </p>
    </PublicLayout>
</template>

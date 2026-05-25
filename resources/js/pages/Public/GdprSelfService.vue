<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { formatDateTime } from '@/lib/format-datetime';
import { type ReservationStatus } from '@/lib/reservationStatus';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    reservation: {
        guest_name: string;
        guest_email: string;
        guest_phone: string | null;
        party_size: number;
        status: string;
        note: string | null;
        desired_at: string | null;
        created_at: string | null;
    };
    restaurant: {
        name: string;
        timezone: string;
    };
    deleteToken: string;
}>();

const localDateTime = (iso: string | null): string => (iso ? formatDateTime(iso, { timeZone: props.restaurant.timezone }) : '–');

const desiredAt = computed(() => localDateTime(props.reservation.desired_at));
const createdAt = computed(() => localDateTime(props.reservation.created_at));

const form = useForm({ confirm_date: '' });

const submitDelete = () => {
    // Posts to the short-lived signed delete URL issued by the show endpoint.
    form.post(props.deleteToken, { preserveScroll: true });
};
</script>

<template>
    <PublicLayout
        :heading="`Deine Daten beim Restaurant ${restaurant.name}`"
        description="Hier siehst du alle Daten, die wir zu deiner Reservierung gespeichert haben."
    >
        <Head title="Datenschutz – Deine Daten" />

        <dl class="space-y-3 text-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">Reservierung am</dt>
                <dd class="font-medium">{{ desiredAt }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">Personen</dt>
                <dd class="font-medium">{{ reservation.party_size }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">Status</dt>
                <dd><StatusBadge :status="reservation.status as ReservationStatus" /></dd>
            </div>
        </dl>

        <h2 class="mb-3 mt-6 text-sm font-semibold">Persönliche Daten</h2>
        <dl class="space-y-3 text-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">Name</dt>
                <dd class="font-medium">{{ reservation.guest_name }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">E-Mail</dt>
                <dd class="font-medium">{{ reservation.guest_email }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">Telefon</dt>
                <dd class="font-medium">{{ reservation.guest_phone ?? '–' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">Anmerkung</dt>
                <dd class="font-medium">{{ reservation.note ?? '–' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">Eingegangen am</dt>
                <dd class="font-medium">{{ createdAt }}</dd>
            </div>
        </dl>

        <form class="mt-8 rounded-lg border border-destructive/40 p-4" @submit.prevent="submitDelete">
            <h2 class="text-sm font-semibold text-destructive">Daten löschen</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Bitte bestätige durch Eingabe des Reservierungs-Datums (TT.MM.JJJJ). Anschließend werden alle gespeicherten Daten zu dieser
                Reservierung unwiderruflich gelöscht.
            </p>
            <div class="mt-3 grid gap-2">
                <Label for="confirm-date" class="sr-only">Reservierungs-Datum</Label>
                <Input id="confirm-date" v-model="form.confirm_date" placeholder="TT.MM.JJJJ" autocomplete="off" class="max-w-40" />
                <InputError :message="form.errors.confirm_date" />
            </div>
            <Button type="submit" variant="destructive" class="mt-3" :disabled="form.processing"> Löschen bestätigen </Button>
        </form>
    </PublicLayout>
</template>

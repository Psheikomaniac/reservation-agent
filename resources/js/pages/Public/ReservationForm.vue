<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

type Tonality = 'formal' | 'casual' | 'family';

const props = defineProps<{
    restaurant: {
        name: string;
        slug: string;
        tonality: Tonality;
    };
}>();

const greetings: Record<Tonality, string> = {
    formal: 'Sehr geehrte Gäste, wir freuen uns auf Ihre Reservierungsanfrage.',
    casual: 'Schön, dass du reservieren möchtest — wir melden uns gleich bei dir.',
    family: 'Schön, dass ihr bei uns essen wollt! Wir freuen uns auf eure Anfrage.',
};

const greeting = computed(() => greetings[props.restaurant.tonality]);

const form = useForm({
    guest_name: '',
    guest_email: '',
    guest_phone: '',
    party_size: 2,
    date: '',
    time: '',
    message: '',
    website: '',
});

const partySizes = Array.from({ length: 20 }, (_, i) => i + 1);

const submit = () => {
    form.transform((data) => ({
        guest_name: data.guest_name,
        guest_email: data.guest_email,
        guest_phone: data.guest_phone,
        party_size: data.party_size,
        desired_at: data.date && data.time ? `${data.date} ${data.time}` : '',
        message: data.message,
        website: data.website,
    })).post(route('public.reservations.store', { restaurant: props.restaurant.slug }), {
        preserveScroll: true,
    });
};
</script>

<template>
    <PublicLayout :heading="`Reservierung bei ${restaurant.name}`" :description="greeting">
        <Head :title="`Reservierung – ${restaurant.name}`" />

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-2">
                <Label for="guest_name">Name</Label>
                <Input id="guest_name" type="text" required autocomplete="name" autofocus v-model="form.guest_name" placeholder="Vor- und Nachname" />
                <InputError :message="form.errors.guest_name" />
            </div>

            <div class="grid gap-2">
                <Label for="guest_email">E-Mail</Label>
                <Input id="guest_email" type="email" required autocomplete="email" v-model="form.guest_email" placeholder="name@example.de" />
                <InputError :message="form.errors.guest_email" />
            </div>

            <div class="grid gap-2">
                <Label for="guest_phone">Telefon (optional)</Label>
                <Input id="guest_phone" type="tel" autocomplete="tel" v-model="form.guest_phone" placeholder="+49 …" />
                <InputError :message="form.errors.guest_phone" />
            </div>

            <div class="grid gap-2">
                <Label for="party_size">Personen</Label>
                <select
                    id="party_size"
                    v-model.number="form.party_size"
                    required
                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 md:text-sm"
                >
                    <option v-for="n in partySizes" :key="n" :value="n">{{ n }}</option>
                </select>
                <InputError :message="form.errors.party_size" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="date">Datum</Label>
                    <Input id="date" type="date" required v-model="form.date" />
                </div>
                <div class="grid gap-2">
                    <Label for="time">Uhrzeit</Label>
                    <Input id="time" type="time" required v-model="form.time" />
                </div>
            </div>
            <InputError :message="form.errors.desired_at" />

            <div class="grid gap-2">
                <Label for="message">Nachricht (optional)</Label>
                <textarea
                    id="message"
                    rows="3"
                    v-model="form.message"
                    maxlength="2000"
                    placeholder="Allergien, Wünsche, Anlass …"
                    class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 md:text-sm"
                />
                <InputError :message="form.errors.message" />
            </div>

            <!-- Honeypot: visually hidden, removed from a11y tree, skipped by tab. Real users never reach it. -->
            <div aria-hidden="true" class="absolute left-[-9999px] top-auto h-px w-px overflow-hidden">
                <Label for="website">Website</Label>
                <input id="website" type="text" tabindex="-1" autocomplete="off" v-model="form.website" />
            </div>

            <Button type="submit" class="w-full" :disabled="form.processing"> Reservierung anfragen </Button>
        </form>
    </PublicLayout>
</template>

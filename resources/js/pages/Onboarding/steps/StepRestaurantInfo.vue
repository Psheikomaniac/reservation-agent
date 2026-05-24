<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{ restaurant: { name: string; slug: string; timezone: string } }>();
const emit = defineEmits<{ saved: [] }>();

const form = useForm({
    name: props.restaurant.name,
    slug: props.restaurant.slug,
    timezone: props.restaurant.timezone,
});

const submit = () =>
    form.patch(route('onboarding.restaurant.update'), {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
</script>

<template>
    <form class="grid gap-4" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="name">Name des Restaurants</Label>
            <Input id="name" v-model="form.name" required autocomplete="organization" />
            <InputError :message="form.errors.name" />
        </div>
        <div class="grid gap-2">
            <Label for="slug">Öffentliche URL (Slug)</Label>
            <Input id="slug" v-model="form.slug" required />
            <InputError :message="form.errors.slug" />
        </div>
        <div class="grid gap-2">
            <Label for="timezone">Zeitzone</Label>
            <Input id="timezone" v-model="form.timezone" required />
            <InputError :message="form.errors.timezone" />
        </div>
        <Button type="submit" :disabled="form.processing">Stammdaten speichern</Button>
    </form>
</template>

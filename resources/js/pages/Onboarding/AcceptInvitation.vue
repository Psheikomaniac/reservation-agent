<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{ email: string; token: string; restaurantName: string }>();

const form = useForm({
    name: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('onboarding.accept.store', { token: props.token }), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <OnboardingLayout :title="`Willkommen bei ${restaurantName}`">
        <Head title="Einladung annehmen" />

        <form class="grid gap-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="email">E-Mail</Label>
                <Input id="email" :model-value="email" type="email" disabled />
            </div>

            <div class="grid gap-2">
                <Label for="name">Ihr Name</Label>
                <Input id="name" v-model="form.name" required autofocus autocomplete="name" />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Passwort</Label>
                <Input id="password" v-model="form.password" type="password" required autocomplete="new-password" />
                <InputError :message="form.errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Passwort bestätigen</Label>
                <Input id="password_confirmation" v-model="form.password_confirmation" type="password" required autocomplete="new-password" />
            </div>

            <Button type="submit" :disabled="form.processing">Konto aktivieren</Button>
        </form>
    </OnboardingLayout>
</template>

<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TransitionRoot } from '@headlessui/vue';
import { Link, useForm } from '@inertiajs/vue3';

const emit = defineEmits<{ saved: [] }>();

const form = useForm({ email: '' });

const submit = () =>
    form.post(route('onboarding.team.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('saved');
        },
    });
</script>

<template>
    <form class="grid gap-4" @submit.prevent="submit">
        <p class="text-sm text-muted-foreground">Laden Sie Mitarbeitende ein (optional). Sie erhalten einen eigenen Einladungs-Link.</p>
        <div class="grid gap-2">
            <Label for="email">E-Mail der Mitarbeiterin / des Mitarbeiters</Label>
            <Input id="email" v-model="form.email" type="email" required autocomplete="off" />
            <InputError :message="form.errors.email" />
        </div>
        <div class="flex items-center gap-4">
            <Button type="submit" :disabled="form.processing">Einladen</Button>
            <Link :href="route('onboarding.wizard')" class="text-sm text-muted-foreground underline">Überspringen</Link>
            <TransitionRoot
                :show="form.recentlySuccessful"
                enter="transition ease-in-out"
                enter-from="opacity-0"
                leave="transition ease-in-out"
                leave-to="opacity-0"
            >
                <p class="text-sm text-muted-foreground">Einladung verschickt.</p>
            </TransitionRoot>
        </div>
    </form>
</template>

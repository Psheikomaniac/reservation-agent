<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { TransitionRoot } from '@headlessui/vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{ tonality: string; tonalities: string[] }>();
const emit = defineEmits<{ saved: [] }>();

const LABEL: Record<string, string> = {
    formal: 'Förmlich',
    casual: 'Locker',
    family: 'Familiär',
};

const form = useForm({ tonality: props.tonality });

const submit = () =>
    form.patch(route('onboarding.tonality.update'), {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
</script>

<template>
    <form class="grid gap-4" @submit.prevent="submit">
        <Head title="Tonalität" />
        <p class="text-sm text-muted-foreground">Wie sollen die KI-Antworten klingen? (optional)</p>
        <label v-for="option in tonalities" :key="option" class="flex items-center gap-2">
            <input v-model="form.tonality" type="radio" :value="option" :data-testid="`tonality-${option}`" />
            <span>{{ LABEL[option] ?? option }}</span>
        </label>
        <InputError :message="form.errors.tonality" />
        <div class="flex items-center gap-4">
            <Button type="submit" :disabled="form.processing">Speichern</Button>
            <Link :href="route('onboarding.wizard')" class="text-sm text-muted-foreground underline">Überspringen</Link>
            <TransitionRoot
                :show="form.recentlySuccessful"
                enter="transition ease-in-out"
                enter-from="opacity-0"
                leave="transition ease-in-out"
                leave-to="opacity-0"
            >
                <p class="text-sm text-muted-foreground">Gespeichert.</p>
            </TransitionRoot>
        </div>
    </form>
</template>

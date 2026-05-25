<script setup lang="ts">
import { Button } from '@/components/ui/button';
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
        <div class="flex items-center gap-4">
            <Button type="submit" :disabled="form.processing">Speichern</Button>
            <Link :href="route('onboarding.wizard')" class="text-sm text-muted-foreground underline">Überspringen</Link>
        </div>
    </form>
</template>

<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/vue3';

type Block = { from: string; to: string };
type Schedule = Record<string, Block[]>;

const props = defineProps<{ openingHours: Schedule }>();
const emit = defineEmits<{ saved: [] }>();

const DAYS: { key: string; label: string }[] = [
    { key: 'mon', label: 'Montag' },
    { key: 'tue', label: 'Dienstag' },
    { key: 'wed', label: 'Mittwoch' },
    { key: 'thu', label: 'Donnerstag' },
    { key: 'fri', label: 'Freitag' },
    { key: 'sat', label: 'Samstag' },
    { key: 'sun', label: 'Sonntag' },
];

const form = useForm<{ opening_hours: Schedule }>({
    opening_hours: DAYS.reduce((acc, day) => {
        acc[day.key] = props.openingHours[day.key] ?? [];
        return acc;
    }, {} as Schedule),
});

const addBlock = (day: string) => form.opening_hours[day].push({ from: '18:00', to: '22:00' });
const removeBlock = (day: string, index: number) => form.opening_hours[day].splice(index, 1);

const submit = () =>
    form.patch(route('onboarding.hours.update'), {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });

defineExpose({ form, addBlock, removeBlock });
</script>

<template>
    <form class="grid gap-4" @submit.prevent="submit">
        <div v-for="day in DAYS" :key="day.key" class="grid gap-2 border-b border-border pb-3">
            <div class="flex items-center justify-between">
                <span class="font-medium">{{ day.label }}</span>
                <Button type="button" variant="ghost" size="sm" @click="addBlock(day.key)">+ Zeit</Button>
            </div>
            <div v-if="form.opening_hours[day.key].length === 0" class="text-sm text-muted-foreground">Ruhetag</div>
            <div v-for="(block, index) in form.opening_hours[day.key]" :key="index" class="flex items-center gap-2">
                <Input v-model="block.from" type="time" :data-testid="`${day.key}-from-${index}`" />
                <span>–</span>
                <Input v-model="block.to" type="time" :data-testid="`${day.key}-to-${index}`" />
                <Button type="button" variant="ghost" size="sm" @click="removeBlock(day.key, index)">Entfernen</Button>
            </div>
        </div>
        <InputError :message="form.errors.opening_hours" />
        <Button type="submit" :disabled="form.processing">Öffnungszeiten speichern</Button>
    </form>
</template>

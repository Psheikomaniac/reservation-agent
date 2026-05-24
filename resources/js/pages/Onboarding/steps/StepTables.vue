<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';

defineProps<{ tables: { id: number; label: string; seats: number; room_tag: string | null }[] }>();
const emit = defineEmits<{ saved: [] }>();

const form = useForm({
    label: '',
    seats: 2,
});

const submit = () =>
    form.post(route('onboarding.tables.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('saved');
        },
    });
</script>

<template>
    <div class="grid gap-6">
        <ul v-if="tables.length > 0" class="grid gap-2">
            <li v-for="table in tables" :key="table.id" class="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm">
                <span class="font-medium">{{ table.label }}</span>
                <span class="text-muted-foreground">{{ table.seats }} Plätze</span>
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">Noch keine Tische. Legen Sie mindestens einen an.</p>

        <form class="flex items-end gap-2" @submit.prevent="submit">
            <div class="grid flex-1 gap-2">
                <Label for="label">Bezeichnung</Label>
                <Input id="label" v-model="form.label" required placeholder="z. B. Tisch 1" />
                <InputError :message="form.errors.label" />
            </div>
            <div class="grid w-28 gap-2">
                <Label for="seats">Plätze</Label>
                <Input id="seats" v-model="form.seats" type="number" min="1" max="20" required />
                <InputError :message="form.errors.seats" />
            </div>
            <Button type="submit" :disabled="form.processing">Hinzufügen</Button>
        </form>
    </div>
</template>

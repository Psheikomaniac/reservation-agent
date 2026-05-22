<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { type TableFormPayload, type TableModel } from '@/types';
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{
    /** The table being edited, or `null` for a create. */
    table: TableModel | null;
    /** Tables of the same restaurant that this one may be combined with. */
    siblings: TableModel[];
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'saved'): void;
}>();

const isEdit = computed(() => props.table !== null);

const form = reactive<TableFormPayload>({
    label: props.table?.label ?? '',
    seats: props.table?.seats ?? 2,
    room_tag: props.table?.room_tag ?? '',
    sort_order: props.table?.sort_order ?? 0,
    active: props.table?.active ?? true,
    combinable_with: [...(props.table?.combinable_with ?? [])],
});

const errors = ref<Record<string, string>>({});
const processing = ref(false);

function payload(): TableFormPayload {
    return {
        label: form.label,
        seats: form.seats,
        room_tag: form.room_tag === '' ? null : form.room_tag,
        sort_order: form.sort_order,
        active: form.active,
        combinable_with: form.combinable_with,
    };
}

function submit(): void {
    processing.value = true;
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
        onError: (received: Record<string, string>) => {
            errors.value = received;
        },
        onFinish: () => {
            processing.value = false;
        },
    };

    if (props.table) {
        router.patch(route('tables.update', { table: props.table.id }), payload(), options);
    } else {
        router.post(route('tables.store'), payload(), options);
    }
}

function toggleCombinable(id: number): void {
    const index = form.combinable_with.indexOf(id);
    if (index === -1) {
        form.combinable_with.push(id);
    } else {
        form.combinable_with.splice(index, 1);
    }
}

function onOpenChange(open: boolean): void {
    if (!open) {
        emit('close');
    }
}
</script>

<template>
    <Sheet :open="true" @update:open="onOpenChange">
        <SheetContent class="flex w-full flex-col gap-4 overflow-y-auto sm:max-w-md" data-testid="table-form">
            <SheetHeader>
                <SheetTitle>{{ isEdit ? 'Tisch bearbeiten' : 'Neuer Tisch' }}</SheetTitle>
            </SheetHeader>

            <form class="flex flex-1 flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="table-label">Bezeichnung</Label>
                    <Input id="table-label" v-model="form.label" data-testid="field-label" required />
                    <InputError :message="errors.label" />
                </div>

                <div class="grid gap-2">
                    <Label for="table-seats">Plätze</Label>
                    <Input id="table-seats" v-model.number="form.seats" type="number" min="1" max="20" data-testid="field-seats" required />
                    <InputError :message="errors.seats" />
                </div>

                <div class="grid gap-2">
                    <Label for="table-room-tag">Bereich</Label>
                    <Input id="table-room-tag" v-model="form.room_tag" data-testid="field-room-tag" placeholder="z. B. Innen, Terrasse" />
                    <InputError :message="errors.room_tag" />
                </div>

                <div class="grid gap-2">
                    <Label for="table-sort-order">Sortierung</Label>
                    <Input id="table-sort-order" v-model.number="form.sort_order" type="number" min="0" data-testid="field-sort-order" />
                    <InputError :message="errors.sort_order" />
                </div>

                <label class="flex items-center gap-2">
                    <Checkbox v-model:checked="form.active" data-testid="field-active" />
                    <span class="text-sm font-medium">Aktiv</span>
                </label>

                <fieldset v-if="siblings.length > 0" class="grid gap-2">
                    <legend class="text-sm font-medium">Kombinierbar mit</legend>
                    <label v-for="sibling in siblings" :key="sibling.id" class="flex items-center gap-2 text-sm">
                        <Checkbox
                            :checked="form.combinable_with.includes(sibling.id)"
                            :data-testid="`combinable-${sibling.id}`"
                            @update:checked="toggleCombinable(sibling.id)"
                        />
                        {{ sibling.label }}
                    </label>
                    <InputError :message="errors.combinable_with" />
                </fieldset>

                <SheetFooter class="mt-auto flex justify-end gap-2">
                    <Button type="button" variant="outline" @click="emit('close')">Abbrechen</Button>
                    <Button type="submit" :disabled="processing" data-testid="submit">Speichern</Button>
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>
</template>

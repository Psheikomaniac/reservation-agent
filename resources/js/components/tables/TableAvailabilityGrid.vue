<script setup lang="ts">
import { Label } from '@/components/ui/label';
import { type DayAvailability, type SlotState, type TableModel } from '@/types';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    tables: TableModel[];
    availability: DayAvailability;
}>();

const date = ref(props.availability.date);
const labelById = computed(() => new Map(props.tables.map((table) => [table.id, table.label])));

// Partial reload: the Belegung tab is the availability endpoint itself, so
// re-requesting the current URL with a new date and only the availability prop
// refreshes the grid without a full page visit or any fetch/axios call.
function changeDate(): void {
    router.reload({ data: { date: date.value }, only: ['availability'] });
}

const stateMeta: Record<SlotState, { label: string; class: string }> = {
    free: { label: 'Frei', class: 'bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-200' },
    tight: { label: 'Knapp', class: 'bg-yellow-100 text-yellow-900 dark:bg-yellow-950 dark:text-yellow-200' },
    full: { label: 'Voll', class: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200' },
};

function suggestedLabel(id: number | null): string {
    return id === null ? '–' : (labelById.value.get(id) ?? `#${id}`);
}
</script>

<template>
    <div class="space-y-4" data-testid="availability-grid">
        <div class="flex flex-wrap items-center gap-4">
            <div class="flex items-center gap-2">
                <Label for="availability-date">Datum</Label>
                <input
                    id="availability-date"
                    v-model="date"
                    type="date"
                    data-testid="availability-date"
                    class="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                    @change="changeDate"
                />
            </div>
            <span class="text-sm text-muted-foreground" data-testid="occupancy">
                {{ availability.reserved_seats }} / {{ availability.total_capacity }} Plätze belegt
            </span>
        </div>

        <div v-if="availability.slots.length === 0" class="rounded-lg border border-dashed p-10 text-center" data-testid="availability-empty">
            <p class="text-sm text-muted-foreground">An diesem Tag ist das Restaurant geschlossen.</p>
        </div>

        <div v-else class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/40">
                    <tr class="border-b border-border">
                        <th class="px-4 py-2">Zeit</th>
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2">Tisch-Vorschlag</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="slot in availability.slots"
                        :key="slot.time"
                        data-testid="slot-row"
                        :data-state="slot.state"
                        class="border-b border-border last:border-0"
                    >
                        <td class="px-4 py-2 font-medium">{{ slot.time }}</td>
                        <td class="px-4 py-2">
                            <span class="inline-block rounded px-2 py-0.5 text-xs font-medium" :class="stateMeta[slot.state].class">
                                {{ stateMeta[slot.state].label }}
                            </span>
                        </td>
                        <td class="px-4 py-2">{{ suggestedLabel(slot.suggested_table_id) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

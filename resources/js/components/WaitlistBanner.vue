<script setup lang="ts">
import { formatDateTime } from '@/lib/format-datetime';
import { type ReservationRequestRow } from '@/types';
import { computed } from 'vue';

const props = defineProps<{
    /** Waitlisted reservations whose slot is currently free (PRD-013). */
    banner: ReservationRequestRow[];
    /** Restaurant timezone, so wished times read in local time. */
    timezone?: string;
}>();

const emit = defineEmits<{
    (e: 'open', id: number): void;
}>();

const MAX_VISIBLE = 5;

const visible = computed(() => props.banner.slice(0, MAX_VISIBLE));
const overflow = computed(() => Math.max(0, props.banner.length - MAX_VISIBLE));

const headline = computed(() =>
    props.banner.length === 1
        ? '1 Wartender könnte jetzt einen Slot bekommen.'
        : `${props.banner.length} Wartende könnten jetzt einen Slot bekommen.`,
);

function when(iso: string | null): string {
    return formatDateTime(iso, { timeZone: props.timezone });
}
</script>

<template>
    <div
        v-if="banner.length > 0"
        class="rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-900/40 dark:bg-amber-950/40"
        data-testid="waitlist-banner"
    >
        <p class="text-sm font-medium text-amber-900 dark:text-amber-200">{{ headline }}</p>
        <ul class="mt-2 space-y-1 text-sm">
            <li v-for="entry in visible" :key="entry.id">
                <button
                    type="button"
                    class="text-amber-900 underline underline-offset-2 hover:no-underline dark:text-amber-200"
                    data-testid="waitlist-entry"
                    @click="emit('open', entry.id)"
                >
                    {{ entry.guest_name }} – {{ when(entry.desired_at) }} ({{ entry.party_size }} P.)
                </button>
            </li>
        </ul>
        <p v-if="overflow > 0" class="mt-1 text-xs text-amber-800 dark:text-amber-300" data-testid="waitlist-overflow">
            … und {{ overflow }} weitere
        </p>
    </div>
</template>

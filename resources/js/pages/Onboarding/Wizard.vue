<script setup lang="ts">
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import type { OnboardingProgress, OnboardingStep } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import StepOpeningHours from './steps/StepOpeningHours.vue';
import StepRestaurantInfo from './steps/StepRestaurantInfo.vue';
import StepTables from './steps/StepTables.vue';
import StepTeam from './steps/StepTeam.vue';
import StepTonality from './steps/StepTonality.vue';

interface RestaurantInfo {
    name: string;
    slug: string;
    timezone: string;
    tonality: string;
    opening_hours: Record<string, { from: string; to: string }[]>;
}

const props = defineProps<{
    restaurant: RestaurantInfo;
    tables: { id: number; label: string; seats: number; room_tag: string | null }[];
    tonalities: string[];
    progress: OnboardingProgress;
}>();

const ORDER: { key: OnboardingStep; label: string }[] = [
    { key: 'restaurant', label: 'Stammdaten' },
    { key: 'hours', label: 'Öffnungszeiten' },
    { key: 'tables', label: 'Tische' },
    { key: 'tonality', label: 'Tonalität' },
    { key: 'team', label: 'Team' },
];

// Start at the first incomplete core step, otherwise the first optional step.
const active = ref<OnboardingStep>(props.progress.nextCoreStep ?? 'tonality');

const goTo = (step: OnboardingStep) => (active.value = step);

// Steps where saving is a single "done with this step" action: auto-advance to
// the next step in ORDER. Tische / Team stay because the user may add several
// (a new table, more invitees) and would not want each save to navigate away.
const STAYS_ON_SAVE: Set<OnboardingStep> = new Set(['tables', 'team']);

// Re-fetch derived progress + restaurant/tables after each step save, and
// advance to the next step where it makes sense.
const onSaved = () => {
    router.reload({ only: ['progress', 'restaurant', 'tables'] });

    if (!STAYS_ON_SAVE.has(active.value)) {
        const index = ORDER.findIndex((step) => step.key === active.value);
        const next = ORDER[index + 1]?.key;
        if (next) {
            active.value = next;
        }
    }
};
</script>

<template>
    <OnboardingLayout title="Restaurant einrichten">
        <Head title="Einrichtung" />

        <nav class="mb-6 flex flex-wrap gap-2">
            <button
                v-for="step in ORDER"
                :key="step.key"
                type="button"
                class="rounded-md px-3 py-1 text-sm"
                :class="active === step.key ? 'bg-primary text-primary-foreground' : 'bg-secondary text-secondary-foreground'"
                @click="goTo(step.key)"
            >
                {{ step.label }}<span v-if="progress.steps[step.key]"> ✓</span>
            </button>
        </nav>

        <div
            v-if="progress.coreComplete"
            class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-status-confirmed/40 bg-status-confirmed/10 px-3 py-2"
        >
            <p class="text-sm font-medium text-status-confirmed">Pflichtangaben vollständig — Ihr Restaurant ist live.</p>
            <Link :href="route('dashboard')" class="text-sm font-medium underline">Zum Dashboard →</Link>
        </div>

        <StepRestaurantInfo v-if="active === 'restaurant'" :restaurant="restaurant" @saved="onSaved" />
        <StepOpeningHours v-else-if="active === 'hours'" :opening-hours="restaurant.opening_hours" @saved="onSaved" />
        <StepTables v-else-if="active === 'tables'" :tables="tables" @saved="onSaved" />
        <StepTonality v-else-if="active === 'tonality'" :tonality="restaurant.tonality" :tonalities="tonalities" @saved="onSaved" />
        <StepTeam v-else @saved="onSaved" />
    </OnboardingLayout>
</template>

<script setup lang="ts">
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import type { OnboardingProgress, OnboardingStep } from '@/types';
import { Head, router } from '@inertiajs/vue3';
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

// Re-fetch derived progress + restaurant/tables after each step save.
const onSaved = () => router.reload({ only: ['progress', 'restaurant', 'tables'] });
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

        <p v-if="progress.coreComplete" class="mb-4 text-sm font-medium text-status-confirmed">
            Pflichtangaben vollständig — Ihr Restaurant ist live.
        </p>

        <StepRestaurantInfo v-if="active === 'restaurant'" :restaurant="restaurant" @saved="onSaved" />
        <StepOpeningHours v-else-if="active === 'hours'" :opening-hours="restaurant.opening_hours" @saved="onSaved" />
        <StepTables v-else-if="active === 'tables'" :tables="tables" @saved="onSaved" />
        <StepTonality v-else-if="active === 'tonality'" :tonality="restaurant.tonality" :tonalities="tonalities" @saved="onSaved" />
        <StepTeam v-else @saved="onSaved" />
    </OnboardingLayout>
</template>

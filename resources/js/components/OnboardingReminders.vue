<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

type OptionalStep = 'tonality' | 'team';

defineProps<{ reminders: OptionalStep[] }>();

const COPY: Record<OptionalStep, { title: string; body: string }> = {
    tonality: { title: 'Tonalität festlegen', body: 'Bestimmen Sie, wie die KI-Antworten klingen.' },
    team: { title: 'Team einladen', body: 'Laden Sie Mitarbeitende zu Ihrem Restaurant ein.' },
};
</script>

<template>
    <div v-if="reminders.length > 0" class="grid gap-3 sm:grid-cols-2">
        <Link
            v-for="step in reminders"
            :key="step"
            data-testid="onboarding-reminder"
            :href="route('onboarding.wizard')"
            class="rounded-lg border border-border bg-card p-4 text-sm transition hover:border-primary"
        >
            <p class="font-medium">{{ COPY[step].title }}</p>
            <p class="text-muted-foreground">{{ COPY[step].body }}</p>
        </Link>
    </div>
</template>

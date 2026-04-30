<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';

interface TrendBucket {
    label: string;
    bucketStart: string;
    count: number;
}

interface AnalyticsSnapshot {
    range: 'today' | '7d' | '30d';
    bucketSize: 'hour' | 'day';
    totals: Record<string, number>;
    sources: Record<string, number>;
    statusBreakdown: Record<string, number>;
    responseTime: {
        medianMinutes: number | null;
        p90Minutes: number | null;
        sampleSize: number;
    };
    editRate: number | null;
    sendModeStats: {
        manual: number;
        shadow: number;
        auto: number;
        shadowComparedSampleSize: number;
        takeoverRate: number | null;
        topHardGateReasons: Array<{ reason: string; count: number }>;
    } | null;
    trends: TrendBucket[];
    confirmationRateTrend: TrendBucket[];
    threadRepliesTrend: TrendBucket[];
}

defineProps<{
    snapshot: AnalyticsSnapshot;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Analytics', href: '/analytics' }];

// Range tabs trigger an Inertia partial reload so cache + tenant
// scoping stay on the server. The full chart UI lands in #232; this
// stub keeps the wiring testable.
const ranges: Array<{ value: 'today' | '7d' | '30d'; label: string }> = [
    { value: 'today', label: 'Heute' },
    { value: '7d', label: 'Letzte 7 Tage' },
    { value: '30d', label: 'Letzte 30 Tage' },
];

function selectRange(range: 'today' | '7d' | '30d') {
    router.reload({
        data: { range },
        only: ['snapshot'],
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Analytics" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="r in ranges"
                    :key="r.value"
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm transition"
                    :class="snapshot.range === r.value ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted'"
                    @click="selectRange(r.value)"
                >
                    {{ r.label }}
                </button>
            </div>

            <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">Anfragen gesamt</p>
                    <p class="mt-1 text-2xl font-semibold">{{ snapshot.totals.total ?? 0 }}</p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">Web-Formular</p>
                    <p class="mt-1 text-2xl font-semibold">{{ snapshot.sources.web_form ?? 0 }}</p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">E-Mail</p>
                    <p class="mt-1 text-2xl font-semibold">{{ snapshot.sources.email ?? 0 }}</p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">Bestätigt</p>
                    <p class="mt-1 text-2xl font-semibold">{{ snapshot.statusBreakdown.confirmed ?? 0 }}</p>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

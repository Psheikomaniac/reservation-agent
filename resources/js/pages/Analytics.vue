<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { computed, defineAsyncComponent } from 'vue';

// PRD-008 Bundle-Budget: chart.js + vue-chartjs are ~70 KB gzipped,
// above the 60 KB threshold the PRD sets for the initial bundle.
// `defineAsyncComponent` + dynamic import keep the chart code out of
// the SPA's initial chunk; only users navigating to /analytics
// download it. The Chart.js global registry is set up inside the
// async chunk so it never executes for non-analytics pages.
const Line = defineAsyncComponent(async () => {
    const [{ Line: LineComponent }, chartjs] = await Promise.all([import('vue-chartjs'), import('chart.js')]);

    chartjs.Chart.register(
        chartjs.CategoryScale,
        chartjs.LinearScale,
        chartjs.PointElement,
        chartjs.LineElement,
        chartjs.Title,
        chartjs.Tooltip,
        chartjs.Legend,
        chartjs.Filler,
    );

    return LineComponent;
});

interface TrendBucket {
    label: string;
    bucketStart: string;
    count: number;
}

interface ResponseTime {
    medianMinutes: number | null;
    p90Minutes: number | null;
    sampleSize: number;
}

interface SendModeStats {
    manual: number;
    shadow: number;
    auto: number;
    shadowComparedSampleSize: number;
    takeoverRate: number | null;
    topHardGateReasons: Array<{ reason: string; count: number }>;
}

export interface AnalyticsSnapshot {
    range: 'today' | '7d' | '30d';
    bucketSize: 'hour' | 'day';
    totals: { total: number };
    sources: { web_form: number; email: number };
    statusBreakdown: Record<'new' | 'in_review' | 'replied' | 'confirmed' | 'declined' | 'cancelled', number>;
    responseTime: ResponseTime;
    editRate: number | null;
    sendModeStats: SendModeStats | null;
    trends: TrendBucket[];
    confirmationRateTrend: TrendBucket[];
    threadRepliesTrend: TrendBucket[];
}

const props = defineProps<{
    snapshot: AnalyticsSnapshot;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Analytics', href: '/analytics' }];

const ranges: Array<{ value: 'today' | '7d' | '30d'; label: string }> = [
    { value: 'today', label: 'Heute' },
    { value: '7d', label: '7 Tage' },
    { value: '30d', label: '30 Tage' },
];

function selectRange(range: 'today' | '7d' | '30d') {
    if (range === props.snapshot.range) {
        return;
    }
    router.reload({
        data: { range },
        only: ['snapshot'],
        preserveScroll: true,
    });
}

const isEmpty = computed(() => props.snapshot.totals.total === 0);

const confirmationRate = computed(() => {
    const total = props.snapshot.totals.total;
    if (total === 0) {
        return null;
    }
    const confirmed = props.snapshot.statusBreakdown.confirmed ?? 0;
    return Math.round((confirmed / total) * 100);
});

const rejectionRate = computed(() => {
    const total = props.snapshot.totals.total;
    if (total === 0) {
        return null;
    }
    const declined = props.snapshot.statusBreakdown.declined ?? 0;
    return Math.round((declined / total) * 100);
});

const editRatePercent = computed(() => (props.snapshot.editRate === null ? null : Math.round(props.snapshot.editRate * 100)));

const takeoverRatePercent = computed(() => {
    const r = props.snapshot.sendModeStats?.takeoverRate;
    return r === null || r === undefined ? null : Math.round(r * 100);
});

function formatPercent(value: number | null): string {
    return value === null ? '—' : `${value} %`;
}

function formatMinutes(value: number | null): string {
    return value === null ? '—' : `${value} min`;
}

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: { intersect: false, mode: 'index' as const },
    },
    scales: {
        y: { beginAtZero: true, ticks: { precision: 0 } },
        x: { grid: { display: false } },
    },
}));

function buildLineData(buckets: TrendBucket[], color: string) {
    return {
        labels: buckets.map((b) => b.label),
        datasets: [
            {
                data: buckets.map((b) => b.count),
                borderColor: color,
                backgroundColor: `${color}33`,
                fill: true,
                tension: 0.25,
                pointRadius: 2,
            },
        ],
    };
}

const requestsChart = computed(() => buildLineData(props.snapshot.trends, '#2563eb'));
const confirmationChart = computed(() => buildLineData(props.snapshot.confirmationRateTrend, '#16a34a'));
const threadRepliesChart = computed(() => buildLineData(props.snapshot.threadRepliesTrend, '#9333ea'));
</script>

<template>
    <Head title="Analytics" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4 md:p-6">
            <header class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-semibold tracking-tight">Analytics</h1>
                <div role="tablist" aria-label="Zeitraum" class="flex flex-wrap gap-2">
                    <button
                        v-for="r in ranges"
                        :key="r.value"
                        type="button"
                        role="tab"
                        :aria-selected="snapshot.range === r.value"
                        :data-active="snapshot.range === r.value"
                        class="rounded-md border px-3 py-1.5 text-sm transition"
                        :class="snapshot.range === r.value ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted'"
                        @click="selectRange(r.value)"
                    >
                        {{ r.label }}
                    </button>
                </div>
            </header>

            <div v-if="isEmpty" class="rounded-lg border border-dashed p-10 text-center" data-testid="empty-state">
                <p class="text-lg font-medium">Noch keine Anfragen im gewählten Zeitraum.</p>
                <p class="mt-1 text-sm text-muted-foreground">Sobald Reservierungsanfragen eingehen, erscheinen hier die KPIs und Trend-Charts.</p>
            </div>

            <template v-else>
                <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader>
                            <CardDescription>Anfragen gesamt</CardDescription>
                            <CardTitle class="text-3xl">{{ snapshot.totals.total }}</CardTitle>
                        </CardHeader>
                        <CardContent class="text-sm text-muted-foreground">
                            Web-Formular: {{ snapshot.sources.web_form }} · E-Mail: {{ snapshot.sources.email }}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardDescription>Bestätigungsquote</CardDescription>
                            <CardTitle class="text-3xl">{{ formatPercent(confirmationRate) }}</CardTitle>
                        </CardHeader>
                        <CardContent class="text-sm text-muted-foreground"> Ablehnungsquote: {{ formatPercent(rejectionRate) }} </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardDescription>Zeit bis Antwort</CardDescription>
                            <CardTitle class="text-3xl">{{ formatMinutes(snapshot.responseTime.medianMinutes) }}</CardTitle>
                        </CardHeader>
                        <CardContent class="text-sm text-muted-foreground">
                            p90: {{ formatMinutes(snapshot.responseTime.p90Minutes) }} · n =
                            {{ snapshot.responseTime.sampleSize }}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardDescription>Edit-Quote</CardDescription>
                            <CardTitle class="text-3xl">{{ formatPercent(editRatePercent) }}</CardTitle>
                        </CardHeader>
                        <CardContent class="text-sm text-muted-foreground"> Anteil der KI-Drafts, die nachbearbeitet wurden. </CardContent>
                    </Card>
                </section>

                <section v-if="snapshot.sendModeStats" class="grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="send-mode-section">
                    <Card>
                        <CardHeader>
                            <CardTitle>Send-Mode-Stats</CardTitle>
                            <CardDescription>PRD-007 Auto-Send-Vertrauen</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span>Manual</span>
                                <span>{{ snapshot.sendModeStats.manual }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Shadow</span>
                                <span>{{ snapshot.sendModeStats.shadow }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Auto</span>
                                <span>{{ snapshot.sendModeStats.auto }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Shadow-Übernahme-Rate</span>
                                <span>{{ formatPercent(takeoverRatePercent) }}</span>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Top Hard-Gate-Reasons</CardTitle>
                            <CardDescription>Häufigste Gründe, warum auto auf manual fällt</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ul v-if="snapshot.sendModeStats.topHardGateReasons.length > 0" class="space-y-1 text-sm">
                                <li v-for="entry in snapshot.sendModeStats.topHardGateReasons" :key="entry.reason" class="flex justify-between">
                                    <span>{{ entry.reason }}</span>
                                    <span>{{ entry.count }}</span>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-muted-foreground">Keine Hard-Gate-Blockaden im aktuellen Zeitraum.</p>
                        </CardContent>
                    </Card>
                </section>

                <section class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Card class="col-span-1 lg:col-span-1">
                        <CardHeader>
                            <CardTitle>Anfragen pro {{ snapshot.bucketSize === 'hour' ? 'Stunde' : 'Tag' }}</CardTitle>
                        </CardHeader>
                        <CardContent class="h-64">
                            <Line :data="requestsChart" :options="chartOptions" />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Bestätigungsquote (%)</CardTitle>
                        </CardHeader>
                        <CardContent class="h-64">
                            <Line :data="confirmationChart" :options="chartOptions" />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Thread-Replies (Gast)</CardTitle>
                        </CardHeader>
                        <CardContent class="h-64">
                            <Line :data="threadRepliesChart" :options="chartOptions" />
                        </CardContent>
                    </Card>
                </section>
            </template>
        </div>
    </AppLayout>
</template>

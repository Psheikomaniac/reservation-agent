<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    type BreadcrumbItem,
    type DashboardFilters,
    type DashboardStats,
    type PaginatedReservationRequests,
    type ReservationRequestDetail,
    type ReservationSource,
    type ReservationStatus,
    type SharedData,
} from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useDocumentVisibility, useIntervalFn } from '@vueuse/core';
import { ChevronDown, Info } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const POLL_MS = 30_000;

interface DashboardProps {
    filters: DashboardFilters;
    requests: PaginatedReservationRequests;
    stats: DashboardStats;
    selectedRequest?: ReservationRequestDetail | null;
}

const props = defineProps<DashboardProps>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const page = usePage<SharedData>();
const restaurantName = computed(() => page.props.restaurant?.name ?? '');

const STATUS_OPTIONS: { value: ReservationStatus; label: string }[] = [
    { value: 'new', label: 'Neu' },
    { value: 'in_review', label: 'In Bearbeitung' },
    { value: 'replied', label: 'Beantwortet' },
    { value: 'confirmed', label: 'Bestätigt' },
    { value: 'declined', label: 'Abgelehnt' },
    { value: 'cancelled', label: 'Storniert' },
];

const SOURCE_OPTIONS: { value: ReservationSource; label: string }[] = [
    { value: 'web_form', label: 'Webformular' },
    { value: 'email', label: 'E-Mail' },
];

const STATUS_BADGE_CLASS: Record<ReservationStatus, string> = {
    new: 'bg-blue-100 text-blue-900 dark:bg-blue-900/40 dark:text-blue-200',
    in_review: 'bg-indigo-100 text-indigo-900 dark:bg-indigo-900/40 dark:text-indigo-200',
    replied: 'bg-purple-100 text-purple-900 dark:bg-purple-900/40 dark:text-purple-200',
    confirmed: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-200',
    declined: 'bg-rose-100 text-rose-900 dark:bg-rose-900/40 dark:text-rose-200',
    cancelled: 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
};

const STATUS_LABEL: Record<ReservationStatus, string> = Object.fromEntries(STATUS_OPTIONS.map((s) => [s.value, s.label])) as Record<
    ReservationStatus,
    string
>;

const SOURCE_LABEL: Record<ReservationSource, string> = Object.fromEntries(SOURCE_OPTIONS.map((s) => [s.value, s.label])) as Record<
    ReservationSource,
    string
>;

const search = ref(props.filters.q ?? '');
const fromDate = ref(props.filters.from ?? '');
const toDate = ref(props.filters.to ?? '');

watch(
    () => props.filters,
    (next) => {
        search.value = next.q ?? '';
        fromDate.value = next.from ?? '';
        toDate.value = next.to ?? '';
    },
);

function buildQuery(overrides: DashboardFilters): Record<string, unknown> {
    const next: Record<string, unknown> = {
        ...props.filters,
        ...overrides,
    };

    for (const key of Object.keys(next)) {
        const value = next[key];
        const isEmptyArray = Array.isArray(value) && value.length === 0;
        const isEmptyString = typeof value === 'string' && value.length === 0;
        const isNullish = value === null || value === undefined;

        if (isEmptyArray || isEmptyString || isNullish) {
            delete next[key];
        }
    }

    return next;
}

function applyFilter(overrides: DashboardFilters) {
    router.get(route('dashboard'), buildQuery(overrides), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        only: ['filters', 'requests', 'stats'],
    });
}

function toggleStatus(value: ReservationStatus) {
    const current = props.filters.status ?? [];
    const next = current.includes(value) ? current.filter((s) => s !== value) : [...current, value];
    applyFilter({ status: next });
}

function toggleSource(value: ReservationSource) {
    const current = props.filters.source ?? [];
    const next = current.includes(value) ? current.filter((s) => s !== value) : [...current, value];
    applyFilter({ source: next });
}

function submitSearch() {
    applyFilter({ q: search.value });
}

function commitDateRange() {
    applyFilter({ from: fromDate.value, to: toDate.value });
}

function clearAllFilters() {
    router.get(
        route('dashboard'),
        { clear: '1' },
        { preserveScroll: true, preserveState: true, replace: true, only: ['filters', 'requests', 'stats'] },
    );
}

function isStatusActive(value: ReservationStatus): boolean {
    return (props.filters.status ?? []).includes(value);
}

function isSourceActive(value: ReservationSource): boolean {
    return (props.filters.source ?? []).includes(value);
}

const hasActiveFilters = computed(() => {
    const f = props.filters;
    return (
        (f.status && f.status.length > 0) ||
        (f.source && f.source.length > 0) ||
        (f.from && f.from.length > 0) ||
        (f.to && f.to.length > 0) ||
        (f.q && f.q.length > 0)
    );
});

function formatDateTime(iso: string | null): string {
    if (!iso) {
        return '–';
    }

    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '–';
    }

    return new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

function detailHref(rowId: number | null): string {
    const data = buildQuery({});
    if (rowId !== null) {
        data.selected = rowId;
    } else {
        delete data.selected;
    }

    return route('dashboard', data);
}

const drawerOpen = computed({
    get: () => props.selectedRequest != null,
    set: (open: boolean) => {
        if (open || props.selectedRequest == null) {
            return;
        }

        router.visit(detailHref(null), {
            only: ['selectedRequest'],
            preserveScroll: true,
            preserveState: true,
        });
    },
});

const rawEmailOpen = ref(false);

watch(
    () => props.selectedRequest?.id,
    () => {
        rawEmailOpen.value = false;
    },
);

function pollOnly(): string[] {
    const keys = ['requests', 'stats'];
    if (props.selectedRequest != null) {
        keys.push('selectedRequest');
    }
    return keys;
}

const visibility = useDocumentVisibility();

const { pause: pausePolling, resume: resumePolling } = useIntervalFn(
    () => router.reload({ only: pollOnly(), preserveScroll: true, preserveState: true }),
    POLL_MS,
    { immediate: false, immediateCallback: false },
);

if (visibility.value === 'visible') {
    resumePolling();
}

watch(visibility, (state) => {
    if (state === 'visible') {
        resumePolling();
    } else {
        pausePolling();
    }
});
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <header class="flex flex-wrap items-end justify-between gap-4" data-testid="restaurant-header">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">{{ restaurantName }}</h1>
                    <p class="text-sm text-muted-foreground">Reservierungs-Dashboard</p>
                </div>

                <div class="flex flex-wrap items-center gap-3 text-sm" data-testid="dashboard-stats">
                    <span
                        class="rounded-md border border-blue-200 bg-blue-50 px-3 py-1 text-blue-900 dark:border-blue-900/40 dark:bg-blue-950/40 dark:text-blue-200"
                    >
                        Neu: <strong>{{ props.stats.new }}</strong>
                    </span>
                    <span
                        class="rounded-md border border-indigo-200 bg-indigo-50 px-3 py-1 text-indigo-900 dark:border-indigo-900/40 dark:bg-indigo-950/40 dark:text-indigo-200"
                    >
                        In Bearbeitung: <strong>{{ props.stats.in_review }}</strong>
                    </span>
                </div>
            </header>

            <section
                class="flex flex-col gap-4 rounded-lg border border-border p-4"
                data-testid="dashboard-filter-bar"
                aria-label="Reservierungs-Filter"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Status</span>
                    <button
                        v-for="opt in STATUS_OPTIONS"
                        :key="`status-${opt.value}`"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                        :class="
                            isStatusActive(opt.value)
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-input bg-background text-foreground hover:bg-muted'
                        "
                        :aria-pressed="isStatusActive(opt.value)"
                        @click="toggleStatus(opt.value)"
                    >
                        {{ opt.label }}
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Quelle</span>
                    <button
                        v-for="opt in SOURCE_OPTIONS"
                        :key="`source-${opt.value}`"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                        :class="
                            isSourceActive(opt.value)
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-input bg-background text-foreground hover:bg-muted'
                        "
                        :aria-pressed="isSourceActive(opt.value)"
                        @click="toggleSource(opt.value)"
                    >
                        {{ opt.label }}
                    </button>
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <label class="flex flex-col gap-1 text-xs">
                        <span class="font-semibold uppercase tracking-wide text-muted-foreground">Von</span>
                        <Input v-model="fromDate" type="date" class="h-9" data-testid="filter-from" @change="commitDateRange" />
                    </label>
                    <label class="flex flex-col gap-1 text-xs">
                        <span class="font-semibold uppercase tracking-wide text-muted-foreground">Bis</span>
                        <Input v-model="toDate" type="date" class="h-9" data-testid="filter-to" @change="commitDateRange" />
                    </label>

                    <form class="flex flex-1 items-end gap-2" @submit.prevent="submitSearch">
                        <label class="flex flex-1 flex-col gap-1 text-xs">
                            <span class="font-semibold uppercase tracking-wide text-muted-foreground">Suche</span>
                            <Input
                                v-model="search"
                                type="search"
                                class="h-9"
                                data-testid="filter-search"
                                placeholder="Name oder E-Mail"
                                @blur="submitSearch"
                            />
                        </label>
                        <Button type="submit" variant="secondary" class="h-9">Suchen</Button>
                    </form>

                    <Button v-if="hasActiveFilters" type="button" variant="ghost" class="h-9" data-testid="filter-clear" @click="clearAllFilters">
                        Filter zurücksetzen
                    </Button>
                </div>
            </section>

            <section
                v-if="props.requests.data.length === 0"
                data-testid="reservations-empty-state"
                class="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center dark:border-sidebar-border"
            >
                <p class="text-lg font-medium">Keine Reservierungen</p>
                <p class="mt-1 text-sm text-muted-foreground">Es gibt aktuell keine Anfragen, die zu deinen Filtern passen.</p>
            </section>

            <section v-else class="overflow-x-auto rounded-lg border border-border" data-testid="reservations-table">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Eingegangen</th>
                            <th class="px-3 py-2 font-medium">Wunschzeit</th>
                            <th class="px-3 py-2 font-medium">Personen</th>
                            <th class="px-3 py-2 font-medium">Name</th>
                            <th class="px-3 py-2 font-medium">Quelle</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in props.requests.data" :key="row.id" class="hover:bg-muted/30" :data-testid="`reservation-row-${row.id}`">
                            <td class="whitespace-nowrap px-3 py-2 text-muted-foreground">
                                {{ formatDateTime(row.created_at) }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2">{{ formatDateTime(row.desired_at) }}</td>
                            <td class="px-3 py-2">{{ row.party_size }}</td>
                            <td class="px-3 py-2">{{ row.guest_name }}</td>
                            <td class="px-3 py-2 text-xs text-muted-foreground">{{ SOURCE_LABEL[row.source] }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="STATUS_BADGE_CLASS[row.status]"
                                    >
                                        {{ STATUS_LABEL[row.status] }}
                                    </span>
                                    <span
                                        v-if="row.needs_manual_review"
                                        data-testid="needs-review-chip"
                                        class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-900/40 dark:text-amber-200"
                                        title="Beim automatischen Parsen waren nicht alle Felder eindeutig — bitte manuell prüfen."
                                    >
                                        Prüfen
                                    </span>
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <Link
                                    :href="detailHref(row.id)"
                                    :only="['selectedRequest']"
                                    :preserve-scroll="true"
                                    :preserve-state="true"
                                    class="text-sm font-medium text-primary hover:underline"
                                    :data-testid="`open-detail-${row.id}`"
                                >
                                    Details
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <nav
                v-if="props.requests.meta.last_page > 1"
                class="flex items-center justify-between gap-3 text-sm"
                data-testid="dashboard-pagination"
                aria-label="Seitennavigation"
            >
                <span class="text-muted-foreground">
                    Seite {{ props.requests.meta.current_page }} von {{ props.requests.meta.last_page }} — {{ props.requests.meta.total }} Einträge
                </span>
                <div class="flex flex-wrap items-center gap-1">
                    <Link
                        v-for="(link, index) in props.requests.meta.links"
                        :key="`page-${index}`"
                        :href="link.url ?? ''"
                        :as="link.url ? 'a' : 'span'"
                        class="rounded-md border px-3 py-1 text-xs font-medium transition-colors"
                        :class="[
                            link.active ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-background text-foreground',
                            !link.url ? 'cursor-not-allowed opacity-50' : 'hover:bg-muted',
                        ]"
                        :preserve-scroll="true"
                        :preserve-state="true"
                        :only="['filters', 'requests', 'stats']"
                    >
                        <span v-html="link.label" />
                    </Link>
                </div>
            </nav>

            <TooltipProvider :delay-duration="150">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <aside
                            data-testid="known-limitation-threading"
                            class="flex cursor-help items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-left text-sm text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200"
                        >
                            <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            <div>
                                <p class="font-medium">Hinweis V1.0: Antworten des Gastes erzeugen eine neue Anfrage</p>
                                <p class="mt-1 text-amber-900/80 dark:text-amber-200/80">
                                    Wenn ein Gast auf eine versendete Bestätigung antwortet, erscheint die Antwort aktuell als neue
                                    Reservierungsanfrage. Automatisches Threading folgt in V2.0 – bis dahin bitte Einträge manuell zusammenführen.
                                </p>
                            </div>
                        </aside>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-sm text-xs leading-relaxed">
                        In V1.0 akzeptieren wir Dubletten im Dashboard und verlassen uns auf manuellen Merge durch den Gastronom. Threading (Zuordnung
                        späterer Mails zur ursprünglichen Reservierung) ist für V2.0 geplant. Details: docs/PRD-003-email-ingestion.md § Risiken &amp;
                        offene Fragen.
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>

        <Sheet v-model:open="drawerOpen">
            <SheetContent v-if="props.selectedRequest" class="flex w-full flex-col gap-4 sm:max-w-lg" data-testid="reservation-detail-drawer">
                <SheetHeader>
                    <SheetTitle>{{ props.selectedRequest.guest_name }}</SheetTitle>
                    <SheetDescription>
                        {{ SOURCE_LABEL[props.selectedRequest.source] }} ·
                        {{ STATUS_LABEL[props.selectedRequest.status] }}
                    </SheetDescription>
                </SheetHeader>

                <dl class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-2 text-sm" data-testid="reservation-detail-fields">
                    <dt class="font-medium text-muted-foreground">Eingegangen</dt>
                    <dd>{{ formatDateTime(props.selectedRequest.created_at) }}</dd>

                    <dt class="font-medium text-muted-foreground">Wunschzeit</dt>
                    <dd>{{ formatDateTime(props.selectedRequest.desired_at) }}</dd>

                    <dt class="font-medium text-muted-foreground">Personen</dt>
                    <dd>{{ props.selectedRequest.party_size }}</dd>

                    <dt class="font-medium text-muted-foreground">E-Mail</dt>
                    <dd class="break-all">{{ props.selectedRequest.guest_email ?? '–' }}</dd>

                    <dt class="font-medium text-muted-foreground">Telefon</dt>
                    <dd>{{ props.selectedRequest.guest_phone ?? '–' }}</dd>
                </dl>

                <section v-if="props.selectedRequest.message" class="space-y-1.5">
                    <h3 class="text-sm font-medium text-muted-foreground">Nachricht</h3>
                    <p class="whitespace-pre-line rounded-md border border-border bg-muted/30 p-3 text-sm">
                        {{ props.selectedRequest.message }}
                    </p>
                </section>

                <Collapsible
                    v-if="props.selectedRequest.raw_email_body"
                    v-model:open="rawEmailOpen"
                    class="space-y-2"
                    data-testid="raw-email-collapsible"
                >
                    <CollapsibleTrigger as-child>
                        <Button variant="outline" size="sm" class="w-full justify-between">
                            <span>Original-E-Mail anzeigen</span>
                            <ChevronDown class="size-4 transition-transform" :class="rawEmailOpen ? 'rotate-180' : ''" />
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <pre
                            class="max-h-80 overflow-auto whitespace-pre-wrap rounded-md border border-border bg-muted/30 p-3 text-xs leading-relaxed"
                            data-testid="raw-email-body"
                            >{{ props.selectedRequest.raw_email_body }}</pre
                        >
                    </CollapsibleContent>
                </Collapsible>
            </SheetContent>
        </Sheet>
    </AppLayout>
</template>

<script setup lang="ts">
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { Info } from 'lucide-vue-next';
import { computed } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const page = usePage<SharedData>();
const restaurantName = computed(() => page.props.restaurant?.name ?? '');
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <header data-testid="restaurant-header">
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ restaurantName }}
                </h1>
                <p class="text-sm text-muted-foreground">Willkommen im Reservierungs-Dashboard.</p>
            </header>

            <section
                data-testid="reservations-empty-state"
                class="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center dark:border-sidebar-border"
            >
                <p class="text-lg font-medium">Noch keine Reservierungen</p>
                <p class="mt-1 text-sm text-muted-foreground">Sobald eine Anfrage eingeht, erscheint sie hier.</p>
            </section>

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
    </AppLayout>
</template>

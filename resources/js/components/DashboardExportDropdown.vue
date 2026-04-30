<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { DashboardFilters } from '@/types';
import { router } from '@inertiajs/vue3';
import { Download, Info } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    filters: DashboardFilters;
}>();

const exporting = ref(false);

function startExport(format: 'csv' | 'pdf') {
    if (exporting.value) {
        return;
    }
    exporting.value = true;

    // Pass the dashboard filter set verbatim — the backend's
    // ExportRequest reuses the same WithDashboardFilters trait
    // the dashboard filter request uses, so empty keys + invalid
    // values get rejected on the server.
    const payload: Record<string, unknown> = { format, ...props.filters };

    router.post(route('exports.store'), payload, {
        preserveScroll: true,
        preserveState: true,
        // For the sync path the controller returns a streamed
        // download — Inertia's onFinish still fires, the browser
        // handles the file dialog out-of-band.
        onFinish: () => {
            exporting.value = false;
        },
    });
}
</script>

<template>
    <div class="flex items-center gap-1" data-testid="dashboard-export-dropdown">
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button variant="outline" size="sm" :disabled="exporting" data-testid="dashboard-export-trigger">
                    <Download class="size-4" aria-hidden="true" />
                    <span class="ml-2">Export</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem data-testid="dashboard-export-csv" @click="startExport('csv')"> Als CSV exportieren </DropdownMenuItem>
                <DropdownMenuItem data-testid="dashboard-export-pdf" @click="startExport('pdf')"> Als PDF exportieren </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <TooltipProvider>
            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="rounded-full p-1 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        :aria-label="'Datenschutzhinweis zum Export'"
                        data-testid="dashboard-export-gdpr-info"
                    >
                        <Info class="size-4" aria-hidden="true" />
                    </button>
                </TooltipTrigger>
                <TooltipContent class="max-w-xs text-xs leading-relaxed">
                    Der Export enthält personenbezogene Daten (Gast-Name, E-Mail, Telefon). Die Verarbeitung erfolgt nach der Datenschutzerklärung des
                    Restaurants.
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    </div>
</template>

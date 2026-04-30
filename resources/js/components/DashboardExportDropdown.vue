<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { DashboardFilters } from '@/types';
import { Download, Info } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    filters: DashboardFilters;
}>();

const exporting = ref(false);

/**
 * Build a hidden HTML form and submit it so the browser handles
 * the response natively. Inertia's `router.post` is XHR — it
 * cannot trigger the save dialog when the controller returns a
 * `StreamedResponse` for the sync path; the response would
 * render as a non-Inertia error overlay instead. The async path
 * returns a 302 redirect, which the browser follows back to the
 * dashboard with the flash message intact.
 */
function appendField(form: HTMLFormElement, name: string, value: unknown): void {
    if (Array.isArray(value)) {
        for (const item of value) {
            appendField(form, `${name}[]`, item);
        }

        return;
    }

    if (value === null || value === undefined || value === '') {
        return;
    }

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = String(value);
    form.appendChild(input);
}

function startExport(format: 'csv' | 'pdf') {
    if (exporting.value) {
        return;
    }
    exporting.value = true;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = route('exports.store');
    form.style.display = 'none';

    const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
    appendField(form, '_token', csrf);
    appendField(form, 'format', format);

    for (const [key, value] of Object.entries(props.filters)) {
        appendField(form, key, value);
    }

    document.body.appendChild(form);
    form.submit();

    // Browser owns the response from here. Either a download
    // dialog (sync) or a navigation back to the dashboard
    // (async) — both leave us free to clean the form up after a
    // short tick and re-enable the button.
    setTimeout(() => {
        form.remove();
        exporting.value = false;
    }, 500);
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

<script setup lang="ts">
import TableForm from '@/components/tables/TableForm.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type TableModel } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    tables: { data: TableModel[] };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tische', href: '/tables' }];

type TabKey = 'stammdaten' | 'belegung';
const tabs: Array<{ value: TabKey; label: string }> = [
    { value: 'stammdaten', label: 'Stammdaten' },
    { value: 'belegung', label: 'Belegung' },
];
const activeTab = ref<TabKey>('stammdaten');

const rows = computed(() => props.tables.data);
const labelById = computed(() => new Map(rows.value.map((table) => [table.id, table.label])));

function combinableLabels(table: TableModel): string {
    if (table.combinable_with.length === 0) {
        return '–';
    }
    return table.combinable_with.map((id) => labelById.value.get(id) ?? `#${id}`).join(', ');
}

const showForm = ref(false);
const editing = ref<TableModel | null>(null);

// On create every table is a combinable candidate; on edit a table cannot be
// combined with itself, so it is filtered out of the sibling list.
const siblings = computed<TableModel[]>(() => {
    const current = editing.value;
    return current ? rows.value.filter((table) => table.id !== current.id) : rows.value;
});

function openCreate(): void {
    editing.value = null;
    showForm.value = true;
}

function openEdit(table: TableModel): void {
    editing.value = table;
    showForm.value = true;
}

// The store/update controllers redirect to tables.index, so Inertia reloads
// the list automatically — closing the drawer is all the page has to do.
function onSaved(): void {
    showForm.value = false;
}

const confirmingId = ref<number | null>(null);

function requestDelete(table: TableModel): void {
    confirmingId.value = table.id;
}

function cancelDelete(): void {
    confirmingId.value = null;
}

function confirmDelete(table: TableModel): void {
    router.delete(route('tables.destroy', { table: table.id }), {
        preserveScroll: true,
        onFinish: () => {
            confirmingId.value = null;
        },
    });
}
</script>

<template>
    <Head title="Tische" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4 md:p-6">
            <header class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-semibold tracking-tight">Tische</h1>
                <Button type="button" data-testid="new-table" @click="openCreate">+ Neuer Tisch</Button>
            </header>

            <div role="tablist" aria-label="Tisch-Ansicht" class="flex gap-2 border-b border-border">
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === tab.value"
                    :data-active="activeTab === tab.value"
                    :data-testid="`tab-${tab.value}`"
                    class="-mb-px border-b-2 px-3 py-2 text-sm transition"
                    :class="
                        activeTab === tab.value
                            ? 'border-primary font-medium text-primary'
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                    "
                    @click="activeTab = tab.value"
                >
                    {{ tab.label }}
                </button>
            </div>

            <section v-if="activeTab === 'stammdaten'" data-testid="tab-panel-stammdaten">
                <div v-if="rows.length === 0" class="rounded-lg border border-dashed p-10 text-center" data-testid="empty-state">
                    <p class="text-lg font-medium">Noch keine Tische angelegt.</p>
                    <p class="mt-1 text-sm text-muted-foreground">Lege deinen ersten Tisch an, um die Verfügbarkeit zu pflegen.</p>
                </div>

                <div v-else class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-muted/40">
                            <tr class="border-b border-border">
                                <th class="px-4 py-2">Bezeichnung</th>
                                <th class="px-4 py-2">Plätze</th>
                                <th class="px-4 py-2">Bereich</th>
                                <th class="px-4 py-2">Kombinierbar mit</th>
                                <th class="px-4 py-2">Aktiv</th>
                                <th class="px-4 py-2 text-right">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="table in rows"
                                :key="table.id"
                                data-testid="table-row"
                                class="border-b border-border last:border-0 hover:bg-muted/30"
                            >
                                <td class="px-4 py-2 font-medium">{{ table.label }}</td>
                                <td class="px-4 py-2">{{ table.seats }}</td>
                                <td class="px-4 py-2">{{ table.room_tag ?? '–' }}</td>
                                <td class="px-4 py-2">{{ combinableLabels(table) }}</td>
                                <td class="px-4 py-2">
                                    <span :class="table.active ? 'text-foreground' : 'text-muted-foreground'">
                                        {{ table.active ? 'Ja' : 'Nein' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <template v-if="confirmingId === table.id">
                                        <span class="mr-2 text-xs text-muted-foreground">Wirklich deaktivieren?</span>
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            size="sm"
                                            :data-testid="`confirm-delete-${table.id}`"
                                            @click="confirmDelete(table)"
                                        >
                                            Ja
                                        </Button>
                                        <Button type="button" variant="ghost" size="sm" class="ml-1" @click="cancelDelete">Abbrechen</Button>
                                    </template>
                                    <template v-else>
                                        <Button type="button" variant="ghost" size="sm" :data-testid="`edit-${table.id}`" @click="openEdit(table)">
                                            Bearbeiten
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            class="ml-1 text-destructive"
                                            :data-testid="`delete-${table.id}`"
                                            @click="requestDelete(table)"
                                        >
                                            Löschen
                                        </Button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-else data-testid="tab-panel-belegung" class="rounded-lg border border-dashed p-10 text-center">
                <p class="text-lg font-medium">Belegungs-Ansicht folgt.</p>
                <p class="mt-1 text-sm text-muted-foreground">Die Tages-Belegung wird in einem späteren Schritt ergänzt.</p>
            </section>
        </div>

        <TableForm v-if="showForm" :table="editing" :siblings="siblings" @close="showForm = false" @saved="onSaved" />
    </AppLayout>
</template>

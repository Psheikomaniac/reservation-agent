<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type QuickAvailability, type TableModel } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';

const props = defineProps<{
    tables: { data: TableModel[] };
    defaults: { date: string; time: string; party_size: number };
    availability: QuickAvailability;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Telefon-Reservierung', href: '/reservations/quick' }];

// Matches the native-select styling used by the public ReservationForm so the
// dropdowns line up with the adjacent Input fields.
const selectClass =
    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 md:text-sm';

const form = reactive({
    source: 'phone' as 'phone' | 'walk_in',
    date: props.defaults.date,
    time: props.defaults.time,
    party_size: props.defaults.party_size,
    guest_name: '',
    guest_phone: '',
    guest_email: '',
    note: '',
    table_id: null as number | null,
});

const errors = ref<Record<string, string>>({});
const processing = ref(false);

const tableById = computed(() => new Map(props.tables.data.map((table) => [table.id, table])));

// Human-readable proposal for the banner: a single suggested table, or the
// labels of a two-table combination, resolved from the `tables` prop.
const proposedTables = computed<string | null>(() => {
    const { suggested_table_id, combination } = props.availability;
    if (suggested_table_id !== null) {
        const table = tableById.value.get(suggested_table_id);
        return table ? `Tisch ${table.label} (${table.seats} Plätze)` : null;
    }
    if (combination !== null) {
        return combination.table_ids.map((id) => `Tisch ${tableById.value.get(id)?.label ?? `#${id}`}`).join(' + ');
    }
    return null;
});

const bannerClass = computed(
    () =>
        ({
            free: 'border-green-300 bg-green-50 text-green-900',
            tight: 'border-amber-300 bg-amber-50 text-amber-900',
            full: 'border-red-300 bg-red-50 text-red-900',
        })[props.availability.state],
);

// Dirty = the operator has typed something worth losing. Date/time/party always
// carry the smart defaults, so they do not count; only the entered guest details
// and note do.
const isDirty = computed(() => form.guest_name !== '' || form.guest_phone !== '' || form.guest_email !== '' || form.note !== '');

// Live availability: re-fetch only the `availability` prop on every slot change,
// debounced so typing a date/time does not fire a request per keystroke. `form`
// is a detached reactive object (not bound to props), so it survives the reload;
// `preserveState` keeps the page from remounting. party_size is coerced because
// the wrapper <Input>'s v-model.number is a no-op, and the reload is skipped
// while the slot is incompletely entered so the server never sees an empty value.
const refreshAvailability = useDebounceFn(() => {
    const partySize = Number(form.party_size);
    if (form.date === '' || form.time === '' || !Number.isFinite(partySize) || partySize < 1) {
        return;
    }
    router.reload({
        only: ['availability'],
        data: { date: form.date, time: form.time, party_size: partySize },
        preserveState: true,
        preserveScroll: true,
    });
}, 250);

watch(() => [form.date, form.time, form.party_size], refreshAvailability);

function applySlot(slot: { date: string; time: string }): void {
    form.date = slot.date;
    form.time = slot.time;
}

function payload(): Record<string, unknown> {
    return {
        source: form.source,
        date: form.date,
        time: form.time,
        party_size: Number(form.party_size),
        guest_name: form.guest_name,
        guest_phone: form.guest_phone === '' ? null : form.guest_phone,
        guest_email: form.guest_email === '' ? null : form.guest_email,
        note: form.note === '' ? null : form.note,
        table_id: form.table_id,
    };
}

function submit(): void {
    if (processing.value) {
        return;
    }
    processing.value = true;
    errors.value = {};
    // On success the controller redirects to the dashboard, so the page does not
    // handle onSuccess itself.
    router.post(route('reservations.quick.store'), payload(), {
        preserveScroll: true,
        onError: (received: Record<string, string>) => {
            errors.value = received;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

function cancel(): void {
    if (isDirty.value && !window.confirm('Eingaben verwerfen?')) {
        return;
    }
    router.visit(route('dashboard'));
}

// Keyboard-first: Ctrl/Cmd+Enter submits from anywhere on the form, Esc cancels.
// isComposing guards against firing mid-IME-composition.
function onKeydown(event: KeyboardEvent): void {
    if (event.isComposing) {
        return;
    }
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();
        submit();
    } else if (event.key === 'Escape') {
        event.preventDefault();
        cancel();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Head title="Telefon-Reservierung" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
            <h1 class="text-2xl font-semibold tracking-tight">Telefon-Reservierung</h1>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="quick-source">Art</Label>
                    <select id="quick-source" v-model="form.source" data-testid="field-source" :class="selectClass">
                        <option value="phone">Telefon</option>
                        <option value="walk_in">Walk-in</option>
                    </select>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div class="grid gap-2">
                        <Label for="quick-date">Datum</Label>
                        <Input id="quick-date" v-model="form.date" type="date" data-testid="field-date" required />
                        <InputError :message="errors.date" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="quick-time">Zeit</Label>
                        <Input id="quick-time" v-model="form.time" type="time" data-testid="field-time" required />
                        <InputError :message="errors.time" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="quick-party">Personen</Label>
                        <Input
                            id="quick-party"
                            v-model.number="form.party_size"
                            type="number"
                            min="1"
                            max="20"
                            data-testid="field-party-size"
                            required
                        />
                        <InputError :message="errors.party_size" />
                    </div>
                </div>

                <div class="rounded-lg border p-3 text-sm" :class="bannerClass" data-testid="availability-banner">
                    <p v-if="availability.state === 'free'">
                        Slot frei<span v-if="proposedTables"> – {{ proposedTables }} wird vorgeschlagen</span>
                    </p>
                    <p v-else-if="availability.state === 'tight'">
                        Slot knapp<span v-if="proposedTables"> – {{ proposedTables }} passt noch</span>, andere Slots sind sicherer
                    </p>
                    <template v-else>
                        <p>Slot belegt.</p>
                        <div v-if="availability.alternative_slots.length > 0" class="mt-2 flex flex-wrap items-center gap-2">
                            <span>Vorschläge:</span>
                            <button
                                v-for="slot in availability.alternative_slots"
                                :key="`${slot.date} ${slot.time}`"
                                type="button"
                                class="rounded border border-current px-2 py-0.5 underline"
                                data-testid="alt-slot"
                                @click="applySlot(slot)"
                            >
                                {{ slot.time }}
                            </button>
                        </div>
                    </template>
                </div>

                <div class="grid gap-2">
                    <Label for="quick-name">Name</Label>
                    <Input id="quick-name" v-model="form.guest_name" data-testid="field-name" required />
                    <InputError :message="errors.guest_name" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="quick-phone">Telefon</Label>
                        <Input id="quick-phone" v-model="form.guest_phone" data-testid="field-phone" placeholder="+49 …" />
                        <InputError :message="errors.guest_phone" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="quick-email">E-Mail</Label>
                        <Input id="quick-email" v-model="form.guest_email" type="email" data-testid="field-email" placeholder="optional" />
                        <InputError :message="errors.guest_email" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="quick-note">Anmerkung</Label>
                    <Input id="quick-note" v-model="form.note" data-testid="field-note" placeholder="optional, z. B. Geburtstag" />
                    <InputError :message="errors.note" />
                </div>

                <div class="grid gap-2">
                    <Label for="quick-table">Tisch</Label>
                    <select id="quick-table" v-model="form.table_id" data-testid="field-table" :class="selectClass">
                        <option :value="null">Automatisch zuweisen</option>
                        <option v-for="table in tables.data" :key="table.id" :value="table.id">
                            Tisch {{ table.label }} ({{ table.seats }} Plätze)
                        </option>
                    </select>
                    <InputError :message="errors.table_id" />
                </div>

                <div class="mt-2 flex justify-end gap-2">
                    <Button type="button" variant="outline" data-testid="cancel" @click="cancel">Abbrechen (Esc)</Button>
                    <Button type="submit" :disabled="processing" data-testid="submit">Speichern (Strg+Enter)</Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

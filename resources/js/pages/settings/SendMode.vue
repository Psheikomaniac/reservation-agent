<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

type SendMode = 'manual' | 'shadow' | 'auto';

interface ShadowStats {
    total: number;
    takenOver: number;
    takeoverRate: number | null;
    hasData: boolean;
}

interface Props {
    restaurantId: number;
    sendMode: SendMode;
    partySizeMax: number;
    minLeadTimeMinutes: number;
    sendModeChangedAt: string | null;
    shadowStats: ShadowStats;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Versand-Modus', href: '/settings/send-mode' }];

const form = useForm({
    send_mode: props.sendMode,
    auto_send_party_size_max: props.partySizeMax,
    auto_send_min_lead_time_minutes: props.minLeadTimeMinutes,
});

const pendingMode = ref<SendMode | null>(null);

const modeCards: ReadonlyArray<{
    value: SendMode;
    title: string;
    description: string;
    risk: 'Low' | 'Medium' | 'High';
}> = [
    {
        value: 'manual',
        title: 'Manuelle Freigabe',
        description: 'Jede KI-Antwort wartet auf Ihre Freigabe (V1.0-Verhalten). Sicherster Modus.',
        risk: 'Low',
    },
    {
        value: 'shadow',
        title: 'Shadow-Modus (Test)',
        description:
            'Antworten werden generiert und markiert "wäre versendet worden", aber NICHT versendet. Wir messen Ihre Übernahme-Rate, damit Sie informiert auf Auto wechseln können.',
        risk: 'Medium',
    },
    {
        value: 'auto',
        title: 'Automatischer Versand',
        description:
            'Antworten gehen 60 Sekunden nach Generierung automatisch raus, sofern keine Sicherheitsregel greift. In dieser Zeit können Sie sie noch abbrechen.',
        risk: 'High',
    },
];

const riskBadgeClass = (risk: 'Low' | 'Medium' | 'High') =>
    risk === 'Low'
        ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200'
        : risk === 'Medium'
          ? 'bg-amber-100 text-amber-900 dark:bg-amber-900/30 dark:text-amber-200'
          : 'bg-red-100 text-red-900 dark:bg-red-900/30 dark:text-red-200';

const showsKillswitch = computed(() => props.sendMode !== 'manual');

const isShadowToAuto = computed(() => pendingMode.value === 'auto' && props.sendMode === 'shadow');

const takeoverRatePercent = computed(() => (props.shadowStats.takeoverRate !== null ? Math.round(props.shadowStats.takeoverRate * 100) : null));

const requestModeChange = (mode: SendMode) => {
    if (mode === props.sendMode) {
        return;
    }
    pendingMode.value = mode;
};

const cancelModeChange = () => {
    pendingMode.value = null;
};

const confirmModeChange = () => {
    if (pendingMode.value === null) {
        return;
    }
    form.send_mode = pendingMode.value;
    submit(() => {
        pendingMode.value = null;
    });
};

const submit = (onSuccess?: () => void) => {
    form.patch(route('settings.send-mode.update'), {
        preserveScroll: true,
        onSuccess,
    });
};

const triggerKillswitch = () => {
    if (!window.confirm('Auto-Versand sofort stoppen? Alle ausstehenden Versandvorgänge werden abgebrochen.')) {
        return;
    }
    router.post(
        route('restaurants.send-mode.killswitch', {
            restaurant: props.restaurantId,
        }),
        {},
        { preserveScroll: true },
    );
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Versand-Modus" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="Versand-Modus"
                    description="Wählen Sie, wie Antwortvorschläge an Gäste herausgehen. Hard-Gates greifen unabhängig vom Modus."
                />

                <div class="grid gap-4 md:grid-cols-3">
                    <div
                        v-for="card in modeCards"
                        :key="card.value"
                        :class="[
                            'flex flex-col rounded-lg border p-4 shadow-sm',
                            card.value === sendMode ? 'border-primary ring-2 ring-primary/40' : 'border-border',
                        ]"
                        :data-mode="card.value"
                        :data-active="card.value === sendMode"
                    >
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold">{{ card.title }}</h3>
                            <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', riskBadgeClass(card.risk)]">
                                Risiko: {{ card.risk }}
                            </span>
                        </div>
                        <p class="mt-2 flex-1 text-sm text-muted-foreground">
                            {{ card.description }}
                        </p>
                        <Button class="mt-4" :disabled="card.value === sendMode || form.processing" @click="requestModeChange(card.value)">
                            {{ card.value === sendMode ? 'Aktiv' : 'Aktivieren' }}
                        </Button>
                    </div>
                </div>

                <p v-if="sendMode !== 'manual'" class="rounded-md bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-900/20 dark:text-amber-200">
                    Datenschutz-Hinweis: Auto-Versand muss in der Datenschutzerklärung des Restaurants erwähnt werden.
                </p>

                <form @submit.prevent="submit()" class="grid gap-6 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="party-size-max"> Max. Personenzahl für Auto-Versand </Label>
                        <Input id="party-size-max" type="number" min="1" max="50" v-model="form.auto_send_party_size_max" />
                        <InputError :message="form.errors.auto_send_party_size_max" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="min-lead-time"> Mindest-Vorlauf in Minuten </Label>
                        <Input id="min-lead-time" type="number" min="0" max="1440" v-model="form.auto_send_min_lead_time_minutes" />
                        <InputError :message="form.errors.auto_send_min_lead_time_minutes" />
                    </div>

                    <div class="md:col-span-2">
                        <Button type="submit" :disabled="form.processing"> Einstellungen speichern </Button>
                    </div>
                </form>

                <section v-if="showsKillswitch" class="rounded-md border border-red-300 bg-red-50 p-4 dark:border-red-900/50 dark:bg-red-900/20">
                    <h3 class="text-base font-semibold text-red-900 dark:text-red-200">Killswitch</h3>
                    <p class="mt-1 text-sm text-red-900/80 dark:text-red-200/80">
                        Stoppt Auto-Versand sofort und bricht alle ausstehenden Versandvorgänge ab.
                    </p>
                    <Button class="mt-3" variant="destructive" @click="triggerKillswitch"> Auto-Versand sofort stoppen </Button>
                </section>
            </div>

            <Dialog :open="pendingMode !== null" @update:open="(open) => !open && cancelModeChange()">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Modus wechseln zu
                            {{ pendingMode === 'auto' ? 'Automatischer Versand' : pendingMode === 'shadow' ? 'Shadow-Modus' : 'Manuelle Freigabe' }}
                            ?
                        </DialogTitle>
                        <DialogDescription v-if="isShadowToAuto && shadowStats.hasData">
                            In den letzten 30 Tagen wurden {{ shadowStats.takenOver }} von {{ shadowStats.total }} Shadow-Antworten ohne Änderung
                            übernommen ({{ takeoverRatePercent }}%). Auto-Versand bedeutet: ab jetzt gehen Antworten automatisch an Gäste, sofern
                            keine Sicherheitsregel greift.
                        </DialogDescription>
                        <DialogDescription v-else-if="isShadowToAuto">
                            Es liegen noch keine Shadow-Daten vor. Wir empfehlen mindestens 30 Tage Shadow-Modus, bevor Sie auf Auto wechseln.
                        </DialogDescription>
                        <DialogDescription v-else> Sind Sie sicher, dass Sie den Modus jetzt wechseln möchten? </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" @click="cancelModeChange">Abbrechen</Button>
                        <Button @click="confirmModeChange" :disabled="form.processing"> Bestätigen </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </SettingsLayout>
    </AppLayout>
</template>

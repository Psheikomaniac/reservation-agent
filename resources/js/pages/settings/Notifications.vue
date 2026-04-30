<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useNotifications } from '@/composables/useNotifications';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type NotificationSettings } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Props {
    settings: NotificationSettings;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Einstellungen', href: '/settings/profile' },
    { title: 'Benachrichtigungen', href: '/settings/notifications' },
];

const form = useForm({
    browser_notifications: props.settings.browser_notifications,
    sound_alerts: props.settings.sound_alerts,
    sound: props.settings.sound,
    volume: props.settings.volume,
    daily_digest: props.settings.daily_digest,
    daily_digest_at: props.settings.daily_digest_at,
});

// `useNotifications` reads the current Browser-permission via window.Notification
// at construction time. Wrapping the form values in a `Ref` lets the composable
// observe live edits — the "Test sound" button always plays at the current
// slider value, not the value that was active when the page mounted.
const liveSettings = computed<NotificationSettings>(() => ({
    browser_notifications: form.browser_notifications,
    sound_alerts: form.sound_alerts,
    sound: form.sound,
    volume: form.volume,
    daily_digest: form.daily_digest,
    daily_digest_at: form.daily_digest_at,
}));
const liveSettingsRef = computed(() => liveSettings.value);
const notifications = useNotifications(
    // `useNotifications` types `settings` as a `Ref<NotificationSettings>`,
    // but it only ever reads `.value`. A computed ref satisfies that contract
    // and stays in sync with the form.
    liveSettingsRef as unknown as import('vue').Ref<NotificationSettings>,
);

const SOUND_OPTIONS: ReadonlyArray<{ value: 'default' | 'chime' | 'tap'; label: string }> = [
    { value: 'default', label: 'Hinweiston' },
    { value: 'chime', label: 'Glöckchen' },
    { value: 'tap', label: 'Klopfen' },
];

const permissionState = computed<'default' | 'granted' | 'denied'>(() => notifications.permission.value);

const permissionDescription = computed(() => {
    if (permissionState.value === 'granted') {
        return 'Browser-Notifications sind aktiv. Du kannst sie hier jederzeit pausieren — die Browser-Berechtigung selbst bleibt erhalten.';
    }
    if (permissionState.value === 'denied') {
        return 'Du hast Notifications für diese Seite blockiert. Aktiviere sie in den Browser-Einstellungen, dann kommt die Toggle hier zurück.';
    }
    return 'Klicke auf „Aktivieren", um Browser-Notifications zu erlauben. Die Anfrage erscheint nur einmal — wir fragen nicht im Hintergrund.';
});

const browserToggleDisabled = computed(() => permissionState.value === 'denied');

const requestingPermission = ref(false);

async function activateBrowserNotifications(): Promise<void> {
    requestingPermission.value = true;
    try {
        const result = await notifications.requestPermission();
        if (result === 'granted') {
            form.browser_notifications = true;
        }
    } finally {
        requestingPermission.value = false;
    }
}

function testSound(soundKey: 'default' | 'chime' | 'tap'): void {
    // The composable refuses to play if `sound_alerts` is off — but the
    // user clicked "Test sound" exactly to preview it. Force-feed an
    // override so the preview always plays at the current volume.
    if (!form.sound_alerts) {
        const audio = new Audio(`/sounds/${soundKey}.mp3`);
        audio.volume = clampVolume(form.volume);
        void audio.play().catch(() => {
            // user-gesture / autoplay blocks: stay silent on purpose
        });
        return;
    }
    notifications.playSound(soundKey);
}

function clampVolume(percent: number): number {
    if (Number.isNaN(percent)) return 0;
    const ratio = percent / 100;
    if (ratio < 0) return 0;
    if (ratio > 1) return 1;
    return ratio;
}

function submit(): void {
    form.put(route('settings.notifications.update'), {
        preserveScroll: true,
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Benachrichtigungen" />

        <form class="flex flex-col gap-8 p-6" data-testid="notification-settings-form" @submit.prevent="submit">
            <HeadingSmall
                title="Benachrichtigungen"
                description="Browser-Push, Sound-Alerts und der tägliche Digest. Alle drei lassen sich unabhängig voneinander de-/aktivieren."
            />

            <section class="rounded-lg border border-border p-4" data-testid="browser-notifications-section">
                <header class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-base font-semibold">Browser-Notifications</h2>
                        <p class="text-xs text-muted-foreground">
                            Erscheinen am Bildschirmrand, sobald eine neue Anfrage eintrifft. Funktioniert nur in dem Tab, der das Dashboard offen
                            hat.
                        </p>
                    </div>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="{
                            'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-200': permissionState === 'granted',
                            'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200': permissionState === 'default',
                            'bg-rose-100 text-rose-900 dark:bg-rose-900/40 dark:text-rose-200': permissionState === 'denied',
                        }"
                        data-testid="browser-permission-state"
                    >
                        Browser-Status:
                        {{ permissionState === 'granted' ? 'erlaubt' : permissionState === 'denied' ? 'blockiert' : 'nicht entschieden' }}
                    </span>
                </header>

                <p class="mt-3 text-xs text-muted-foreground" data-testid="browser-permission-description">
                    {{ permissionDescription }}
                </p>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <Checkbox
                        id="browser-notifications-toggle"
                        :checked="form.browser_notifications"
                        :disabled="browserToggleDisabled"
                        data-testid="browser-notifications-toggle"
                        @update:checked="(value) => (form.browser_notifications = Boolean(value))"
                    />
                    <Label for="browser-notifications-toggle">Browser-Notifications zeigen</Label>

                    <Button
                        v-if="permissionState === 'default'"
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="requestingPermission"
                        data-testid="browser-notifications-activate"
                        @click="activateBrowserNotifications"
                    >
                        Aktivieren
                    </Button>
                </div>
            </section>

            <section class="rounded-lg border border-border p-4" data-testid="sound-alerts-section">
                <h2 class="text-base font-semibold">Sound-Alerts</h2>
                <p class="text-xs text-muted-foreground">Optionaler Ton bei jeder neuen Anfrage. Standard: aus.</p>

                <div class="mt-4 flex items-center gap-3">
                    <Checkbox
                        id="sound-alerts-toggle"
                        :checked="form.sound_alerts"
                        data-testid="sound-alerts-toggle"
                        @update:checked="(value) => (form.sound_alerts = Boolean(value))"
                    />
                    <Label for="sound-alerts-toggle">Sound abspielen</Label>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Auswahl</Label>
                        <ul class="space-y-2" data-testid="sound-options">
                            <li
                                v-for="opt in SOUND_OPTIONS"
                                :key="opt.value"
                                class="flex items-center justify-between gap-3 rounded-md border border-input bg-background px-3 py-2"
                                :data-sound="opt.value"
                            >
                                <label class="flex flex-1 items-center gap-2 text-sm">
                                    <input
                                        type="radio"
                                        name="sound"
                                        :value="opt.value"
                                        :checked="form.sound === opt.value"
                                        :data-testid="`sound-radio-${opt.value}`"
                                        @change="form.sound = opt.value"
                                    />
                                    <span>{{ opt.label }}</span>
                                </label>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :data-testid="`sound-test-${opt.value}`"
                                    @click="testSound(opt.value)"
                                >
                                    Ton testen
                                </Button>
                            </li>
                        </ul>
                        <InputError class="mt-1" :message="form.errors.sound" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="volume-slider" class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Lautstärke ({{ form.volume }} %)
                        </Label>
                        <input
                            id="volume-slider"
                            type="range"
                            min="0"
                            max="100"
                            step="1"
                            v-model.number="form.volume"
                            class="w-full"
                            data-testid="sound-volume-slider"
                        />
                        <InputError class="mt-1" :message="form.errors.volume" />
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-border p-4" data-testid="daily-digest-section">
                <h2 class="text-base font-semibold">Tages-Digest per E-Mail</h2>
                <p class="text-xs text-muted-foreground">
                    Eine Übersicht der heutigen Anfragen — bestätigt, offen, Prüf-Bedarf — als E-Mail. Kommt unabhängig vom Browser-Status.
                </p>

                <div class="mt-4 flex flex-wrap items-end gap-4">
                    <div class="flex items-center gap-3">
                        <Checkbox
                            id="daily-digest-toggle"
                            :checked="form.daily_digest"
                            data-testid="daily-digest-toggle"
                            @update:checked="(value) => (form.daily_digest = Boolean(value))"
                        />
                        <Label for="daily-digest-toggle">Tages-Digest senden</Label>
                    </div>

                    <div class="grid gap-1">
                        <Label for="daily-digest-at" class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Sende-Uhrzeit (Restaurant-Zeit)
                        </Label>
                        <Input id="daily-digest-at" type="time" v-model="form.daily_digest_at" class="h-9 w-32" data-testid="daily-digest-at" />
                        <InputError class="mt-1" :message="form.errors.daily_digest_at" />
                    </div>
                </div>
            </section>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing" data-testid="notification-settings-save">Speichern</Button>
                <p v-if="form.recentlySuccessful" class="text-sm text-emerald-700 dark:text-emerald-400" data-testid="notification-settings-saved">
                    Gespeichert.
                </p>
            </div>
        </form>
    </AppLayout>
</template>

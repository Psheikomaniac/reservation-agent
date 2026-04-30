import type { NotificationSettings } from '@/types';
import { ref, type Ref } from 'vue';

type Permission = 'default' | 'granted' | 'denied';

export interface UseNotificationsHandle {
    /** Reactive snapshot of the browser's Notification permission. */
    permission: Ref<Permission>;
    /**
     * Request browser-notification permission from the user. Resolves
     * to the new permission state. No-ops (and resolves to `'denied'`)
     * when the Notification API isn't available — happy-dom in tests,
     * older Safari versions, etc.
     */
    requestPermission(): Promise<Permission>;
    /**
     * Show a browser notification — but only when the user opted in
     * (`settings.browser_notifications`) AND the browser actually
     * granted permission. All other cases are silent so the
     * dashboard polling loop can call `notify()` unconditionally
     * without checking guards itself.
     */
    notify(title: string, options?: NotificationOptions): void;
    /**
     * Play one of the bundled `/sounds/{key}.mp3` files. Silent when
     * the user opted out via `settings.sound_alerts`. The volume from
     * settings is clamped to [0, 1] so a corrupt JSON value can't
     * blow out the user's speakers.
     *
     * `audio.play()` returns a Promise that rejects when the browser
     * blocks the playback (typically: no user gesture has happened
     * yet). The rejection is intentionally swallowed — surfacing it
     * as a UI error would be louder than the silence the user
     * experiences.
     */
    playSound(soundKey?: string): void;
}

function readPermission(): Permission {
    if (typeof window === 'undefined' || typeof window.Notification === 'undefined') {
        return 'denied';
    }

    return window.Notification.permission as Permission;
}

function clampVolume(percent: number): number {
    if (Number.isNaN(percent)) {
        return 0;
    }

    const ratio = percent / 100;
    if (ratio < 0) return 0;
    if (ratio > 1) return 1;
    return ratio;
}

export function useNotifications(settings: Ref<NotificationSettings>): UseNotificationsHandle {
    const permission = ref<Permission>(readPermission());

    async function requestPermission(): Promise<Permission> {
        if (typeof window === 'undefined' || typeof window.Notification === 'undefined') {
            permission.value = 'denied';
            return 'denied';
        }

        const next = await window.Notification.requestPermission();
        permission.value = next as Permission;
        return permission.value;
    }

    function notify(title: string, options?: NotificationOptions): void {
        if (!settings.value.browser_notifications) {
            return;
        }
        if (typeof window === 'undefined' || typeof window.Notification === 'undefined') {
            return;
        }
        if (permission.value !== 'granted') {
            return;
        }

        // Construct directly — Notification is a side-effect API,
        // we don't need the instance for anything.
        new window.Notification(title, options);
    }

    function playSound(soundKey?: string): void {
        if (!settings.value.sound_alerts) {
            return;
        }
        if (typeof window === 'undefined' || typeof window.Audio === 'undefined') {
            return;
        }

        const key = soundKey ?? settings.value.sound ?? 'default';
        const audio = new window.Audio(`/sounds/${key}.mp3`);
        audio.volume = clampVolume(settings.value.volume);

        const result = audio.play();
        if (result && typeof result.then === 'function') {
            result.catch(() => {
                // Browser blocked playback (no user gesture yet,
                // hidden tab, autoplay policy). The composable's
                // contract is "fire and forget" — do not surface
                // a UI error here.
            });
        }
    }

    return { permission, requestPermission, notify, playSound };
}

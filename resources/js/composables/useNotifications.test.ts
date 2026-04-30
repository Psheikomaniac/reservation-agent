import type { NotificationSettings } from '@/types';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { useNotifications } from './useNotifications';

function makeSettings(overrides: Partial<NotificationSettings> = {}): NotificationSettings {
    return {
        browser_notifications: false,
        sound_alerts: false,
        sound: 'default',
        volume: 70,
        daily_digest: true,
        daily_digest_at: '18:00',
        ...overrides,
    };
}

const NotificationConstructor = vi.fn();
const requestPermissionMock = vi.fn();

class FakeAudio {
    public volume = 1;
    public src: string;

    private resolution: 'fulfilled' | 'rejected' = 'fulfilled';

    static lastInstance: FakeAudio | null = null;

    static rejectNext = false;

    constructor(src: string) {
        this.src = src;
        FakeAudio.lastInstance = this;
        if (FakeAudio.rejectNext) {
            this.resolution = 'rejected';
            FakeAudio.rejectNext = false;
        }
    }

    play(): Promise<void> {
        return this.resolution === 'rejected' ? Promise.reject(new Error('NotAllowedError: user gesture missing')) : Promise.resolve();
    }
}

beforeEach(() => {
    NotificationConstructor.mockReset();
    requestPermissionMock.mockReset();
    FakeAudio.lastInstance = null;
    FakeAudio.rejectNext = false;

    // Wire a Notification stub so `new Notification(...)` records
    // the constructor args, and `.permission` / `.requestPermission`
    // can be controlled per-test.
    const stub = function Notification(this: unknown, title: string, options?: NotificationOptions) {
        NotificationConstructor(title, options);
    } as unknown as typeof window.Notification & {
        permission: Permission;
        requestPermission: () => Promise<Permission>;
    };
    type Permission = 'default' | 'granted' | 'denied';
    stub.permission = 'default';
    stub.requestPermission = requestPermissionMock;

    Object.defineProperty(window, 'Notification', {
        configurable: true,
        writable: true,
        value: stub,
    });

    Object.defineProperty(window, 'Audio', {
        configurable: true,
        writable: true,
        value: FakeAudio,
    });
});

afterEach(() => {
    // happy-dom keeps the property between tests; explicit delete
    // is the cleanest way to model "Notification API unavailable".
    Reflect.deleteProperty(window, 'Notification');
    Reflect.deleteProperty(window, 'Audio');
});

describe('useNotifications', () => {
    it('does not call Notification when browser_notifications is off', () => {
        (window.Notification as unknown as { permission: string }).permission = 'granted';
        const settings = ref(makeSettings({ browser_notifications: false }));

        const handle = useNotifications(settings);
        handle.notify('hi');

        expect(NotificationConstructor).not.toHaveBeenCalled();
    });

    it('does not call Notification when permission is not granted', () => {
        (window.Notification as unknown as { permission: string }).permission = 'denied';
        const settings = ref(makeSettings({ browser_notifications: true }));

        const handle = useNotifications(settings);
        handle.notify('hi');

        expect(NotificationConstructor).not.toHaveBeenCalled();
    });

    it('triggers Notification with correct title when allowed', () => {
        (window.Notification as unknown as { permission: string }).permission = 'granted';
        const settings = ref(makeSettings({ browser_notifications: true }));

        const handle = useNotifications(settings);
        handle.notify('Neue Reservierung', { body: 'Anna Müller, 4 Personen' });

        expect(NotificationConstructor).toHaveBeenCalledTimes(1);
        expect(NotificationConstructor).toHaveBeenCalledWith('Neue Reservierung', { body: 'Anna Müller, 4 Personen' });
    });

    it('returns denied permission when Notification API is undefined', () => {
        Reflect.deleteProperty(window, 'Notification');
        const settings = ref(makeSettings({ browser_notifications: true }));

        const handle = useNotifications(settings);

        expect(handle.permission.value).toBe('denied');
        // notify() must also no-op without throwing.
        handle.notify('hi');
        expect(NotificationConstructor).not.toHaveBeenCalled();
    });

    it('updates the permission ref when the user grants the request', async () => {
        (window.Notification as unknown as { permission: string }).permission = 'default';
        requestPermissionMock.mockResolvedValueOnce('granted');
        const settings = ref(makeSettings());

        const handle = useNotifications(settings);
        const result = await handle.requestPermission();

        expect(result).toBe('granted');
        expect(handle.permission.value).toBe('granted');
    });

    it('does not throw when audio.play rejects', async () => {
        FakeAudio.rejectNext = true;
        const settings = ref(makeSettings({ sound_alerts: true }));

        const handle = useNotifications(settings);

        expect(() => handle.playSound()).not.toThrow();
        // Flush microtasks so the swallowed rejection settles
        // before the test ends — without this, vitest's
        // unhandledRejection guard could still fire.
        await new Promise((resolve) => setTimeout(resolve, 0));
    });

    it('does not call Audio when sound_alerts is off', () => {
        const settings = ref(makeSettings({ sound_alerts: false }));

        const handle = useNotifications(settings);
        handle.playSound();

        expect(FakeAudio.lastInstance).toBeNull();
    });

    it('clamps volume to [0, 1]', () => {
        const settings = ref(makeSettings({ sound_alerts: true, volume: 150 }));

        useNotifications(settings).playSound();
        expect(FakeAudio.lastInstance?.volume).toBe(1);

        FakeAudio.lastInstance = null;
        settings.value = makeSettings({ sound_alerts: true, volume: -25 });
        useNotifications(settings).playSound();
        expect(FakeAudio.lastInstance?.volume).toBe(0);

        FakeAudio.lastInstance = null;
        settings.value = makeSettings({ sound_alerts: true, volume: 50 });
        useNotifications(settings).playSound();
        expect(FakeAudio.lastInstance?.volume).toBe(0.5);
    });

    it('uses the soundKey override when supplied', () => {
        const settings = ref(makeSettings({ sound_alerts: true, sound: 'default' }));

        useNotifications(settings).playSound('chime');

        expect(FakeAudio.lastInstance?.src).toBe('/sounds/chime.mp3');
    });

    /**
     * PRD-010 § Risiken & offene Fragen — "Browser-Block": when the browser
     * has no Notification API at all (older Safari, embedded webviews, some
     * test runners), the composable must stay completely silent — every
     * call has to no-op without throwing — so the digest email becomes the
     * only delivery channel for the operator. This is the consolidation
     * gate from issue #252.
     */
    it('falls back to digest only when Notification API is undefined', () => {
        // Wipe both sides of the browser API: notifications and audio
        // can be missing independently in real environments, but for the
        // "fallback to digest" path we model the worst case where the
        // tab has neither.
        Reflect.deleteProperty(window, 'Notification');
        Reflect.deleteProperty(window, 'Audio');

        const settings = ref(
            makeSettings({
                browser_notifications: true,
                sound_alerts: true,
                daily_digest: true, // digest stays the only channel
            }),
        );

        const handle = useNotifications(settings);

        // Permission is reported as denied — the settings page uses this
        // signal to disable the toggle and show the "blocked" hint.
        expect(handle.permission.value).toBe('denied');

        // Both side-effect functions must no-op silently. If either threw
        // a "ReferenceError: Notification is not defined" the dashboard
        // diff trigger would crash on the first poll cycle and the user
        // would lose every notification path *including* the polling
        // refresh that drives the row updates.
        expect(() => handle.notify('hi', { body: 'unused' })).not.toThrow();
        expect(() => handle.playSound()).not.toThrow();

        expect(NotificationConstructor).not.toHaveBeenCalled();
        // FakeAudio was deleted — `lastInstance` is whatever the previous
        // test left it as (null thanks to beforeEach), but the absence of
        // a constructor call is the assertion that matters.
        expect(FakeAudio.lastInstance).toBeNull();
    });
});

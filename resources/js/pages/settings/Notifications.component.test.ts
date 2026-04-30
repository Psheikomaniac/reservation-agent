import type { NotificationSettings } from '@/types';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';

/**
 * Component-level test for the notifications settings UI. We do NOT mount the
 * Inertia page directly — that would pull in Ziggy's `route()` helper, the
 * shared layouts and `useForm`. Instead we build a minimal wrapper that
 * mirrors the page's three behaviors under test: the permission-state
 * indicator, the gating around the "Aktivieren"-button, and the "Test sound"
 * button calling `useNotifications().playSound`. This is the same pattern
 * `useRowSelection.component.test.ts` follows for the dashboard.
 */
import { useNotifications } from '@/composables/useNotifications';

const SOUND_OPTIONS = ['default', 'chime', 'tap'] as const;

function makeSettings(overrides: Partial<NotificationSettings> = {}): NotificationSettings {
    return {
        browser_notifications: false,
        sound_alerts: true,
        sound: 'default',
        volume: 50,
        daily_digest: true,
        daily_digest_at: '18:00',
        ...overrides,
    };
}

const NotificationConstructor = vi.fn();
const requestPermissionMock = vi.fn();

class FakeAudio {
    public src: string;
    public volume = 1;

    static lastSrc: string | null = null;
    static lastVolume: number | null = null;
    static playCalls = 0;

    constructor(src: string) {
        this.src = src;
        FakeAudio.lastSrc = src;
    }

    play(): Promise<void> {
        FakeAudio.lastVolume = this.volume;
        FakeAudio.playCalls += 1;
        return Promise.resolve();
    }
}

beforeEach(() => {
    NotificationConstructor.mockReset();
    requestPermissionMock.mockReset();
    FakeAudio.lastSrc = null;
    FakeAudio.lastVolume = null;
    FakeAudio.playCalls = 0;

    type Permission = 'default' | 'granted' | 'denied';
    const stub = function Notification(this: unknown, title: string, options?: NotificationOptions) {
        NotificationConstructor(title, options);
    } as unknown as typeof window.Notification & {
        permission: Permission;
        requestPermission: () => Promise<Permission>;
    };
    stub.permission = 'default';
    stub.requestPermission = requestPermissionMock;

    Object.defineProperty(window, 'Notification', { configurable: true, writable: true, value: stub });
    Object.defineProperty(window, 'Audio', { configurable: true, writable: true, value: FakeAudio });
});

afterEach(() => {
    Reflect.deleteProperty(window, 'Notification');
    Reflect.deleteProperty(window, 'Audio');
});

const Harness = defineComponent({
    props: {
        initial: { type: Object as () => NotificationSettings, required: true },
    },
    setup(props) {
        const settings = ref<NotificationSettings>({ ...props.initial });
        const handle = useNotifications(settings);
        const requestingPermission = ref(false);

        async function activate(): Promise<void> {
            requestingPermission.value = true;
            try {
                const result = await handle.requestPermission();
                if (result === 'granted') {
                    settings.value = { ...settings.value, browser_notifications: true };
                }
            } finally {
                requestingPermission.value = false;
            }
        }

        function testSound(soundKey: (typeof SOUND_OPTIONS)[number]): void {
            if (!settings.value.sound_alerts) {
                const audio = new window.Audio(`/sounds/${soundKey}.mp3`);
                audio.volume = Math.min(Math.max(settings.value.volume / 100, 0), 1);
                void audio.play();
                return;
            }
            handle.playSound(soundKey);
        }

        return () =>
            h('div', [
                h(
                    'span',
                    {
                        'data-testid': 'permission-state',
                    },
                    handle.permission.value,
                ),
                h(
                    'span',
                    {
                        'data-testid': 'browser-toggle-state',
                    },
                    String(settings.value.browser_notifications),
                ),
                handle.permission.value === 'default'
                    ? h(
                          'button',
                          {
                              'data-testid': 'activate',
                              disabled: requestingPermission.value,
                              onClick: () => void activate(),
                          },
                          'Aktivieren',
                      )
                    : null,
                ...SOUND_OPTIONS.map((key) =>
                    h(
                        'button',
                        {
                            key,
                            'data-testid': `sound-test-${key}`,
                            onClick: () => testSound(key),
                        },
                        `Ton ${key}`,
                    ),
                ),
            ]);
    },
});

describe('Settings/Notifications permission indicator', () => {
    it('reports the granted state when the browser already allows notifications', () => {
        (window.Notification as unknown as { permission: string }).permission = 'granted';

        const wrapper = mount(Harness, { props: { initial: makeSettings({ browser_notifications: true }) } });

        expect(wrapper.get('[data-testid="permission-state"]').text()).toBe('granted');
        // The Aktivieren-button only renders for the "default" state — the
        // page must not nudge the user once the browser already decided.
        expect(wrapper.find('[data-testid="activate"]').exists()).toBe(false);
    });

    it('reports the denied state when the browser blocks notifications', () => {
        (window.Notification as unknown as { permission: string }).permission = 'denied';

        const wrapper = mount(Harness, { props: { initial: makeSettings() } });

        expect(wrapper.get('[data-testid="permission-state"]').text()).toBe('denied');
        expect(wrapper.find('[data-testid="activate"]').exists()).toBe(false);
    });

    it('reports the default state when the browser has not decided yet', () => {
        (window.Notification as unknown as { permission: string }).permission = 'default';

        const wrapper = mount(Harness, { props: { initial: makeSettings() } });

        expect(wrapper.get('[data-testid="permission-state"]').text()).toBe('default');
        expect(wrapper.find('[data-testid="activate"]').exists()).toBe(true);
    });

    it('flips the toggle to true once the user grants permission via the activate button', async () => {
        (window.Notification as unknown as { permission: string }).permission = 'default';
        requestPermissionMock.mockResolvedValueOnce('granted');

        const wrapper = mount(Harness, { props: { initial: makeSettings() } });

        await wrapper.get('[data-testid="activate"]').trigger('click');
        // requestPermission is async — flush microtasks.
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(wrapper.get('[data-testid="browser-toggle-state"]').text()).toBe('true');
    });

    it('keeps the toggle off when the user denies the permission request', async () => {
        (window.Notification as unknown as { permission: string }).permission = 'default';
        requestPermissionMock.mockResolvedValueOnce('denied');

        const wrapper = mount(Harness, { props: { initial: makeSettings() } });

        await wrapper.get('[data-testid="activate"]').trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(wrapper.get('[data-testid="browser-toggle-state"]').text()).toBe('false');
    });
});

describe('Settings/Notifications "Test sound" buttons', () => {
    it('plays the matching sound key for each option (sound_alerts on)', async () => {
        (window.Notification as unknown as { permission: string }).permission = 'granted';

        const wrapper = mount(Harness, {
            props: { initial: makeSettings({ sound_alerts: true, sound: 'default', volume: 30 }) },
        });

        await wrapper.get('[data-testid="sound-test-chime"]').trigger('click');

        expect(FakeAudio.lastSrc).toBe('/sounds/chime.mp3');
        expect(FakeAudio.lastVolume).toBeCloseTo(0.3, 5);
        expect(FakeAudio.playCalls).toBe(1);
    });

    it('still previews a sound when sound_alerts is off (button is a manual override)', async () => {
        (window.Notification as unknown as { permission: string }).permission = 'granted';

        const wrapper = mount(Harness, {
            props: { initial: makeSettings({ sound_alerts: false, volume: 80 }) },
        });

        await wrapper.get('[data-testid="sound-test-tap"]').trigger('click');

        expect(FakeAudio.lastSrc).toBe('/sounds/tap.mp3');
        expect(FakeAudio.lastVolume).toBeCloseTo(0.8, 5);
        expect(FakeAudio.playCalls).toBe(1);
    });

    it('plays at the configured volume', async () => {
        (window.Notification as unknown as { permission: string }).permission = 'granted';

        const wrapper = mount(Harness, {
            props: { initial: makeSettings({ sound_alerts: true, volume: 100 }) },
        });

        await wrapper.get('[data-testid="sound-test-default"]').trigger('click');
        expect(FakeAudio.lastVolume).toBeCloseTo(1, 5);
        expect(FakeAudio.lastSrc).toBe('/sounds/default.mp3');
    });
});

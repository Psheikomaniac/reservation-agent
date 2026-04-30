import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref, type Ref } from 'vue';
import { useCountdown } from './useCountdown';

/**
 * Pins the PRD-007 cancel-window countdown contract: ticks down once a
 * second from a deadline timestamp, clamps at zero, resets when the
 * deadline changes, and cleans up its interval on dispose.
 */
describe('useCountdown', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-04-30T12:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    function mountCountdown(deadline: Ref<string | null>) {
        const captured: { secondsLeft: Ref<number>; isExpired: Ref<boolean> } = {
            secondsLeft: ref(0),
            isExpired: ref(false),
        };

        const Component = defineComponent({
            setup() {
                const handle = useCountdown(deadline);
                captured.secondsLeft = handle.secondsLeft;
                captured.isExpired = handle.isExpired;
                return () => h('div');
            },
        });

        const wrapper = mount(Component);
        return { wrapper, captured };
    }

    it('starts at the difference between deadline and now in whole seconds', () => {
        const deadline = ref<string | null>('2026-04-30T12:00:45Z');
        const { captured } = mountCountdown(deadline);

        expect(captured.secondsLeft.value).toBe(45);
        expect(captured.isExpired.value).toBe(false);
    });

    it('ticks down once per second until it reaches zero', async () => {
        const deadline = ref<string | null>('2026-04-30T12:00:03Z');
        const { captured } = mountCountdown(deadline);

        expect(captured.secondsLeft.value).toBe(3);

        await vi.advanceTimersByTimeAsync(1_000);
        expect(captured.secondsLeft.value).toBe(2);

        await vi.advanceTimersByTimeAsync(1_000);
        expect(captured.secondsLeft.value).toBe(1);

        await vi.advanceTimersByTimeAsync(1_000);
        expect(captured.secondsLeft.value).toBe(0);
        expect(captured.isExpired.value).toBe(true);
    });

    it('clamps to zero when the deadline is already in the past', () => {
        const deadline = ref<string | null>('2026-04-30T11:59:00Z');
        const { captured } = mountCountdown(deadline);

        expect(captured.secondsLeft.value).toBe(0);
        expect(captured.isExpired.value).toBe(true);
    });

    it('resets when the deadline ref changes', async () => {
        const deadline = ref<string | null>('2026-04-30T12:00:05Z');
        const { captured } = mountCountdown(deadline);

        expect(captured.secondsLeft.value).toBe(5);

        deadline.value = '2026-04-30T12:00:42Z';
        await vi.advanceTimersByTimeAsync(0);

        expect(captured.secondsLeft.value).toBe(42);
    });

    it('returns zero when the deadline is null', () => {
        const deadline = ref<string | null>(null);
        const { captured } = mountCountdown(deadline);

        expect(captured.secondsLeft.value).toBe(0);
        expect(captured.isExpired.value).toBe(true);
    });

    it('stops the interval on unmount so background timers do not leak', async () => {
        const deadline = ref<string | null>('2026-04-30T12:00:10Z');
        const { wrapper, captured } = mountCountdown(deadline);

        expect(captured.secondsLeft.value).toBe(10);
        wrapper.unmount();

        await vi.advanceTimersByTimeAsync(5_000);
        expect(captured.secondsLeft.value).toBe(10); // Frozen at last value.
    });
});

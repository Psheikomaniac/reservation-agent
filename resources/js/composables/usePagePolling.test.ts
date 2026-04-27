import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref, type Ref } from 'vue';
import { usePagePolling } from './usePagePolling';

/**
 * Reference test that pins the dashboard polling contract: no requests fire
 * while `document.visibilityState === 'hidden'`, and polling resumes as soon
 * as the tab returns to `visible`. Originally specified in #55 / #157.
 */
describe('usePagePolling', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    function mountPoller(visibility: Ref<DocumentVisibilityState>, callback: () => void) {
        const Poller = defineComponent({
            setup() {
                usePagePolling(callback, 1_000, { visibility });
                return () => h('div');
            },
        });
        return mount(Poller);
    }

    it('starts polling on mount when the tab is already visible', async () => {
        const callback = vi.fn();
        const visibility = ref<DocumentVisibilityState>('visible');

        mountPoller(visibility, callback);

        await vi.advanceTimersByTimeAsync(2_500);

        expect(callback).toHaveBeenCalledTimes(2);
    });

    it('does not poll while the tab is hidden', async () => {
        const callback = vi.fn();
        const visibility = ref<DocumentVisibilityState>('hidden');

        mountPoller(visibility, callback);

        await vi.advanceTimersByTimeAsync(5_000);

        expect(callback).not.toHaveBeenCalled();
    });

    it('pauses when visibility flips to hidden and resumes on visible', async () => {
        const callback = vi.fn();
        const visibility = ref<DocumentVisibilityState>('visible');

        mountPoller(visibility, callback);

        await vi.advanceTimersByTimeAsync(1_000);
        expect(callback).toHaveBeenCalledTimes(1);

        visibility.value = 'hidden';
        await vi.advanceTimersByTimeAsync(5_000);
        expect(callback).toHaveBeenCalledTimes(1);

        visibility.value = 'visible';
        await vi.advanceTimersByTimeAsync(1_000);
        expect(callback).toHaveBeenCalledTimes(2);
    });

    it('does not start a backlog of calls when the tab returns from a long hidden period', async () => {
        const callback = vi.fn();
        const visibility = ref<DocumentVisibilityState>('hidden');

        mountPoller(visibility, callback);

        await vi.advanceTimersByTimeAsync(60_000);
        expect(callback).not.toHaveBeenCalled();

        visibility.value = 'visible';
        await vi.advanceTimersByTimeAsync(1_000);
        expect(callback).toHaveBeenCalledTimes(1);
    });
});

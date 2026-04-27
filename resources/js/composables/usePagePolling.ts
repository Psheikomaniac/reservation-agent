import { useDocumentVisibility, useIntervalFn } from '@vueuse/core';
import { watch, type Ref } from 'vue';

type DocumentVisibility = ReturnType<typeof useDocumentVisibility>;

export interface UsePagePollingOptions {
    /**
     * Inject a controlled visibility ref instead of binding to
     * `document.visibilityState`. Used by the unit tests to drive the
     * pause/resume behaviour without mutating the real document.
     */
    visibility?: DocumentVisibility | Ref<DocumentVisibilityState>;
}

export interface UsePagePollingHandle {
    pause: () => void;
    resume: () => void;
}

/**
 * Calls `callback` every `intervalMs` milliseconds while the page is visible.
 *
 * Pauses on `document.visibilityState === 'hidden'` and resumes when the tab
 * is foregrounded again, so a backgrounded dashboard does not hammer the
 * server with polls nobody is reading.
 *
 * The visibility source can be overridden for tests via `options.visibility`.
 */
export function usePagePolling(callback: () => void, intervalMs: number, options: UsePagePollingOptions = {}): UsePagePollingHandle {
    const visibility = options.visibility ?? useDocumentVisibility();

    const { pause, resume } = useIntervalFn(callback, intervalMs, {
        immediate: false,
        immediateCallback: false,
    });

    if (visibility.value === 'visible') {
        resume();
    }

    watch(visibility, (state) => {
        if (state === 'visible') {
            resume();
        } else {
            pause();
        }
    });

    return { pause, resume };
}

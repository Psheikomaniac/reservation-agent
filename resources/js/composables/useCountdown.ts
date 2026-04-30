import { computed, onScopeDispose, ref, watchEffect, type Ref } from 'vue';

export interface UseCountdownOptions {
    /**
     * Provide a deterministic clock for tests (must return the current
     * timestamp in milliseconds since the epoch). Falls back to
     * `Date.now` in production.
     */
    now?: () => number;
}

export interface UseCountdownHandle {
    /**
     * Remaining seconds, clamped to zero. Reactive: it ticks down every
     * second while the deadline is in the future.
     */
    secondsLeft: Ref<number>;

    /**
     * `true` once the countdown reaches zero. Stays `true` afterwards.
     */
    isExpired: Ref<boolean>;
}

/**
 * Tick-down composable for the PRD-007 cancel-window banner. Pass a
 * reactive ISO-8601 timestamp; `secondsLeft` updates once a second and
 * clamps at zero so callers can render `…in {seconds}s` without doing
 * the math themselves. When the deadline changes (the operator picks a
 * different reply in the drawer), the timer resets cleanly.
 *
 * Cleans up its interval on scope dispose.
 */
export function useCountdown(deadline: Ref<string | Date | null | undefined>, options: UseCountdownOptions = {}): UseCountdownHandle {
    const now = options.now ?? Date.now;
    const secondsLeft = ref(0);
    let timer: ReturnType<typeof setInterval> | null = null;

    const stop = () => {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    };

    const tick = (deadlineMs: number) => {
        const remaining = Math.max(0, Math.ceil((deadlineMs - now()) / 1000));
        secondsLeft.value = remaining;
        if (remaining === 0) {
            stop();
        }
    };

    watchEffect(() => {
        stop();

        const value = deadline.value;
        if (value === null || value === undefined) {
            secondsLeft.value = 0;
            return;
        }

        const deadlineMs = value instanceof Date ? value.getTime() : new Date(value).getTime();
        if (Number.isNaN(deadlineMs)) {
            secondsLeft.value = 0;
            return;
        }

        tick(deadlineMs);
        if (secondsLeft.value > 0) {
            timer = setInterval(() => tick(deadlineMs), 1000);
        }
    });

    onScopeDispose(stop);

    const isExpired = computed(() => secondsLeft.value === 0);

    return { secondsLeft, isExpired };
}

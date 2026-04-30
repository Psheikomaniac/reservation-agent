import type { UseNotificationsHandle } from '@/composables/useNotifications';
import type { DashboardFilters } from '@/types';
import { watch, type Ref } from 'vue';

/**
 * PRD-010 § Trigger im Dashboard.
 *
 * Watches a paginated list of reservation rows for *new* IDs that
 * appeared between two server reloads (the dashboard polls every 30 s).
 * Whenever a poll surfaces ids the previous snapshot did not contain
 * AND the filter snapshot is unchanged, the operator gets a browser
 * notification + the configured sound.
 *
 * Two intentional silences:
 *
 * 1. **First load.** A `hasBaseline` flag — *not* `previousIds.size === 0` —
 *    delineates the very first watcher invocation from any later one.
 *    The size-based check would silence the first reservation that
 *    ever arrives on a dashboard that started empty, exactly the
 *    scenario the operator most needs to hear about.
 *
 * 2. **Filter change.** A status/source/date filter switch produces
 *    a different ID set, but those rows are not "new" — they were
 *    already in the database. We compare a stable JSON snapshot of
 *    the filter object between calls; on change, we refresh the
 *    `previousIds` snapshot and skip the diff evaluation entirely.
 *
 * The `tag` field on the notification is fixed (`'reservation-agent-new-request'`)
 * so a slow operator doesn't accumulate a stack of system toasts —
 * the OS replaces the previous one.
 */
export function useReservationDiffTrigger(
    rowIds: Ref<readonly number[]>,
    filters: Ref<DashboardFilters>,
    notifications: UseNotificationsHandle,
): void {
    const previousIds = new Set<number>();
    let previousFilterKey: string | null = null;
    // Explicit baseline flag instead of `previousIds.size === 0`.
    // The dashboard regularly starts empty (a brand-new restaurant
    // or an aggressive filter), and `Set.size` would lie about
    // "first load" again on every subsequent empty-or-near-empty
    // poll — silencing the first reservation that ever arrives.
    let hasBaseline = false;

    watch(
        // Watch a stable string of ids so polling that returns the
        // *same* ids doesn't re-execute the diff. Filter-only
        // changes that produce identical id sets fall through to the
        // next genuine row change, which still detects the stale
        // filter snapshot and resets the baseline silently.
        () => rowIds.value.join(','),
        () => {
            const currentIds = new Set(rowIds.value);
            const filterKey = serializeFilters(filters.value);

            // Treat filter changes as a snapshot reset, never as a
            // notification trigger. The `previousFilterKey === null`
            // guard makes the very first poll behave the same way:
            // populate the baseline silently.
            const filtersChanged = previousFilterKey !== null && previousFilterKey !== filterKey;

            if (hasBaseline && !filtersChanged) {
                const newIds: number[] = [];
                for (const id of currentIds) {
                    if (!previousIds.has(id)) {
                        newIds.push(id);
                    }
                }
                if (newIds.length > 0) {
                    notifications.notify('Neue Reservierungsanfrage', {
                        body: bodyForCount(newIds.length),
                        tag: 'reservation-agent-new-request',
                    });
                    notifications.playSound();
                }
            }

            previousIds.clear();
            for (const id of currentIds) {
                previousIds.add(id);
            }
            previousFilterKey = filterKey;
            hasBaseline = true;
        },
        { immediate: true },
    );
}

function bodyForCount(count: number): string {
    if (count === 1) {
        return '1 neue Anfrage – jetzt anschauen';
    }
    return `${count} neue Anfragen – jetzt anschauen`;
}

function serializeFilters(filters: DashboardFilters): string {
    // JSON.stringify is order-sensitive; we explicitly sort the keys
    // so a server response that re-orders them between polls (Laravel
    // does, depending on how the DTO is built) doesn't read as a
    // filter change and silently swallow a real diff trigger.
    const sorted: Record<string, unknown> = {};
    for (const key of Object.keys(filters).sort()) {
        const value = (filters as Record<string, unknown>)[key];
        if (Array.isArray(value)) {
            sorted[key] = [...value].sort();
        } else {
            sorted[key] = value;
        }
    }
    return JSON.stringify(sorted);
}

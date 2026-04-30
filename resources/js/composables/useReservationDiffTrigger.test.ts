import type { UseNotificationsHandle } from '@/composables/useNotifications';
import type { DashboardFilters } from '@/types';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import { useReservationDiffTrigger } from './useReservationDiffTrigger';

function fakeHandle(): UseNotificationsHandle & { _notify: ReturnType<typeof vi.fn>; _play: ReturnType<typeof vi.fn> } {
    const notify = vi.fn();
    const play = vi.fn();
    return {
        permission: ref('granted') as unknown as UseNotificationsHandle['permission'],
        requestPermission: vi.fn(),
        notify: notify as UseNotificationsHandle['notify'],
        playSound: play as UseNotificationsHandle['playSound'],
        _notify: notify,
        _play: play,
    } as UseNotificationsHandle & { _notify: ReturnType<typeof vi.fn>; _play: ReturnType<typeof vi.fn> };
}

describe('useReservationDiffTrigger', () => {
    let rowIds: ReturnType<typeof ref<readonly number[]>>;
    let filters: ReturnType<typeof ref<DashboardFilters>>;
    let handle: ReturnType<typeof fakeHandle>;

    beforeEach(() => {
        rowIds = ref<readonly number[]>([]);
        filters = ref<DashboardFilters>({});
        handle = fakeHandle();
    });

    it('does not notify on initial load', async () => {
        rowIds.value = [10, 11, 12];

        useReservationDiffTrigger(
            rowIds as unknown as Parameters<typeof useReservationDiffTrigger>[0],
            filters as unknown as Parameters<typeof useReservationDiffTrigger>[1],
            handle,
        );
        await nextTick();

        expect(handle._notify).not.toHaveBeenCalled();
        expect(handle._play).not.toHaveBeenCalled();
    });

    it('notifies when polling adds new request ids', async () => {
        rowIds.value = [10, 11];
        useReservationDiffTrigger(
            rowIds as unknown as Parameters<typeof useReservationDiffTrigger>[0],
            filters as unknown as Parameters<typeof useReservationDiffTrigger>[1],
            handle,
        );
        await nextTick();
        expect(handle._notify).not.toHaveBeenCalled();

        // Simulate the next poll cycle returning a freshly inserted row.
        rowIds.value = [10, 11, 42];
        await nextTick();

        expect(handle._notify).toHaveBeenCalledTimes(1);
        expect(handle._notify).toHaveBeenCalledWith('Neue Reservierungsanfrage', {
            body: '1 neue Anfrage – jetzt anschauen',
            tag: 'reservation-agent-new-request',
        });
        expect(handle._play).toHaveBeenCalledTimes(1);
    });

    it('uses the plural body when more than one new id arrives', async () => {
        rowIds.value = [1];
        useReservationDiffTrigger(
            rowIds as unknown as Parameters<typeof useReservationDiffTrigger>[0],
            filters as unknown as Parameters<typeof useReservationDiffTrigger>[1],
            handle,
        );
        await nextTick();

        rowIds.value = [1, 2, 3, 4];
        await nextTick();

        expect(handle._notify).toHaveBeenCalledWith('Neue Reservierungsanfrage', {
            body: '3 neue Anfragen – jetzt anschauen',
            tag: 'reservation-agent-new-request',
        });
    });

    it('does not notify on filter change even when the id set differs', async () => {
        rowIds.value = [10, 11];
        useReservationDiffTrigger(
            rowIds as unknown as Parameters<typeof useReservationDiffTrigger>[0],
            filters as unknown as Parameters<typeof useReservationDiffTrigger>[1],
            handle,
        );
        await nextTick();

        // Operator switches a status chip — server returns a new id set
        // (rows that already existed but were filtered out before).
        filters.value = { status: ['confirmed'] };
        rowIds.value = [50, 51, 52];
        await nextTick();

        expect(handle._notify).not.toHaveBeenCalled();
        expect(handle._play).not.toHaveBeenCalled();

        // After the filter is stable again, a polling cycle that adds a
        // new id should notify normally.
        rowIds.value = [50, 51, 52, 99];
        await nextTick();

        expect(handle._notify).toHaveBeenCalledTimes(1);
        expect(handle._notify).toHaveBeenCalledWith('Neue Reservierungsanfrage', {
            body: '1 neue Anfrage – jetzt anschauen',
            tag: 'reservation-agent-new-request',
        });
    });

    it('notifies when the very first reservation arrives on an empty dashboard', async () => {
        // Regression: an earlier `previousIds.size === 0` check treated
        // the second watcher fire as another "first load" if the page
        // started empty, swallowing the most important alert.
        rowIds.value = [];
        useReservationDiffTrigger(
            rowIds as unknown as Parameters<typeof useReservationDiffTrigger>[0],
            filters as unknown as Parameters<typeof useReservationDiffTrigger>[1],
            handle,
        );
        await nextTick();

        rowIds.value = [42];
        await nextTick();

        expect(handle._notify).toHaveBeenCalledTimes(1);
        expect(handle._notify).toHaveBeenCalledWith('Neue Reservierungsanfrage', {
            body: '1 neue Anfrage – jetzt anschauen',
            tag: 'reservation-agent-new-request',
        });
        expect(handle._play).toHaveBeenCalledTimes(1);
    });

    it('does not notify when the same ids are polled again (no diff)', async () => {
        rowIds.value = [1, 2, 3];
        useReservationDiffTrigger(
            rowIds as unknown as Parameters<typeof useReservationDiffTrigger>[0],
            filters as unknown as Parameters<typeof useReservationDiffTrigger>[1],
            handle,
        );
        await nextTick();

        // Polling cycle with identical ids — common case in steady state.
        rowIds.value = [1, 2, 3];
        await nextTick();

        expect(handle._notify).not.toHaveBeenCalled();
        expect(handle._play).not.toHaveBeenCalled();
    });

    it('treats filter array reorder as no change (stable serializer)', async () => {
        rowIds.value = [10];
        filters.value = { status: ['new', 'confirmed'] };
        useReservationDiffTrigger(
            rowIds as unknown as Parameters<typeof useReservationDiffTrigger>[0],
            filters as unknown as Parameters<typeof useReservationDiffTrigger>[1],
            handle,
        );
        await nextTick();

        // Reorder the array — semantically identical filter snapshot.
        filters.value = { status: ['confirmed', 'new'] };
        rowIds.value = [10, 20];
        await nextTick();

        // The new id (20) must still trigger a notification because the
        // filter snapshot did NOT change in any meaningful way.
        expect(handle._notify).toHaveBeenCalledTimes(1);
    });

    it('notifies via the composable guards (suppression handled inside notify())', async () => {
        // The composable always calls notify(); the guard against
        // disabled browser_notifications lives inside the
        // useNotifications handle. We verify the boundary here by
        // simulating a handle whose `notify` is a no-op stub — the
        // diff trigger calls it regardless, and the suppression test
        // sits at the useNotifications layer.
        rowIds.value = [1];
        useReservationDiffTrigger(
            rowIds as unknown as Parameters<typeof useReservationDiffTrigger>[0],
            filters as unknown as Parameters<typeof useReservationDiffTrigger>[1],
            handle,
        );
        await nextTick();

        rowIds.value = [1, 2];
        await nextTick();

        // Both calls fire — the composable doesn't gate; the
        // useNotifications handle does.
        expect(handle._notify).toHaveBeenCalledTimes(1);
        expect(handle._play).toHaveBeenCalledTimes(1);
    });
});

import { computed, ref, type ComputedRef } from 'vue';

export type RowSelectionState = 'none' | 'some' | 'all';

export interface UseRowSelection {
    selectedIds: ComputedRef<ReadonlySet<number>>;
    count: ComputedRef<number>;
    isSelected: (id: number) => boolean;
    toggle: (id: number) => void;
    setSelected: (id: number, selected: boolean) => void;
    visibleState: (visibleIds: readonly number[]) => RowSelectionState;
    isVisibleAllSelected: (visibleIds: readonly number[]) => boolean;
    isVisibleIndeterminate: (visibleIds: readonly number[]) => boolean;
    toggleAllVisible: (visibleIds: readonly number[]) => void;
    retainVisible: (visibleIds: readonly number[]) => void;
    clear: () => void;
}

/**
 * Tracks bulk-selection of reservation rows in a `Set<number>` keyed by id.
 *
 * The set is component-local and survives pagination because we key by id
 * rather than visible index — that's the foundation #84 (polling-reload
 * persistence) builds on.
 *
 * Header-checkbox toggle uses the "select-first" convention (Gmail/GitHub):
 * a click while in any non-fully-selected state selects all visible rows;
 * a click while fully selected clears them. That keeps a single intuition
 * for users moving between mixed and clean states.
 */
export function useRowSelection(): UseRowSelection {
    const internal = ref<Set<number>>(new Set());

    const selectedIds = computed<ReadonlySet<number>>(() => internal.value);
    const count = computed(() => internal.value.size);

    function isSelected(id: number): boolean {
        return internal.value.has(id);
    }

    function setSelected(id: number, selected: boolean): void {
        const next = new Set(internal.value);

        if (selected) {
            next.add(id);
        } else {
            next.delete(id);
        }

        internal.value = next;
    }

    function toggle(id: number): void {
        setSelected(id, !internal.value.has(id));
    }

    function visibleState(visibleIds: readonly number[]): RowSelectionState {
        if (visibleIds.length === 0) {
            return 'none';
        }

        let selectedCount = 0;
        for (const id of visibleIds) {
            if (internal.value.has(id)) {
                selectedCount++;
            }
        }

        if (selectedCount === 0) {
            return 'none';
        }

        return selectedCount === visibleIds.length ? 'all' : 'some';
    }

    function isVisibleAllSelected(visibleIds: readonly number[]): boolean {
        return visibleState(visibleIds) === 'all';
    }

    function isVisibleIndeterminate(visibleIds: readonly number[]): boolean {
        return visibleState(visibleIds) === 'some';
    }

    function toggleAllVisible(visibleIds: readonly number[]): void {
        const next = new Set(internal.value);
        const allSelected = visibleState(visibleIds) === 'all';

        for (const id of visibleIds) {
            if (allSelected) {
                next.delete(id);
            } else {
                next.add(id);
            }
        }

        internal.value = next;
    }

    /**
     * Drops every selected id that is not in `visibleIds`.
     *
     * Call this after the dashboard re-renders the row set (polling reload,
     * filter change, pagination) so the bulk action operates on rows the
     * operator can still see. Without it, ids that left the view (e.g. a row
     * that was just marked declined and now no longer matches the active
     * status filter) would silently linger in the selection.
     */
    function retainVisible(visibleIds: readonly number[]): void {
        if (internal.value.size === 0) {
            return;
        }

        const next = new Set<number>();
        for (const id of visibleIds) {
            if (internal.value.has(id)) {
                next.add(id);
            }
        }

        if (next.size === internal.value.size) {
            return;
        }

        internal.value = next;
    }

    function clear(): void {
        internal.value = new Set();
    }

    return {
        selectedIds,
        count,
        isSelected,
        toggle,
        setSelected,
        visibleState,
        isVisibleAllSelected,
        isVisibleIndeterminate,
        toggleAllVisible,
        retainVisible,
        clear,
    };
}

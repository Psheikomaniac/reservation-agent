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
        clear,
    };
}

import { describe, expect, it } from 'vitest';
import { useRowSelection } from './useRowSelection';

describe('useRowSelection', () => {
    it('starts empty', () => {
        const s = useRowSelection();

        expect(s.count.value).toBe(0);
        expect(s.selectedIds.value.size).toBe(0);
    });

    it('tracks individual selection by id', () => {
        const s = useRowSelection();

        s.toggle(1);
        s.toggle(42);

        expect(s.isSelected(1)).toBe(true);
        expect(s.isSelected(42)).toBe(true);
        expect(s.isSelected(7)).toBe(false);
        expect(s.count.value).toBe(2);
    });

    it('toggles an existing id off', () => {
        const s = useRowSelection();

        s.toggle(5);
        s.toggle(5);

        expect(s.isSelected(5)).toBe(false);
        expect(s.count.value).toBe(0);
    });

    it('reports visibleState as none when no visible rows are selected', () => {
        const s = useRowSelection();

        s.toggle(99);

        expect(s.visibleState([1, 2, 3])).toBe('none');
        expect(s.isVisibleAllSelected([1, 2, 3])).toBe(false);
        expect(s.isVisibleIndeterminate([1, 2, 3])).toBe(false);
    });

    it('reports visibleState as some when partially selected', () => {
        const s = useRowSelection();

        s.toggle(2);

        expect(s.visibleState([1, 2, 3])).toBe('some');
        expect(s.isVisibleIndeterminate([1, 2, 3])).toBe(true);
        expect(s.isVisibleAllSelected([1, 2, 3])).toBe(false);
    });

    it('reports visibleState as all when every visible row is selected', () => {
        const s = useRowSelection();

        s.toggle(1);
        s.toggle(2);
        s.toggle(3);

        expect(s.visibleState([1, 2, 3])).toBe('all');
        expect(s.isVisibleAllSelected([1, 2, 3])).toBe(true);
        expect(s.isVisibleIndeterminate([1, 2, 3])).toBe(false);
    });

    it('returns none for an empty visibleIds list', () => {
        const s = useRowSelection();

        expect(s.visibleState([])).toBe('none');
    });

    it('toggleAllVisible selects all when none are selected', () => {
        const s = useRowSelection();

        s.toggleAllVisible([1, 2, 3]);

        expect(s.isSelected(1)).toBe(true);
        expect(s.isSelected(2)).toBe(true);
        expect(s.isSelected(3)).toBe(true);
        expect(s.count.value).toBe(3);
    });

    it('toggleAllVisible selects the missing rows when some are already selected (select-first)', () => {
        const s = useRowSelection();

        s.toggle(2);
        s.toggleAllVisible([1, 2, 3]);

        expect(s.isSelected(1)).toBe(true);
        expect(s.isSelected(2)).toBe(true);
        expect(s.isSelected(3)).toBe(true);
    });

    it('toggleAllVisible clears the visible page when every visible row is already selected', () => {
        const s = useRowSelection();

        s.toggle(1);
        s.toggle(2);
        s.toggle(3);
        s.toggleAllVisible([1, 2, 3]);

        expect(s.count.value).toBe(0);
    });

    it('toggleAllVisible only touches ids on the current page (foundation for #84)', () => {
        const s = useRowSelection();

        // Simulate a previous selection from another page.
        s.toggle(99);

        s.toggleAllVisible([1, 2]);

        expect(s.isSelected(99)).toBe(true);
        expect(s.isSelected(1)).toBe(true);
        expect(s.isSelected(2)).toBe(true);
        expect(s.count.value).toBe(3);

        // Header-toggle on the current page must not blow away id 99.
        s.toggleAllVisible([1, 2]);

        expect(s.isSelected(99)).toBe(true);
        expect(s.isSelected(1)).toBe(false);
        expect(s.isSelected(2)).toBe(false);
        expect(s.count.value).toBe(1);
    });

    it('clear empties the entire set including off-page selections', () => {
        const s = useRowSelection();

        s.toggle(1);
        s.toggle(99);

        s.clear();

        expect(s.count.value).toBe(0);
        expect(s.isSelected(1)).toBe(false);
        expect(s.isSelected(99)).toBe(false);
    });

    it('setSelected is idempotent', () => {
        const s = useRowSelection();

        s.setSelected(7, true);
        s.setSelected(7, true);
        expect(s.count.value).toBe(1);

        s.setSelected(7, false);
        s.setSelected(7, false);
        expect(s.count.value).toBe(0);
    });

    describe('retainVisible (#84 polling-reload cleanup)', () => {
        it('drops ids that are no longer visible', () => {
            const s = useRowSelection();

            s.toggle(1);
            s.toggle(2);
            s.toggle(99);

            s.retainVisible([1, 2, 5]);

            expect(s.isSelected(1)).toBe(true);
            expect(s.isSelected(2)).toBe(true);
            expect(s.isSelected(99)).toBe(false);
            expect(s.count.value).toBe(2);
        });

        it('keeps every id when all selections remain visible', () => {
            const s = useRowSelection();

            s.toggle(1);
            s.toggle(2);

            s.retainVisible([1, 2, 3]);

            expect(s.count.value).toBe(2);
            expect(s.isSelected(1)).toBe(true);
            expect(s.isSelected(2)).toBe(true);
        });

        it('clears the selection when no selected id is visible anymore', () => {
            const s = useRowSelection();

            s.toggle(7);
            s.toggle(8);

            s.retainVisible([10, 11]);

            expect(s.count.value).toBe(0);
        });

        it('is a no-op on an empty selection (does not allocate)', () => {
            const s = useRowSelection();
            const before = s.selectedIds.value;

            s.retainVisible([1, 2, 3]);

            expect(s.count.value).toBe(0);
            // Same Set reference — proves the early return short-circuited.
            expect(s.selectedIds.value).toBe(before);
        });
    });
});

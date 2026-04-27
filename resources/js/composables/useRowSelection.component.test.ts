import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { computed, defineComponent, h, watch } from 'vue';
import { useRowSelection } from './useRowSelection';

/**
 * Component-level integration test: mounts a minimal table that wires up
 * `useRowSelection` exactly the way Dashboard.vue does (header checkbox +
 * per-row checkboxes + counter, plus a watcher that calls `retainVisible`
 * after every row-set change to model partial-reload cleanup). Verifies the
 * user-visible behavior — clicks flow through the composable and update the
 * rendered state — instead of pulling in the full Dashboard with
 * Inertia/Ziggy/Layout dependencies.
 */
const TestTable = defineComponent({
    props: {
        rows: { type: Array as () => Array<{ id: number; name: string }>, required: true },
    },
    setup(props) {
        const selection = useRowSelection();
        const visibleIds = computed(() => props.rows.map((r) => r.id));
        const headerState = computed(() => selection.visibleState(visibleIds.value));

        // Mirrors Dashboard.vue: every time the row set changes (polling
        // reload, filter, pagination), drop off-view selections so the bulk
        // action operates on rows the operator can still see (#84).
        watch(
            () => props.rows.map((r) => r.id).join(','),
            () => selection.retainVisible(visibleIds.value),
        );

        return () =>
            h('div', [
                h('span', { 'data-testid': 'count' }, String(selection.count.value)),
                h('span', { 'data-testid': 'header-state' }, headerState.value),
                h('table', [
                    h('thead', [
                        h('tr', [
                            h('th', [
                                h('input', {
                                    type: 'checkbox',
                                    'data-testid': 'header',
                                    checked: selection.isVisibleAllSelected(visibleIds.value),
                                    onChange: () => selection.toggleAllVisible(visibleIds.value),
                                }),
                            ]),
                        ]),
                    ]),
                    h(
                        'tbody',
                        props.rows.map((row) =>
                            h('tr', { key: row.id }, [
                                h('td', [
                                    h('input', {
                                        type: 'checkbox',
                                        'data-testid': `row-${row.id}`,
                                        checked: selection.isSelected(row.id),
                                        onChange: () => selection.toggle(row.id),
                                    }),
                                ]),
                            ]),
                        ),
                    ),
                ]),
            ]);
    },
});

const sampleRows = [
    { id: 10, name: 'Anna' },
    { id: 11, name: 'Bob' },
    { id: 12, name: 'Carla' },
];

describe('row selection wiring', () => {
    it('toggles a single row and updates the visible counter', async () => {
        const wrapper = mount(TestTable, { props: { rows: sampleRows } });

        await wrapper.get('[data-testid="row-11"]').setValue(true);

        expect(wrapper.get('[data-testid="count"]').text()).toBe('1');
        expect(wrapper.get('[data-testid="header-state"]').text()).toBe('some');
    });

    it('header click selects every visible row', async () => {
        const wrapper = mount(TestTable, { props: { rows: sampleRows } });

        await wrapper.get('[data-testid="header"]').trigger('change');

        expect(wrapper.get('[data-testid="count"]').text()).toBe('3');
        expect(wrapper.get('[data-testid="header-state"]').text()).toBe('all');
        expect((wrapper.get('[data-testid="row-10"]').element as HTMLInputElement).checked).toBe(true);
        expect((wrapper.get('[data-testid="row-11"]').element as HTMLInputElement).checked).toBe(true);
        expect((wrapper.get('[data-testid="row-12"]').element as HTMLInputElement).checked).toBe(true);
    });

    it('header click in a partially-selected state fills the page (select-first)', async () => {
        const wrapper = mount(TestTable, { props: { rows: sampleRows } });

        await wrapper.get('[data-testid="row-11"]').trigger('change');
        expect(wrapper.get('[data-testid="header-state"]').text()).toBe('some');

        await wrapper.get('[data-testid="header"]').trigger('change');

        expect(wrapper.get('[data-testid="count"]').text()).toBe('3');
        expect(wrapper.get('[data-testid="header-state"]').text()).toBe('all');
    });

    it('header click while fully selected clears the page', async () => {
        const wrapper = mount(TestTable, { props: { rows: sampleRows } });

        await wrapper.get('[data-testid="header"]').trigger('change');
        expect(wrapper.get('[data-testid="header-state"]').text()).toBe('all');

        await wrapper.get('[data-testid="header"]').trigger('change');

        expect(wrapper.get('[data-testid="count"]').text()).toBe('0');
        expect(wrapper.get('[data-testid="header-state"]').text()).toBe('none');
    });

    describe('reload cleanup (#84)', () => {
        it('preserves selections of rows that survive a partial reload', async () => {
            const wrapper = mount(TestTable, { props: { rows: sampleRows } });

            await wrapper.get('[data-testid="row-10"]').trigger('change');
            await wrapper.get('[data-testid="row-11"]').trigger('change');
            expect(wrapper.get('[data-testid="count"]').text()).toBe('2');

            // Simulate a polling reload that returns the same rows.
            await wrapper.setProps({ rows: [...sampleRows] });

            expect(wrapper.get('[data-testid="count"]').text()).toBe('2');
            expect((wrapper.get('[data-testid="row-10"]').element as HTMLInputElement).checked).toBe(true);
            expect((wrapper.get('[data-testid="row-11"]').element as HTMLInputElement).checked).toBe(true);
        });

        it('drops a selected row that left the page after a partial reload', async () => {
            const wrapper = mount(TestTable, { props: { rows: sampleRows } });

            // Select all three.
            await wrapper.get('[data-testid="header"]').trigger('change');
            expect(wrapper.get('[data-testid="count"]').text()).toBe('3');

            // Simulate reload after id 11 was marked declined and dropped from
            // the active filter — only ids 10 and 12 remain visible.
            await wrapper.setProps({
                rows: [
                    { id: 10, name: 'Anna' },
                    { id: 12, name: 'Carla' },
                ],
            });

            expect(wrapper.get('[data-testid="count"]').text()).toBe('2');
            expect((wrapper.get('[data-testid="row-10"]').element as HTMLInputElement).checked).toBe(true);
            expect((wrapper.get('[data-testid="row-12"]').element as HTMLInputElement).checked).toBe(true);
        });

        it('clears selection when the user navigates to a page with no overlapping ids', async () => {
            const wrapper = mount(TestTable, { props: { rows: sampleRows } });

            await wrapper.get('[data-testid="row-11"]').trigger('change');
            expect(wrapper.get('[data-testid="count"]').text()).toBe('1');

            // Page 2 — disjoint id space.
            await wrapper.setProps({
                rows: [
                    { id: 20, name: 'Dora' },
                    { id: 21, name: 'Eli' },
                ],
            });

            expect(wrapper.get('[data-testid="count"]').text()).toBe('0');
        });
    });
});

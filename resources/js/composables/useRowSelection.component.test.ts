import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { computed, defineComponent, h } from 'vue';
import { useRowSelection } from './useRowSelection';

/**
 * Component-level integration test: mounts a minimal table that wires up
 * `useRowSelection` exactly the way Dashboard.vue does (header checkbox +
 * per-row checkboxes + counter). Verifies the user-visible behavior — clicks
 * flow through the composable and update the rendered state — instead of
 * pulling in the full Dashboard with Inertia/Ziggy/Layout dependencies.
 */
const TestTable = defineComponent({
    props: {
        rows: { type: Array as () => Array<{ id: number; name: string }>, required: true },
    },
    setup(props) {
        const selection = useRowSelection();
        const visibleIds = computed(() => props.rows.map((r) => r.id));
        const headerState = computed(() => selection.visibleState(visibleIds.value));

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
});

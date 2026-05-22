import TableAvailabilityGrid from '@/components/tables/TableAvailabilityGrid.vue';
import type { DayAvailability, TableModel } from '@/types';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Tables from './Tables.vue';

const { postSpy, patchSpy, deleteSpy, getSpy, reloadSpy } = vi.hoisted(() => ({
    postSpy: vi.fn(),
    patchSpy: vi.fn(),
    deleteSpy: vi.fn(),
    getSpy: vi.fn(),
    reloadSpy: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    router: {
        post: (...args: unknown[]) => postSpy(...args),
        patch: (...args: unknown[]) => patchSpy(...args),
        delete: (...args: unknown[]) => deleteSpy(...args),
        get: (...args: unknown[]) => getSpy(...args),
        reload: (...args: unknown[]) => reloadSpy(...args),
    },
}));

vi.mock('@/layouts/AppLayout.vue', () => ({
    default: { name: 'AppLayout', template: '<div data-testid="layout"><slot /></div>' },
}));

// The Sheet drawer renders through a radix DialogPortal (teleport) in the real
// app; stubbing the chrome to passthrough divs keeps the form fields inline and
// queryable in the test. Checkbox is radix-driven too — a minimal stub keeps
// the v-model:checked contract testable without the indicator internals.
const sheetStubs = {
    Sheet: { template: '<div><slot /></div>' },
    SheetContent: { template: '<div v-bind="$attrs"><slot /></div>' },
    SheetHeader: { template: '<div><slot /></div>' },
    SheetTitle: { template: '<div><slot /></div>' },
    SheetFooter: { template: '<div><slot /></div>' },
    Checkbox: {
        props: ['checked'],
        emits: ['update:checked'],
        template:
            '<button type="button" role="checkbox" :aria-checked="String(checked)" v-bind="$attrs" @click="$emit(\'update:checked\', !checked)" />',
    },
};

function makeTable(overrides: Partial<TableModel> = {}): TableModel {
    return {
        id: 1,
        label: 'Tisch 1',
        seats: 4,
        room_tag: 'Innen',
        sort_order: 1,
        active: true,
        combinable_with: [],
        created_at: '2026-05-01T10:00:00+00:00',
        ...overrides,
    };
}

function makeAvailability(overrides: Partial<DayAvailability> = {}): DayAvailability {
    return {
        date: '2026-06-15',
        total_capacity: 12,
        reserved_seats: 4,
        slots: [
            { time: '17:00', state: 'free', suggested_table_id: 1 },
            { time: '17:30', state: 'tight', suggested_table_id: 1 },
            { time: '18:00', state: 'full', suggested_table_id: null },
        ],
        ...overrides,
    };
}

function mountTables(tables: TableModel[]) {
    return mount(Tables, {
        props: { tables: { data: tables } },
        global: { stubs: sheetStubs },
    });
}

beforeEach(() => {
    postSpy.mockReset();
    patchSpy.mockReset();
    deleteSpy.mockReset();
    getSpy.mockReset();
    reloadSpy.mockReset();

    vi.stubGlobal('route', (name: string, params?: Record<string, unknown>) => (params ? `${name}/${Object.values(params).join('/')}` : name));
});

describe('Tables.vue master-data tab', () => {
    it('renders one row per table and resolves combinable labels', () => {
        const wrapper = mountTables([
            makeTable({ id: 1, label: 'Tisch 1', seats: 4, room_tag: 'Innen', combinable_with: [2] }),
            makeTable({ id: 2, label: 'Tisch 2', seats: 6, room_tag: 'Terrasse' }),
        ]);

        const tableRows = wrapper.findAll('[data-testid="table-row"]');
        expect(tableRows).toHaveLength(2);
        expect(wrapper.text()).toContain('Tisch 1');
        expect(wrapper.text()).toContain('Terrasse');
        // Row 1 combines with table 2, so its label is resolved to "Tisch 2".
        expect(tableRows[0].text()).toContain('Tisch 2');
    });

    it('shows the empty state when there are no tables', () => {
        const wrapper = mountTables([]);

        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid="table-row"]')).toHaveLength(0);
    });

    it('navigates to the availability endpoint when the Belegung tab is clicked', async () => {
        const wrapper = mountTables([makeTable()]);

        expect(wrapper.find('[data-testid="tab-panel-stammdaten"]').exists()).toBe(true);

        await wrapper.find('[data-testid="tab-availability"]').trigger('click');

        expect(getSpy).toHaveBeenCalledTimes(1);
        expect(getSpy.mock.calls[0][0]).toBe('tables.availability');
    });

    it('renders the availability grid when the server marks the tab active', () => {
        const wrapper = mount(Tables, {
            props: {
                tables: { data: [makeTable({ id: 1, label: 'Tisch 1' })] },
                activeTab: 'availability',
                availability: makeAvailability(),
            },
            global: { stubs: sheetStubs },
        });

        expect(wrapper.find('[data-testid="tab-panel-availability"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tab-panel-stammdaten"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="availability-grid"]').exists()).toBe(true);
        // The "Neuer Tisch" button is master-data only.
        expect(wrapper.find('[data-testid="new-table"]').exists()).toBe(false);
    });

    it('opens the drawer with empty fields for create', async () => {
        const wrapper = mountTables([makeTable({ id: 1, label: 'Tisch 1' })]);

        expect(wrapper.find('[data-testid="table-form"]').exists()).toBe(false);

        await wrapper.find('[data-testid="new-table"]').trigger('click');

        expect(wrapper.find('[data-testid="table-form"]').exists()).toBe(true);
        expect((wrapper.find('[data-testid="field-label"]').element as HTMLInputElement).value).toBe('');
    });

    it('opens the drawer pre-filled for edit', async () => {
        const wrapper = mountTables([makeTable({ id: 7, label: 'Fenstertisch', seats: 5, room_tag: 'Innen' })]);

        await wrapper.find('[data-testid="edit-7"]').trigger('click');

        expect((wrapper.find('[data-testid="field-label"]').element as HTMLInputElement).value).toBe('Fenstertisch');
        expect((wrapper.find('[data-testid="field-seats"]').element as HTMLInputElement).value).toBe('5');
    });

    it('submits a create through router.post with the form payload', async () => {
        const wrapper = mountTables([]);

        await wrapper.find('[data-testid="new-table"]').trigger('click');
        await wrapper.find('[data-testid="field-label"]').setValue('Tisch 9');
        await wrapper.find('[data-testid="field-seats"]').setValue(8);
        await wrapper.find('[data-testid="field-room-tag"]').setValue('Terrasse');
        await wrapper.find('form').trigger('submit');

        expect(patchSpy).not.toHaveBeenCalled();
        expect(postSpy).toHaveBeenCalledTimes(1);
        expect(postSpy.mock.calls[0][0]).toBe('tables.store');
        expect(postSpy.mock.calls[0][1]).toMatchObject({
            label: 'Tisch 9',
            seats: 8,
            room_tag: 'Terrasse',
            active: true,
        });
    });

    it('submits an edit through router.patch to the table route', async () => {
        const wrapper = mountTables([makeTable({ id: 3, label: 'Tisch 3', seats: 4 })]);

        await wrapper.find('[data-testid="edit-3"]').trigger('click');
        await wrapper.find('form').trigger('submit');

        expect(postSpy).not.toHaveBeenCalled();
        expect(patchSpy).toHaveBeenCalledTimes(1);
        expect(patchSpy.mock.calls[0][0]).toBe('tables.update/3');
        expect(patchSpy.mock.calls[0][1]).toMatchObject({ label: 'Tisch 3', seats: 4 });
    });

    it('requires a confirmation before deactivating and then calls router.delete', async () => {
        const wrapper = mountTables([makeTable({ id: 5 })]);

        await wrapper.find('[data-testid="deactivate-5"]').trigger('click');
        expect(wrapper.find('[data-testid="confirm-deactivate-5"]').exists()).toBe(true);
        expect(deleteSpy).not.toHaveBeenCalled();

        await wrapper.find('[data-testid="confirm-deactivate-5"]').trigger('click');
        expect(deleteSpy).toHaveBeenCalledTimes(1);
        expect(deleteSpy.mock.calls[0][0]).toBe('tables.destroy/5');
    });

    it('closes the drawer once a save succeeds', async () => {
        const wrapper = mountTables([]);

        await wrapper.find('[data-testid="new-table"]').trigger('click');
        await wrapper.find('[data-testid="field-label"]').setValue('Tisch 1');
        await wrapper.find('form').trigger('submit');

        expect(wrapper.find('[data-testid="table-form"]').exists()).toBe(true);

        // Drive the Inertia onSuccess callback the router mock captured; it is
        // what emits "saved" and lets the page close the drawer.
        const options = postSpy.mock.calls[0][2] as { onSuccess: () => void };
        options.onSuccess();
        await flushPromises();

        expect(wrapper.find('[data-testid="table-form"]').exists()).toBe(false);
    });
});

describe('TableAvailabilityGrid', () => {
    function mountGrid(availability: DayAvailability, tables: TableModel[] = [makeTable({ id: 1, label: 'Tisch 1' })]) {
        return mount(TableAvailabilityGrid, { props: { tables, availability } });
    }

    it('renders one row per slot with the matching state', () => {
        const wrapper = mountGrid(makeAvailability());

        const slotRows = wrapper.findAll('[data-testid="slot-row"]');
        expect(slotRows).toHaveLength(3);
        expect(slotRows[0].attributes('data-state')).toBe('free');
        expect(slotRows[1].attributes('data-state')).toBe('tight');
        expect(slotRows[2].attributes('data-state')).toBe('full');
        expect(wrapper.text()).toContain('17:00');
        expect(wrapper.get('[data-testid="occupancy"]').text()).toContain('4 / 12');
    });

    it('resolves the suggested table to its label and shows a dash when none', () => {
        const wrapper = mountGrid(makeAvailability());
        const slotRows = wrapper.findAll('[data-testid="slot-row"]');

        expect(slotRows[0].text()).toContain('Tisch 1');
        expect(slotRows[2].text()).toContain('–');
    });

    it('shows the closed-day state when there are no slots', () => {
        const wrapper = mountGrid(makeAvailability({ slots: [] }));

        expect(wrapper.find('[data-testid="availability-empty"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid="slot-row"]')).toHaveLength(0);
    });

    it('reloads only the availability prop when the date changes', async () => {
        const wrapper = mountGrid(makeAvailability());

        // setValue on a date input updates the v-model and fires `change`.
        await wrapper.get('[data-testid="availability-date"]').setValue('2026-06-20');

        expect(reloadSpy).toHaveBeenCalledTimes(1);
        expect(reloadSpy.mock.calls[0][0]).toMatchObject({
            data: { date: '2026-06-20' },
            only: ['availability'],
        });
    });
});

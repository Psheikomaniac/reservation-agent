import type { QuickAvailability, TableModel } from '@/types';
import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Quick from './Quick.vue';

const { postSpy, visitSpy, reloadSpy } = vi.hoisted(() => ({
    postSpy: vi.fn(),
    visitSpy: vi.fn(),
    reloadSpy: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    router: {
        post: (...args: unknown[]) => postSpy(...args),
        visit: (...args: unknown[]) => visitSpy(...args),
        reload: (...args: unknown[]) => reloadSpy(...args),
    },
}));

vi.mock('@/layouts/AppLayout.vue', () => ({
    default: { name: 'AppLayout', template: '<div data-testid="layout"><slot /></div>' },
}));

function makeTable(overrides: Partial<TableModel> = {}): TableModel {
    return {
        id: 1,
        label: '1',
        seats: 4,
        room_tag: null,
        sort_order: 1,
        active: true,
        combinable_with: [],
        created_at: null,
        ...overrides,
    };
}

function makeAvailability(overrides: Partial<QuickAvailability> = {}): QuickAvailability {
    return {
        state: 'free',
        suggested_table_id: 1,
        combination: null,
        alternative_slots: [],
        ...overrides,
    };
}

let wrapper: VueWrapper;
let confirmSpy: ReturnType<typeof vi.fn>;

function mountQuick(opts: { tables?: TableModel[]; availability?: QuickAvailability } = {}): VueWrapper {
    wrapper = mount(Quick, {
        props: {
            tables: { data: opts.tables ?? [makeTable()] },
            defaults: { date: '2026-06-15', time: '19:00', party_size: 2 },
            availability: opts.availability ?? makeAvailability(),
        },
    });
    return wrapper;
}

beforeEach(() => {
    vi.useFakeTimers();
    postSpy.mockReset();
    visitSpy.mockReset();
    reloadSpy.mockReset();
    confirmSpy = vi.fn(() => true);
    vi.stubGlobal('confirm', confirmSpy);
    vi.stubGlobal('route', (name: string, params?: Record<string, unknown>) => (params ? `${name}/${Object.values(params).join('/')}` : name));
});

afterEach(() => {
    // Unmount so the window keydown listener is removed before the next test.
    wrapper?.unmount();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('Reservations/Quick.vue', () => {
    it('reloads only the availability prop, debounced, when the date changes', async () => {
        mountQuick();

        await wrapper.get('[data-testid="field-date"]').setValue('2026-06-20');
        expect(reloadSpy).not.toHaveBeenCalled(); // still inside the 250ms debounce

        await vi.advanceTimersByTimeAsync(250);

        expect(reloadSpy).toHaveBeenCalledTimes(1);
        expect(reloadSpy.mock.calls[0][0]).toMatchObject({
            only: ['availability'],
            data: { date: '2026-06-20', time: '19:00', party_size: 2 },
        });
    });

    it('submits via router.post on Ctrl+Enter', async () => {
        mountQuick();
        await wrapper.get('[data-testid="field-name"]').setValue('Müller');

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true }));

        expect(postSpy).toHaveBeenCalledTimes(1);
        expect(postSpy.mock.calls[0][0]).toBe('reservations.quick.store');
        expect(postSpy.mock.calls[0][1]).toMatchObject({
            source: 'phone',
            guest_name: 'Müller',
            party_size: 2,
            table_id: null,
        });
    });

    it('cancels without confirmation when the form is pristine', () => {
        mountQuick();

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(confirmSpy).not.toHaveBeenCalled();
        expect(visitSpy).toHaveBeenCalledWith('dashboard');
    });

    it('confirms before cancelling on Esc when the form is dirty', async () => {
        mountQuick();
        await wrapper.get('[data-testid="field-name"]').setValue('Müller');

        confirmSpy.mockReturnValue(false);
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(visitSpy).not.toHaveBeenCalled();

        confirmSpy.mockReturnValue(true);
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(visitSpy).toHaveBeenCalledWith('dashboard');
    });

    it('renders the suggested table in the banner when the slot is free', () => {
        mountQuick({
            tables: [makeTable({ id: 7, label: '7', seats: 4 })],
            availability: makeAvailability({ suggested_table_id: 7 }),
        });

        const banner = wrapper.get('[data-testid="availability-banner"]');
        expect(banner.text()).toContain('Slot frei');
        expect(banner.text()).toContain('Tisch 7 (4 Plätze)');
    });

    it('lists clickable alternative slots when the slot is full and applies one on click', async () => {
        mountQuick({
            availability: makeAvailability({
                state: 'full',
                suggested_table_id: null,
                alternative_slots: [
                    { date: '2026-06-15', time: '18:30' },
                    { date: '2026-06-15', time: '20:00' },
                ],
            }),
        });

        const alternatives = wrapper.findAll('[data-testid="alt-slot"]');
        expect(alternatives).toHaveLength(2);
        expect(alternatives[0].text()).toBe('18:30');

        await alternatives[1].trigger('click');

        expect((wrapper.get('[data-testid="field-time"]').element as HTMLInputElement).value).toBe('20:00');
    });
});

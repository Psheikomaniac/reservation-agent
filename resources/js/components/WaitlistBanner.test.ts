import type { ReservationRequestRow } from '@/types';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WaitlistBanner from './WaitlistBanner.vue';

const { formatSpy } = vi.hoisted(() => ({
    formatSpy: vi.fn((iso: string | null) => `FMT(${iso})`),
}));

vi.mock('@/lib/format-datetime', () => ({
    formatDateTime: formatSpy,
}));

beforeEach(() => {
    formatSpy.mockReset();
    formatSpy.mockImplementation((iso: string | null) => `FMT(${iso})`);
});

function makeEntry(overrides: Partial<ReservationRequestRow> = {}): ReservationRequestRow {
    return {
        id: 1,
        status: 'waitlisted',
        source: 'web_form',
        guest_name: 'Müller',
        guest_email: null,
        guest_phone: null,
        party_size: 4,
        desired_at: '2026-06-15T19:00:00+00:00',
        needs_manual_review: false,
        created_at: null,
        has_raw_email: false,
        ...overrides,
    };
}

function makeEntries(count: number): ReservationRequestRow[] {
    return Array.from({ length: count }, (_, i) => makeEntry({ id: i + 1, guest_name: `Gast ${i + 1}` }));
}

describe('WaitlistBanner.vue', () => {
    it('renders nothing when the banner is empty', () => {
        const wrapper = mount(WaitlistBanner, { props: { banner: [] } });

        expect(wrapper.find('[data-testid="waitlist-banner"]').exists()).toBe(false);
    });

    it('renders one entry per waitlisted item with name and party size', () => {
        const wrapper = mount(WaitlistBanner, { props: { banner: makeEntries(3) } });

        const entries = wrapper.findAll('[data-testid^="waitlist-entry-"]');
        expect(entries).toHaveLength(3);
        expect(entries[0].text()).toContain('Gast 1');
        expect(entries[0].text()).toContain('(4 P.)');
    });

    it('emits open with the clicked entry id, not just the first', async () => {
        const wrapper = mount(WaitlistBanner, { props: { banner: [makeEntry({ id: 7 }), makeEntry({ id: 42 })] } });

        await wrapper.get('[data-testid="waitlist-entry-42"]').trigger('click');

        expect(wrapper.emitted('open')).toEqual([[42]]);
    });

    it('shows at most five entries and an overflow note for the rest', () => {
        const wrapper = mount(WaitlistBanner, { props: { banner: makeEntries(7) } });

        expect(wrapper.findAll('[data-testid^="waitlist-entry-"]')).toHaveLength(5);
        expect(wrapper.get('[data-testid="waitlist-overflow"]').text()).toContain('2 weitere');
    });

    it('has no overflow note at five or fewer entries', () => {
        const wrapper = mount(WaitlistBanner, { props: { banner: makeEntries(5) } });

        expect(wrapper.find('[data-testid="waitlist-overflow"]').exists()).toBe(false);
    });

    it('uses singular and plural headlines', () => {
        const one = mount(WaitlistBanner, { props: { banner: makeEntries(1) } });
        expect(one.get('[data-testid="waitlist-banner"]').text()).toContain('1 Wartender könnte');

        const many = mount(WaitlistBanner, { props: { banner: makeEntries(3) } });
        expect(many.get('[data-testid="waitlist-banner"]').text()).toContain('3 Wartende könnten');
    });

    it('formats the wished time in the restaurant timezone', () => {
        mount(WaitlistBanner, {
            props: { banner: [makeEntry({ desired_at: '2026-06-15T19:00:00+00:00' })], timezone: 'Europe/Berlin' },
        });

        expect(formatSpy).toHaveBeenCalledWith('2026-06-15T19:00:00+00:00', { timeZone: 'Europe/Berlin' });
    });
});

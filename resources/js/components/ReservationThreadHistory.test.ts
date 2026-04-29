import type { ThreadMessage } from '@/types';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ReservationThreadHistory from './ReservationThreadHistory.vue';

const outbound: ThreadMessage = {
    id: 11,
    direction: 'out',
    subject: 'Reservierung bei Le Bistro [Res #42]',
    from_address: 'noreply@bistro.example',
    to_address: 'guest@example.com',
    body_plain: 'Guten Tag, gerne!',
    sent_at: '2026-04-29T18:00:00+00:00',
    received_at: null,
    approved_by: 'Operator Anna',
};

const inbound: ThreadMessage = {
    id: 12,
    direction: 'in',
    subject: 'Re: Reservierung bei Le Bistro [Res #42]',
    from_address: 'guest@example.com',
    to_address: 'noreply@bistro.example',
    body_plain: 'Vielen Dank, bis dann!',
    sent_at: null,
    received_at: '2026-04-29T19:00:00+00:00',
    approved_by: null,
};

describe('ReservationThreadHistory', () => {
    it('renders the empty state when there are no messages', () => {
        const wrapper = mount(ReservationThreadHistory, { props: { messages: [] } });

        expect(wrapper.find('[data-testid="thread-history-empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="thread-history-list"]').exists()).toBe(false);
    });

    it('renders a chronological list of inbound and outbound messages', () => {
        const wrapper = mount(ReservationThreadHistory, { props: { messages: [outbound, inbound] } });

        const items = wrapper.findAll('[data-testid^="thread-message-"]');
        expect(items).toHaveLength(2);

        expect(items[0].attributes('data-testid')).toBe('thread-message-11');
        expect(items[0].attributes('data-direction')).toBe('out');
        expect(items[0].text()).toContain('Reservierung bei Le Bistro [Res #42]');
        expect(items[0].text()).toContain('Guten Tag, gerne!');
        expect(items[0].text()).toContain('Freigegeben von Operator Anna');

        expect(items[1].attributes('data-testid')).toBe('thread-message-12');
        expect(items[1].attributes('data-direction')).toBe('in');
        expect(items[1].text()).toContain('Re: Reservierung bei Le Bistro [Res #42]');
        expect(items[1].text()).toContain('Vielen Dank, bis dann!');
        // Inbound rows must NOT show "Freigegeben von …" — that's outbound-only.
        expect(items[1].text()).not.toContain('Freigegeben von');
    });

    it('renders the loading state when messages are null and loading is true', () => {
        const wrapper = mount(ReservationThreadHistory, { props: { messages: null, loading: true } });

        expect(wrapper.find('[data-testid="thread-history-loading"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="thread-history-list"]').exists()).toBe(false);
    });
});

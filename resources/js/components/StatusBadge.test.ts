import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import StatusBadge from './StatusBadge.vue';

describe('StatusBadge', () => {
    it('renders the token class and German label for a status', () => {
        const wrapper = mount(StatusBadge, { props: { status: 'confirmed' } });

        expect(wrapper.text()).toBe('Bestätigt');
        expect(wrapper.classes().join(' ')).toContain('text-status-confirmed');
    });

    it('renders cancelled (the 7th status) too', () => {
        const wrapper = mount(StatusBadge, { props: { status: 'cancelled' } });

        expect(wrapper.text()).toBe('Storniert');
        expect(wrapper.classes().join(' ')).toContain('status-cancelled');
    });
});

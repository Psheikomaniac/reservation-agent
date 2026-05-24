import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import OnboardingLayout from './OnboardingLayout.vue';

describe('OnboardingLayout', () => {
    it('renders the title in a dark topbar and the default slot', () => {
        const wrapper = mount(OnboardingLayout, {
            props: { title: 'Restaurant einrichten' },
            slots: { default: '<p>Inhalt</p>' },
        });

        expect(wrapper.find('header').classes()).toContain('bg-topbar');
        expect(wrapper.text()).toContain('Restaurant einrichten');
        expect(wrapper.html()).toContain('Inhalt');
    });
});

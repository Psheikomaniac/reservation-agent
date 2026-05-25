import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import OnboardingReminders from './OnboardingReminders.vue';

// Ziggy's route() is a template global property; provide it via mocks so the
// `:href="route(...)"` binding resolves (Link is stubbed to a plain anchor).
const mountOptions = {
    global: {
        mocks: { route: () => '/onboarding' },
        stubs: { Link: { template: '<a><slot /></a>' } },
    },
};

describe('OnboardingReminders', () => {
    it('renders a card per pending optional step', () => {
        const wrapper = mount(OnboardingReminders, { props: { reminders: ['team', 'tonality'] }, ...mountOptions });

        expect(wrapper.findAll('[data-testid="onboarding-reminder"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('Team einladen');
    });

    it('renders nothing when there are no reminders', () => {
        const wrapper = mount(OnboardingReminders, { props: { reminders: [] }, ...mountOptions });

        expect(wrapper.findAll('[data-testid="onboarding-reminder"]')).toHaveLength(0);
    });
});

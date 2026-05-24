import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

// Stub Inertia's useForm so the component mounts in isolation.
vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) => ({
        ...initial,
        errors: {},
        processing: false,
        patch: vi.fn(),
    }),
}));

// Ziggy route() global.
(globalThis as unknown as { route: () => string }).route = () => '/onboarding/hours';

import StepOpeningHours from './StepOpeningHours.vue';

const stubs = {
    Button: { template: '<button @click="$emit(\'click\')"><slot /></button>' },
    Input: { props: ['modelValue'], template: '<input :value="modelValue" />' },
    InputError: { props: ['message'], template: '<span>{{ message }}</span>' },
};

describe('StepOpeningHours', () => {
    it('renders a time row for a day with a block and Ruhetag for empty days', () => {
        const wrapper = mount(StepOpeningHours, {
            props: { openingHours: { mon: [{ from: '18:00', to: '22:00' }] } },
            global: { stubs },
        });

        expect(wrapper.find('[data-testid="mon-from-0"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Ruhetag'); // tue–sun default to empty
    });

    it('adds and removes a block via the exposed helpers', () => {
        const wrapper = mount(StepOpeningHours, {
            props: { openingHours: {} },
            global: { stubs },
        });

        const vm = wrapper.vm as unknown as {
            addBlock: (d: string) => void;
            removeBlock: (d: string, i: number) => void;
            form: { opening_hours: Record<string, unknown[]> };
        };

        expect(vm.form.opening_hours.fri).toEqual([]);

        vm.addBlock('fri');
        expect(vm.form.opening_hours.fri).toEqual([{ from: '18:00', to: '22:00' }]);

        vm.removeBlock('fri', 0);
        expect(vm.form.opening_hours.fri).toEqual([]);
    });
});

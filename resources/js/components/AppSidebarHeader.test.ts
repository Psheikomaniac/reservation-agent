import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import AppSidebarHeader from './AppSidebarHeader.vue';

const stubs = {
    SidebarTrigger: true,
    Breadcrumb: true,
    BreadcrumbItem: true,
    BreadcrumbLink: true,
    BreadcrumbList: true,
    BreadcrumbPage: true,
    BreadcrumbSeparator: true,
};

describe('AppSidebarHeader', () => {
    it('uses the dark direction-C topbar tokens', () => {
        const wrapper = mount(AppSidebarHeader, { props: { breadcrumbs: [] }, global: { stubs } });

        const header = wrapper.find('header');
        expect(header.classes()).toContain('bg-topbar');
        expect(header.classes()).toContain('text-topbar-foreground');
    });
});

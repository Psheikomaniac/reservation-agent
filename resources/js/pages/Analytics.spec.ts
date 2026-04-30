import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Analytics, { type AnalyticsSnapshot } from './Analytics.vue';

const reloadMock = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    router: {
        get reload() {
            return reloadMock;
        },
    },
}));

// vue-chartjs spins up a real chart instance against the canvas
// element which happy-dom doesn't fully implement. A stub keeps
// these tests fast and chart-library-agnostic — the chart's input
// data is asserted via the prop the stub records.
vi.mock('vue-chartjs', () => ({
    Line: {
        name: 'Line',
        props: ['data', 'options'],
        template: '<div data-testid="chart-stub" :data-labels="JSON.stringify(data.labels)" :data-counts="JSON.stringify(data.datasets[0].data)" />',
    },
}));

vi.mock('chart.js', () => ({
    Chart: { register: vi.fn() },
    CategoryScale: {},
    LinearScale: {},
    PointElement: {},
    LineElement: {},
    Title: {},
    Tooltip: {},
    Legend: {},
    Filler: {},
}));

vi.mock('@/layouts/AppLayout.vue', () => ({
    default: {
        name: 'AppLayout',
        template: '<div data-testid="layout"><slot /></div>',
    },
}));

function makeSnapshot(overrides: Partial<AnalyticsSnapshot> = {}): AnalyticsSnapshot {
    return {
        range: '30d',
        bucketSize: 'day',
        totals: { total: 12 },
        sources: { web_form: 8, email: 4 },
        statusBreakdown: {
            new: 3,
            in_review: 2,
            replied: 1,
            confirmed: 4,
            declined: 1,
            cancelled: 1,
        },
        responseTime: { medianMinutes: 30, p90Minutes: 120, sampleSize: 9 },
        editRate: 0.25,
        sendModeStats: null,
        trends: [
            { label: '2026-04-29', bucketStart: '2026-04-29T00:00:00+00:00', count: 5 },
            { label: '2026-04-30', bucketStart: '2026-04-30T00:00:00+00:00', count: 7 },
        ],
        confirmationRateTrend: [
            { label: '2026-04-29', bucketStart: '2026-04-29T00:00:00+00:00', count: 40 },
            { label: '2026-04-30', bucketStart: '2026-04-30T00:00:00+00:00', count: 60 },
        ],
        threadRepliesTrend: [
            { label: '2026-04-29', bucketStart: '2026-04-29T00:00:00+00:00', count: 1 },
            { label: '2026-04-30', bucketStart: '2026-04-30T00:00:00+00:00', count: 2 },
        ],
        ...overrides,
    };
}

beforeEach(() => {
    reloadMock.mockReset();
});

describe('Analytics.vue', () => {
    it('range-toggle switches active tab and triggers a partial reload', async () => {
        const wrapper = mount(Analytics, {
            props: { snapshot: makeSnapshot({ range: '30d' }) },
        });

        const tabs = wrapper.findAll('[role="tab"]');
        expect(tabs).toHaveLength(3);

        const todayTab = tabs[0];
        expect(todayTab.attributes('aria-selected')).toBe('false');

        await todayTab.trigger('click');

        expect(reloadMock).toHaveBeenCalledTimes(1);
        expect(reloadMock).toHaveBeenCalledWith({
            data: { range: 'today' },
            only: ['snapshot'],
            preserveScroll: true,
        });
    });

    it('does not reload when the active range tab is clicked again', async () => {
        const wrapper = mount(Analytics, {
            props: { snapshot: makeSnapshot({ range: '30d' }) },
        });

        // The third tab (30d) is active per the snapshot above.
        const activeTab = wrapper.findAll('[role="tab"]')[2];
        expect(activeTab.attributes('aria-selected')).toBe('true');

        await activeTab.trigger('click');

        expect(reloadMock).not.toHaveBeenCalled();
    });

    it('chart renders with the provided trend data', () => {
        const wrapper = mount(Analytics, {
            props: { snapshot: makeSnapshot() },
        });

        const charts = wrapper.findAll('[data-testid="chart-stub"]');
        expect(charts).toHaveLength(3);

        const requestsChart = charts[0];
        expect(JSON.parse(requestsChart.attributes('data-counts') ?? '[]')).toEqual([5, 7]);

        const confirmationChart = charts[1];
        expect(JSON.parse(confirmationChart.attributes('data-counts') ?? '[]')).toEqual([40, 60]);

        const threadChart = charts[2];
        expect(JSON.parse(threadChart.attributes('data-counts') ?? '[]')).toEqual([1, 2]);
    });

    it('shows the empty state when totals are zero', () => {
        const wrapper = mount(Analytics, {
            props: {
                snapshot: makeSnapshot({
                    totals: { total: 0 },
                    sources: { web_form: 0, email: 0 },
                    statusBreakdown: {
                        new: 0,
                        in_review: 0,
                        replied: 0,
                        confirmed: 0,
                        declined: 0,
                        cancelled: 0,
                    },
                }),
            },
        });

        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid="chart-stub"]')).toHaveLength(0);
    });

    it('hides send-mode stats in manual mode', () => {
        const wrapper = mount(Analytics, {
            props: { snapshot: makeSnapshot({ sendModeStats: null }) },
        });

        expect(wrapper.find('[data-testid="send-mode-section"]').exists()).toBe(false);
    });

    it('shows send-mode stats when shadow / auto mode is active', () => {
        const wrapper = mount(Analytics, {
            props: {
                snapshot: makeSnapshot({
                    sendModeStats: {
                        manual: 0,
                        shadow: 4,
                        auto: 0,
                        shadowComparedSampleSize: 3,
                        takeoverRate: 0.667,
                        topHardGateReasons: [
                            { reason: 'short_notice', count: 2 },
                            { reason: 'first_time_guest', count: 1 },
                        ],
                    },
                }),
            },
        });

        expect(wrapper.find('[data-testid="send-mode-section"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('short_notice');
        expect(wrapper.text()).toContain('first_time_guest');
    });
});

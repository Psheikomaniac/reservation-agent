import { describe, expect, it } from 'vitest';
import { formatDateTime } from './format-datetime';

describe('formatDateTime', () => {
    it('returns the em-dash placeholder for null', () => {
        expect(formatDateTime(null)).toBe('–');
    });

    it('returns the em-dash placeholder for an unparseable string', () => {
        expect(formatDateTime('not-a-date')).toBe('–');
    });

    it('renders a UTC ISO-8601 timestamp in the given restaurant timezone', () => {
        // 18:00 UTC = 20:00 in Europe/Berlin during DST.
        const rendered = formatDateTime('2025-06-15T18:00:00+00:00', { timeZone: 'Europe/Berlin' });

        expect(rendered).toContain('20:00');
        expect(rendered).toContain('15.06.2025');
    });

    it('renders the same UTC moment differently for a different timezone (proves timezone is applied)', () => {
        const iso = '2025-06-15T18:00:00+00:00';

        const berlin = formatDateTime(iso, { timeZone: 'Europe/Berlin' });
        const auckland = formatDateTime(iso, { timeZone: 'Pacific/Auckland' });

        expect(berlin).not.toBe(auckland);
        // Auckland is +12 in NZST (winter), so 18:00 UTC is 06:00 next day.
        expect(auckland).toContain('06:00');
        expect(auckland).toContain('16.06.2025');
    });

    it('honors a UTC timezone explicitly', () => {
        const rendered = formatDateTime('2025-01-01T00:00:00+00:00', { timeZone: 'UTC' });

        expect(rendered).toContain('00:00');
        expect(rendered).toContain('01.01.2025');
    });
});

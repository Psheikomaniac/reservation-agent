import { describe, expect, it } from 'vitest';
import { RESERVATION_STATUSES, statusBadgeClass, statusLabel } from './reservationStatus';

describe('reservationStatus', () => {
    it('covers all seven statuses including cancelled', () => {
        expect(RESERVATION_STATUSES).toEqual(['new', 'in_review', 'replied', 'confirmed', 'declined', 'cancelled', 'waitlisted']);
    });

    it('returns a token-based badge class and a non-empty label for every status', () => {
        for (const status of RESERVATION_STATUSES) {
            expect(statusBadgeClass(status)).toContain('status-');
            expect(statusLabel(status).length).toBeGreaterThan(0);
        }
    });
});

export const RESERVATION_STATUSES = ['new', 'in_review', 'replied', 'confirmed', 'declined', 'cancelled', 'waitlisted'] as const;

export type ReservationStatus = (typeof RESERVATION_STATUSES)[number];

const BADGE: Record<ReservationStatus, string> = {
    new: 'bg-status-new/15 text-status-new',
    in_review: 'bg-status-in-review/15 text-status-in-review',
    replied: 'bg-status-replied/15 text-status-replied',
    confirmed: 'bg-status-confirmed/15 text-status-confirmed',
    declined: 'bg-status-declined/15 text-status-declined',
    cancelled: 'bg-status-cancelled/15 text-status-cancelled',
    waitlisted: 'bg-status-waitlisted/15 text-status-waitlisted',
};

const LABEL: Record<ReservationStatus, string> = {
    new: 'Neu',
    in_review: 'In Prüfung',
    replied: 'Beantwortet',
    confirmed: 'Bestätigt',
    declined: 'Abgelehnt',
    cancelled: 'Storniert',
    waitlisted: 'Warteliste',
};

export const statusBadgeClass = (status: ReservationStatus): string => BADGE[status];
export const statusLabel = (status: ReservationStatus): string => LABEL[status];

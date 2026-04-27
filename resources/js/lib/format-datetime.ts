/**
 * Renders an ISO-8601 timestamp in `de-DE` medium-date / short-time format.
 *
 * The API contract (CLAUDE.md, PRD-004) is to emit timestamps in UTC ISO-8601.
 * Rendering must happen in the restaurant's local timezone so operators see
 * evening-service reservations at the wall-clock time they actually serve them.
 *
 * Pass `timeZone` (IANA, e.g. `Europe/Berlin`) to render in restaurant time.
 * Without it, the viewer's browser timezone is used — only acceptable when no
 * restaurant context is available (e.g. shell pages before login).
 */
export function formatDateTime(iso: string | null, options?: { timeZone?: string }): string {
    if (!iso) {
        return '–';
    }

    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '–';
    }

    return new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: options?.timeZone,
    }).format(date);
}

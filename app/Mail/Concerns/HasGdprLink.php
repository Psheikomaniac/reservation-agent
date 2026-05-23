<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use Illuminate\Support\Facades\URL;

/**
 * Central generator for the GDPR self-service link (PRD-015) so no outbound
 * mailable forgets the Art. 15/17 footer. Every mailable that reaches a guest
 * (PRD-005 manual reply, PRD-007 auto-send, PRD-014 sync-confirm) uses this.
 *
 * The link is produced at render time, so each send carries a fresh 30-day
 * signature — a guest replying within an active thread always gets a live
 * link rather than an expired one.
 */
trait HasGdprLink
{
    private const int GDPR_LINK_TTL_DAYS = 30;

    /**
     * A 30-day signed `gdpr.self-service` URL for the given reservation request.
     */
    protected function gdprLink(int $reservationId): string
    {
        return URL::temporarySignedRoute(
            'gdpr.self-service',
            now()->addDays(self::GDPR_LINK_TTL_DAYS),
            ['reservation' => $reservationId],
        );
    }
}

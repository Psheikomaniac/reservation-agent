<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\Notifications\DigestSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Daily wrap-up mail (PRD-010 § Email-Digest). Carries the
 * already-aggregated DigestSummary; the calculation lives in the
 * service so the mailable stays a thin presenter the queue worker
 * can rehydrate without database access.
 */
final class DailyDigestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly DigestSummary $summary) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Tageszusammenfassung — %s', $this->summary->restaurantName),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notifications.daily-digest',
            with: [
                'summary' => $this->summary,
                // Per PRD-010 § Permission-Flow im UI the operator
                // can disable the digest from the same settings
                // page. Surface the route so the mail isn't a
                // dead-end if they want to opt out.
                'settingsUrl' => route('settings.notifications.edit'),
            ],
        );
    }
}

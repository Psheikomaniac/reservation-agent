<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\MessageDirection;
use App\Models\ReservationMessage;
use App\Models\ReservationReply;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The single Mailable that delivers an approved AI-drafted reply to the
 * guest (PRD-005). Subject is a German one-liner referencing the
 * restaurant by name; the body is the operator-approved text — no
 * internal IDs, no JSON snapshots, no debug data — and a plaintext view
 * is the canonical rendering. From-address falls back to the application
 * mail config; per-restaurant from-addresses are a V1.1 extension.
 *
 * V2.0 / PRD-006 mail threading: every send carries a stable RFC-2822
 * Message-ID (`<reservation-{reply_id}-{16hex}@{domain}>`) and the subject
 * is suffixed with the threading marker `[Res #<reservation_request_id>]`
 * so the inbound resolver can route replies back to the same reservation.
 */
final class ReservationReplyMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public readonly string $messageId;

    public function __construct(public readonly ReservationReply $reply)
    {
        $this->messageId = self::generateMessageId($reply->id);
    }

    public function envelope(): Envelope
    {
        $restaurant = $this->reply->reservationRequest->restaurant;
        $defaultSubject = 'Reservierung bei '.$restaurant->name;

        return new Envelope(
            subject: $this->buildSubjectWithMarker($defaultSubject, $this->reply->reservation_request_id),
        );
    }

    public function headers(): Headers
    {
        $previousIds = $this->previousMessageIds();

        // In-Reply-To takes the most recent predecessor; the project-wide
        // convention for Message-IDs in `text:` headers is to wrap them in
        // angle brackets explicitly (the `references:` slot wraps for us).
        $text = $previousIds === []
            ? []
            : ['In-Reply-To' => '<'.end($previousIds).'>'];

        return new Headers(
            messageId: $this->messageId,
            references: $previousIds,
            text: $text,
        );
    }

    /**
     * Up to the most recent ten outbound Message-IDs for this reservation,
     * in chronological order. The current send is excluded — it has not
     * been persisted yet — so this returns the predecessor chain only.
     *
     * @return list<string>
     */
    private function previousMessageIds(): array
    {
        return ReservationMessage::query()
            ->where('reservation_request_id', $this->reply->reservation_request_id)
            ->where('direction', MessageDirection::Out)
            ->whereNotNull('message_id')
            ->orderBy('sent_at')
            ->orderBy('id')
            ->pluck('message_id')
            ->slice(-10)
            ->values()
            ->all();
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.reservation-reply',
            with: [
                // Body is rendered with `{!! $body !!}` (raw, not escaped) so
                // operator-typed quotes/ampersands/Umlaute reach the guest as
                // typed. The body is operator-approved (PRD-005 human-in-the-
                // loop), so the threat model that motivates Blade's default
                // escaping does not apply — and HTML-escaping inside a
                // plaintext mail is incorrect by definition.
                'body' => $this->reply->body,
            ],
        );
    }

    /**
     * RFC-2822 Message-ID for outbound replies. Stable per reply, unique
     * per send (16 random hex bytes prevent retries from colliding).
     */
    public static function generateMessageId(int $replyId): string
    {
        return sprintf(
            'reservation-%d-%s@%s',
            $replyId,
            bin2hex(random_bytes(8)),
            self::deriveDomain(),
        );
    }

    private static function deriveDomain(): string
    {
        $from = config('mail.from.address');

        if (! is_string($from) || ! str_contains($from, '@')) {
            return 'localhost';
        }

        $domain = substr($from, strrpos($from, '@') + 1);

        return $domain !== '' ? $domain : 'localhost';
    }

    private function buildSubjectWithMarker(string $subject, int $reservationRequestId): string
    {
        $marker = sprintf('[Res #%d]', $reservationRequestId);

        if (str_contains($subject, $marker)) {
            return $subject;
        }

        return trim($subject.' '.$marker);
    }
}

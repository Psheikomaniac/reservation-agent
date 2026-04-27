<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ReservationReply;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The single Mailable that delivers an approved AI-drafted reply to the
 * guest (PRD-005). Subject is a German one-liner referencing the
 * restaurant by name; the body is the operator-approved text — no
 * internal IDs, no JSON snapshots, no debug data — and a plaintext view
 * is the canonical rendering. From-address falls back to the application
 * mail config; per-restaurant from-addresses are a V1.1 extension.
 */
final class ReservationReplyMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ReservationReply $reply) {}

    public function envelope(): Envelope
    {
        $restaurant = $this->reply->reservationRequest->restaurant;

        return new Envelope(
            subject: 'Ihre Reservierungsanfrage bei '.$restaurant->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.reservation-reply',
            with: [
                'body' => $this->reply->body,
                'restaurantName' => $this->reply->reservationRequest->restaurant->name,
            ],
        );
    }
}

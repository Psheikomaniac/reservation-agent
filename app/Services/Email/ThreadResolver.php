<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\MessageDirection;
use App\Models\ReservationMessage;
use App\Models\ReservationRequest;
use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\Message;

/**
 * Resolves an incoming mail to an existing ReservationRequest using a
 * four-strategy cascade. Every positive resolution is re-verified via
 * verifySender, so a spoofed In-Reply-To cannot hijack a foreign thread.
 *
 * Strategy bodies arrive incrementally: byInReplyTo + byReferences (#203),
 * bySubjectMarker + byHeuristic (#204).
 *
 * Not declared `final` (against the project default) so the cascade
 * contract can be exercised by a test-only subclass that swaps a single
 * strategy stub. Production code MUST NOT subclass this — extend the
 * strategies themselves once they are split out, not the cascade.
 */
class ThreadResolver
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Tries to attach an inbound mail to an existing reservation. Returns
     * null when no strategy matches or when the verified sender does not
     * match the reservation's stored guest email.
     */
    public function resolveForIncoming(Message $message, int $restaurantId): ?ReservationRequest
    {
        if ($candidate = $this->byInReplyTo($message, $restaurantId)) {
            return $this->verifySender($candidate, $message);
        }

        if ($candidate = $this->byReferences($message, $restaurantId)) {
            return $this->verifySender($candidate, $message);
        }

        if ($candidate = $this->bySubjectMarker($message, $restaurantId)) {
            return $this->verifySender($candidate, $message);
        }

        if ($candidate = $this->byHeuristic($message, $restaurantId)) {
            return $this->verifySender($candidate, $message);
        }

        return null;
    }

    protected function byInReplyTo(Message $message, int $restaurantId): ?ReservationRequest
    {
        return $this->lookupOutboundMessage(trim((string) $message->getInReplyTo()), $restaurantId);
    }

    protected function byReferences(Message $message, int $restaurantId): ?ReservationRequest
    {
        $references = trim((string) $message->getReferences());
        if ($references === '') {
            return null;
        }

        foreach (preg_split('/\s+/', $references) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            if ($match = $this->lookupOutboundMessage($candidate, $restaurantId)) {
                return $match;
            }
        }

        return null;
    }

    private function lookupOutboundMessage(string $messageId, int $restaurantId): ?ReservationRequest
    {
        if ($messageId === '') {
            return null;
        }

        $hit = ReservationMessage::query()
            ->where('direction', MessageDirection::Out)
            ->where('message_id', $messageId)
            ->whereHas('reservationRequest', fn ($query) => $query->where('restaurant_id', $restaurantId))
            ->with('reservationRequest')
            ->first();

        return $hit?->reservationRequest;
    }

    protected function bySubjectMarker(Message $message, int $restaurantId): ?ReservationRequest
    {
        return null;
    }

    protected function byHeuristic(Message $message, int $restaurantId): ?ReservationRequest
    {
        return null;
    }

    protected function verifySender(ReservationRequest $request, Message $message): ?ReservationRequest
    {
        $from = strtolower(trim((string) ($message->getFrom()[0]->mail ?? '')));
        $expected = strtolower(trim((string) $request->guest_email));

        if ($from === '' || $expected === '' || $from !== $expected) {
            $this->logger->warning('thread resolver: sender mismatch, falling back to new reservation', [
                'reservation_id' => $request->id,
                'message_id' => (string) $message->getMessageId(),
            ]);

            return null;
        }

        return $request;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\MessageDirection;
use App\Models\ReservationMessage;
use App\Models\ReservationRequest;
use App\Services\Email\DTO\FetchedEmail;
use Psr\Log\LoggerInterface;

/**
 * Resolves an incoming mail to an existing ReservationRequest using a
 * four-strategy cascade. Every positive resolution is re-verified via
 * verifySender, so a spoofed In-Reply-To cannot hijack a foreign thread.
 *
 * Strategies, in cascade order: header-based (byInReplyTo, byReferences),
 * then subject-based fallbacks (bySubjectMarker, byHeuristic). The
 * heuristic is the weakest signal, so it runs last and additionally
 * filters by sender + 30-day window before verifySender.
 *
 * Not declared `final` (against the project default) so the cascade
 * contract can be exercised by a test-only subclass that swaps a single
 * strategy stub. Production code MUST NOT subclass this — extend the
 * strategies themselves once they are split out, not the cascade.
 */
class ThreadResolver
{
    private const REPLY_PREFIX_PATTERN = '/^(?:re|aw|antw)(?:\[\d+\])?:\s*/i';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Tries to attach an inbound mail to an existing reservation. Returns
     * null when no strategy matches or when the verified sender does not
     * match the reservation's stored guest email.
     */
    public function resolveForIncoming(FetchedEmail $email, int $restaurantId): ?ReservationRequest
    {
        if ($candidate = $this->byInReplyTo($email, $restaurantId)) {
            return $this->verifySender($candidate, $email);
        }

        if ($candidate = $this->byReferences($email, $restaurantId)) {
            return $this->verifySender($candidate, $email);
        }

        if ($candidate = $this->bySubjectMarker($email, $restaurantId)) {
            return $this->verifySender($candidate, $email);
        }

        if ($candidate = $this->byHeuristic($email, $restaurantId)) {
            return $this->verifySender($candidate, $email);
        }

        return null;
    }

    protected function byInReplyTo(FetchedEmail $email, int $restaurantId): ?ReservationRequest
    {
        return $this->lookupOutboundMessage(trim($email->inReplyTo), $restaurantId);
    }

    protected function byReferences(FetchedEmail $email, int $restaurantId): ?ReservationRequest
    {
        $references = trim($email->references);
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

    protected function bySubjectMarker(FetchedEmail $email, int $restaurantId): ?ReservationRequest
    {
        if (! preg_match('/\[Res #(\d+)\]/', $email->subject, $matches)) {
            return null;
        }

        return ReservationRequest::query()
            ->whereKey((int) $matches[1])
            ->where('restaurant_id', $restaurantId)
            ->first();
    }

    protected function byHeuristic(FetchedEmail $email, int $restaurantId): ?ReservationRequest
    {
        $subject = $email->subject;

        if (! preg_match(self::REPLY_PREFIX_PATTERN, $subject)) {
            return null;
        }

        $stripped = trim((string) preg_replace(self::REPLY_PREFIX_PATTERN, '', $subject));
        if ($stripped === '') {
            return null;
        }

        $from = strtolower(trim($email->senderEmail));
        if ($from === '') {
            return null;
        }

        $candidates = ReservationMessage::query()
            ->where('direction', MessageDirection::Out)
            ->where('subject', $stripped)
            ->where('sent_at', '>=', now()->subDays(30))
            ->whereHas('reservationRequest', fn ($query) => $query->where('restaurant_id', $restaurantId))
            ->with('reservationRequest')
            ->latest('sent_at')
            ->get();

        $match = $candidates->first(
            fn (ReservationMessage $candidate) => strtolower(trim((string) $candidate->reservationRequest?->guest_email)) === $from,
        );

        return $match?->reservationRequest;
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

    protected function verifySender(ReservationRequest $request, FetchedEmail $email): ?ReservationRequest
    {
        $from = strtolower(trim($email->senderEmail));
        $expected = strtolower(trim((string) $request->guest_email));

        if ($from === '' || $expected === '' || $from !== $expected) {
            $this->logger->warning('thread resolver: sender mismatch, falling back to new reservation', [
                'reservation_id' => $request->id,
                'message_id' => $email->messageId,
            ]);

            return null;
        }

        return $request;
    }
}

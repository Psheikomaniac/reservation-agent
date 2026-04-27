<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\ReservationRequest;
use App\Support\OpeningHours;

/**
 * Deterministic producer of the Context-JSON consumed by the AI reply
 * generator (PRD-005).
 *
 * Every number in the output comes from Laravel — never from the AI. The
 * builder is intentionally pure (no external IO besides DB reads via the
 * already-loaded models) so it can be unit-tested without OpenAI.
 *
 * Output shape:
 *
 *   restaurant   → name, tonality
 *   request      → guest_name, party_size, desired_at (restaurant local time), message
 *   availability → is_open_at_desired_time, seats_free_at_desired,
 *                  alternative_slots, closed_reason
 */
final class ReservationContextBuilder
{
    /**
     * @return array{
     *     restaurant: array{name: string, tonality: string},
     *     request: array{guest_name: string, party_size: int, desired_at: ?string, message: ?string},
     *     availability: array{is_open_at_desired_time: bool, seats_free_at_desired: int, alternative_slots: list<string>, closed_reason: ?string}
     * }
     */
    public function build(ReservationRequest $request): array
    {
        $restaurant = $request->restaurant;
        $hours = OpeningHours::fromRestaurant($restaurant);
        $desiredAt = $request->desired_at;

        $isOpen = $desiredAt !== null && $hours->isOpenAt($desiredAt);
        $closedReason = $desiredAt !== null ? $hours->closedReasonAt($desiredAt) : null;

        // availableSeatsAt() returns null when the restaurant is closed at
        // that time. Per PRD-005, we surface 0 to the AI in that case so the
        // "decline / suggest alternatives" branch triggers naturally.
        $seatsFree = $desiredAt !== null
            ? ($restaurant->availableSeatsAt($desiredAt) ?? 0)
            : 0;

        $localDesiredAt = $desiredAt?->copy()->setTimezone($restaurant->timezone);

        return [
            'restaurant' => [
                'name' => $restaurant->name,
                'tonality' => $restaurant->tonality->value,
            ],
            'request' => [
                'guest_name' => $request->guest_name,
                'party_size' => $request->party_size,
                'desired_at' => $localDesiredAt?->format('Y-m-d H:i'),
                'message' => $request->message,
            ],
            'availability' => [
                'is_open_at_desired_time' => $isOpen,
                'seats_free_at_desired' => $seatsFree,
                // Alternative-slot generation is implemented in #67. For #65
                // the field is present (per the documented shape) but empty.
                'alternative_slots' => [],
                'closed_reason' => $closedReason,
            ],
        ];
    }
}

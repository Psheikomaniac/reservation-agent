<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Support\OpeningHours;
use Carbon\CarbonInterface;

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
    /** Spacing between candidate alternative slots, in minutes (PRD-005). */
    private const int SLOT_STEP_MINUTES = 30;

    /** Maximum number of alternative slots returned to the AI prompt. */
    private const int MAX_ALTERNATIVE_SLOTS = 3;

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
                'alternative_slots' => $desiredAt === null
                    ? []
                    : $this->alternativeSlots($restaurant, $desiredAt, $request->party_size),
                'closed_reason' => $closedReason,
            ],
        ];
    }

    /**
     * Up to MAX_ALTERNATIVE_SLOTS candidate slots on the same restaurant-local
     * day, spaced SLOT_STEP_MINUTES apart, that:
     *   - lie inside an opening block,
     *   - have at least $partySize seats free,
     *   - are not the exact desired time.
     *
     * Sorted by absolute distance to the desired time (closest first).
     * Returns `[]` when the day has no opening blocks (Ruhetag).
     *
     * @return list<string> Carbon `Y-m-d H:i` formatted strings in restaurant local time.
     */
    private function alternativeSlots(Restaurant $restaurant, CarbonInterface $desiredAt, int $partySize): array
    {
        $hours = OpeningHours::fromRestaurant($restaurant);
        $blocks = $hours->blocksAt($desiredAt);

        if ($blocks === []) {
            return [];
        }

        $local = $desiredAt->copy()->setTimezone($restaurant->timezone);
        $desiredKey = $local->format('Y-m-d H:i');

        $eligible = [];
        foreach ($blocks as $block) {
            $start = $local->copy()->setTimeFromTimeString($block['from']);
            $end = $local->copy()->setTimeFromTimeString($block['to']);

            for ($cursor = $start->copy(); $cursor->lt($end); $cursor->addMinutes(self::SLOT_STEP_MINUTES)) {
                if ($cursor->format('Y-m-d H:i') === $desiredKey) {
                    continue;
                }

                $seats = $restaurant->availableSeatsAt($cursor);
                if ($seats === null || $seats < $partySize) {
                    continue;
                }

                $eligible[] = $cursor->copy();
            }
        }

        usort(
            $eligible,
            fn (CarbonInterface $a, CarbonInterface $b): int => abs($a->diffInSeconds($local))
                <=> abs($b->diffInSeconds($local))
        );

        return array_map(
            fn (CarbonInterface $slot): string => $slot->format('Y-m-d H:i'),
            array_slice($eligible, 0, self::MAX_ALTERNATIVE_SLOTS)
        );
    }
}

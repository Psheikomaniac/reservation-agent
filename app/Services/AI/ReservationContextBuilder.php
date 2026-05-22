<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\SlotState;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Availability\SlotAvailability;
use App\Support\OpeningHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Deterministic producer of the Context-JSON consumed by the AI reply
 * generator (PRD-005).
 *
 * Every availability fact comes from Laravel — never from the AI. As of PRD-011
 * the table-level {@see SlotAvailability} service is the single source of truth:
 * the builder maps its free/tight/full verdict (and the next-best slots it
 * suggests when full) into the prompt context. Opening-hours framing
 * (`is_open_at_desired_time`, `closed_reason`) still comes from OpeningHours.
 *
 * Output shape:
 *
 *   restaurant   → name, tonality
 *   request      → guest_name, party_size, desired_at (restaurant local time), message
 *   availability → is_open_at_desired_time, closed_reason, slot_state,
 *                  is_available, alternative_slots
 */
final class ReservationContextBuilder
{
    public function __construct(
        private readonly SlotAvailability $slotAvailability,
    ) {}

    /**
     * @return array{
     *     restaurant: array{name: string, tonality: string},
     *     request: array{guest_name: string, party_size: int, desired_at: ?string, message: ?string},
     *     availability: array{is_open_at_desired_time: bool, closed_reason: ?string, slot_state: string, is_available: bool, alternative_slots: list<string>}
     * }
     */
    public function build(ReservationRequest $request): array
    {
        $restaurant = $request->restaurant;
        $hours = OpeningHours::fromRestaurant($restaurant);
        $desiredAt = $request->desired_at;

        $isOpen = $desiredAt !== null && $hours->isOpenAt($desiredAt);
        $closedReason = $desiredAt !== null ? $hours->closedReasonAt($desiredAt) : null;
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
                'closed_reason' => $closedReason,
                ...$this->slotAvailabilityFor($request, $desiredAt, $restaurant),
            ],
        ];
    }

    /**
     * Table-level availability for the desired slot, mapped from
     * {@see SlotAvailability::forSlot}. When `desired_at` is missing we report an
     * unavailable, stateless slot so the AI falls back to manual handling.
     *
     * @return array{slot_state: string, is_available: bool, alternative_slots: list<string>}
     */
    private function slotAvailabilityFor(ReservationRequest $request, ?CarbonInterface $desiredAt, Restaurant $restaurant): array
    {
        if ($desiredAt === null) {
            return [
                'slot_state' => SlotState::Full->value,
                'is_available' => false,
                'alternative_slots' => [],
            ];
        }

        $slot = $this->slotAvailability->forSlot(
            $request->restaurant_id,
            CarbonImmutable::parse($desiredAt),
            $request->party_size,
        );

        return [
            'slot_state' => $slot->state->value,
            'is_available' => $slot->state !== SlotState::Full,
            'alternative_slots' => $slot->alternativeSlots
                ->map(fn (CarbonImmutable $candidate): string => $candidate->setTimezone($restaurant->timezone)->format('Y-m-d H:i'))
                ->values()
                ->all(),
        ];
    }
}

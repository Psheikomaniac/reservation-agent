<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Availability state of a single slot (PRD-011).
 *
 * Computed deterministically by the SlotAvailability service; the AI never
 * decides this — it only phrases the result. `tight` means a slot is still
 * bookable but ≤ 25 % of the restaurant's active capacity remains.
 */
enum SlotState: string
{
    case Free = 'free';
    case Tight = 'tight';
    case Full = 'full';
}

<?php

declare(strict_types=1);

namespace App\Services\Availability\DTOs;

/**
 * A table assignment proposal for a slot (PRD-011).
 *
 * Either a single fitting table (`tableIds` has one entry) or a two-table
 * combination via `combinable_with` (`tableIds` has two). V3 caps combinations
 * at two tables; 3+ is out of scope. `primaryTableId` is the table the UI
 * highlights first; `totalSeats` is the summed capacity of all `tableIds`.
 */
final readonly class TableCombination
{
    /**
     * @param  list<int>  $tableIds
     */
    public function __construct(
        public int $primaryTableId,
        public array $tableIds,
        public int $totalSeats,
    ) {}
}

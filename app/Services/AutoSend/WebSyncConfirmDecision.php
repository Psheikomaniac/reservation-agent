<?php

declare(strict_types=1);

namespace App\Services\AutoSend;

use App\Services\Availability\DTOs\SlotAvailabilityResult;
use InvalidArgumentException;

/**
 * Outcome of {@see WebSyncConfirmDecider} for a web reservation (PRD-014).
 *
 * `proceed` carries the deterministic slot verdict the controller needs to
 * assign a table; `skip` carries the gate reason that sent the request back to
 * the normal owner-approval path. Exactly one of the two is populated.
 */
final readonly class WebSyncConfirmDecision
{
    public const string STATE_PROCEED = 'proceed';

    public const string STATE_SKIP = 'skip';

    private function __construct(
        public string $state,
        public ?string $reason,
        public ?SlotAvailabilityResult $slot,
    ) {}

    public static function proceed(SlotAvailabilityResult $slot): self
    {
        return new self(self::STATE_PROCEED, null, $slot);
    }

    public static function skip(string $reason): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A skip decision must carry a non-empty reason.');
        }

        return new self(self::STATE_SKIP, $reason, null);
    }

    public function shouldProceed(): bool
    {
        return $this->state === self::STATE_PROCEED;
    }
}

<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case New = 'new';
    case InReview = 'in_review';
    case Replied = 'replied';
    case Confirmed = 'confirmed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::New => [self::InReview, self::Declined],
            self::InReview => [self::Replied, self::Declined],
            self::Replied => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Cancelled],
            self::Declined, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNextStates(), true);
    }
}

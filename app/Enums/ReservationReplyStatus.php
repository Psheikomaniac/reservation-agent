<?php

namespace App\Enums;

enum ReservationReplyStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Sent = 'sent';
    case Failed = 'failed';

    // PRD-007 auto-send extensions:
    case Shadow = 'shadow';
    case ScheduledAutoSend = 'scheduled_auto_send';
    case CancelledAuto = 'cancelled_auto';

    /**
     * @return list<self>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Draft => [self::Approved, self::Failed, self::Shadow, self::ScheduledAutoSend],
            self::Approved => [self::Sent, self::Failed],
            self::Shadow => [self::Approved, self::Draft],
            self::ScheduledAutoSend => [self::Sent, self::CancelledAuto, self::Approved],
            self::Sent, self::Failed, self::CancelledAuto => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNextStates(), true);
    }
}

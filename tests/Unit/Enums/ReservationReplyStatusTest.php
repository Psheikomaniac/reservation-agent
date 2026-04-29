<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ReservationReplyStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservationReplyStatusTest extends TestCase
{
    /**
     * The full V1 + PRD-007 transition matrix. Every (from, to) pair not
     * listed below is implicitly forbidden by the default false.
     *
     * @return array<string, array{0: ReservationReplyStatus, 1: ReservationReplyStatus, 2: bool}>
     */
    public static function transitionMatrix(): array
    {
        $allowed = [
            // V1.0
            [ReservationReplyStatus::Draft, ReservationReplyStatus::Approved],
            [ReservationReplyStatus::Draft, ReservationReplyStatus::Failed],
            [ReservationReplyStatus::Approved, ReservationReplyStatus::Sent],
            [ReservationReplyStatus::Approved, ReservationReplyStatus::Failed],
            // PRD-007 extensions
            [ReservationReplyStatus::Draft, ReservationReplyStatus::Shadow],
            [ReservationReplyStatus::Draft, ReservationReplyStatus::ScheduledAutoSend],
            [ReservationReplyStatus::Shadow, ReservationReplyStatus::Approved],
            [ReservationReplyStatus::Shadow, ReservationReplyStatus::Draft],
            [ReservationReplyStatus::ScheduledAutoSend, ReservationReplyStatus::Sent],
            [ReservationReplyStatus::ScheduledAutoSend, ReservationReplyStatus::CancelledAuto],
            [ReservationReplyStatus::ScheduledAutoSend, ReservationReplyStatus::Approved],
        ];

        $rows = [];
        foreach (ReservationReplyStatus::cases() as $from) {
            foreach (ReservationReplyStatus::cases() as $to) {
                $isAllowed = false;
                foreach ($allowed as [$a, $b]) {
                    if ($from === $a && $to === $b) {
                        $isAllowed = true;
                        break;
                    }
                }
                $rows["{$from->value} → {$to->value}"] = [$from, $to, $isAllowed];
            }
        }

        return $rows;
    }

    #[DataProvider('transitionMatrix')]
    public function test_can_transition_to_matches_the_prd_matrix(ReservationReplyStatus $from, ReservationReplyStatus $to, bool $expected): void
    {
        $this->assertSame($expected, $from->canTransitionTo($to));
    }

    public function test_terminal_statuses_have_no_allowed_next_states(): void
    {
        $this->assertSame([], ReservationReplyStatus::Sent->allowedNextStates());
        $this->assertSame([], ReservationReplyStatus::Failed->allowedNextStates());
        $this->assertSame([], ReservationReplyStatus::CancelledAuto->allowedNextStates());
    }

    public function test_self_transitions_are_forbidden(): void
    {
        foreach (ReservationReplyStatus::cases() as $status) {
            $this->assertFalse(
                $status->canTransitionTo($status),
                "{$status->value} → {$status->value} must be forbidden (self-transition)",
            );
        }
    }
}

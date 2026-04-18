<?php

namespace Tests\Unit\Enums;

use App\Enums\ReservationStatus;
use Tests\TestCase;

class ReservationStatusTest extends TestCase
{
    /**
     * The full transition matrix from PRD-001. True = allowed, false = forbidden.
     * Every pair not listed below is implicitly forbidden by the default false.
     *
     * @return array<string, array{0: ReservationStatus, 1: ReservationStatus, 2: bool}>
     */
    public static function transitionMatrix(): array
    {
        $allowed = [
            [ReservationStatus::New, ReservationStatus::InReview],
            [ReservationStatus::New, ReservationStatus::Declined],
            [ReservationStatus::InReview, ReservationStatus::Replied],
            [ReservationStatus::InReview, ReservationStatus::Declined],
            [ReservationStatus::Replied, ReservationStatus::Confirmed],
            [ReservationStatus::Replied, ReservationStatus::Cancelled],
            [ReservationStatus::Confirmed, ReservationStatus::Cancelled],
        ];

        $rows = [];
        foreach (ReservationStatus::cases() as $from) {
            foreach (ReservationStatus::cases() as $to) {
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

    /**
     * @dataProvider transitionMatrix
     */
    public function test_can_transition_to_matches_the_prd_matrix(ReservationStatus $from, ReservationStatus $to, bool $expected): void
    {
        $this->assertSame($expected, $from->canTransitionTo($to));
    }

    public function test_prd_disallowed_examples_are_forbidden(): void
    {
        $this->assertFalse(ReservationStatus::Confirmed->canTransitionTo(ReservationStatus::New));
    }

    public function test_self_transitions_are_forbidden(): void
    {
        foreach (ReservationStatus::cases() as $status) {
            $this->assertFalse(
                $status->canTransitionTo($status),
                "{$status->value} → {$status->value} must be forbidden (self-transition)"
            );
        }
    }

    public function test_declined_and_cancelled_are_terminal(): void
    {
        $this->assertSame([], ReservationStatus::Declined->allowedNextStates());
        $this->assertSame([], ReservationStatus::Cancelled->allowedNextStates());

        foreach (ReservationStatus::cases() as $target) {
            $this->assertFalse(ReservationStatus::Declined->canTransitionTo($target));
            $this->assertFalse(ReservationStatus::Cancelled->canTransitionTo($target));
        }
    }

    public function test_allowed_next_states_for_new_are_in_review_and_declined(): void
    {
        $this->assertEqualsCanonicalizing(
            [ReservationStatus::InReview, ReservationStatus::Declined],
            ReservationStatus::New->allowedNextStates()
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Enums\SendMode;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\AI\AutoSendDecider;
use App\Services\AI\AutoSendDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\NullLogger;
use Tests\TestCase;

class AutoSendDeciderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_mode_manual_when_restaurant_is_in_manual_mode(): void
    {
        $reply = $this->makeReplyOnRestaurantWithMode(SendMode::Manual);

        $decision = (new AutoSendDecider(new NullLogger))->decide($reply);

        $this->assertSame(AutoSendDecision::DECISION_MANUAL, $decision->decision);
        $this->assertSame('mode_manual', $decision->reason);
    }

    public function test_it_returns_mode_shadow_when_restaurant_is_in_shadow_mode(): void
    {
        $reply = $this->makeReplyOnRestaurantWithMode(SendMode::Shadow);

        $decision = (new AutoSendDecider(new NullLogger))->decide($reply);

        $this->assertSame(AutoSendDecision::DECISION_SHADOW, $decision->decision);
        $this->assertSame('mode_shadow', $decision->reason);
    }

    public function test_it_returns_mode_auto_when_restaurant_is_in_auto_mode_without_hard_gates(): void
    {
        $reply = $this->makeReplyOnRestaurantWithMode(SendMode::Auto);

        $decision = (new AutoSendDecider(new NullLogger))->decide($reply);

        $this->assertSame(AutoSendDecision::DECISION_AUTO_SEND, $decision->decision);
        $this->assertSame('mode_auto', $decision->reason);
    }

    public function test_it_falls_back_to_manual_when_parent_context_is_missing(): void
    {
        // Detached reply: forge a model with no persisted relations so
        // reservationRequest is null. The decider must never NPE on this
        // path — it should log + return manual.
        $reply = new ReservationReply;
        $reply->id = 0;

        $decision = (new AutoSendDecider(new NullLogger))->decide($reply);

        $this->assertSame(AutoSendDecision::DECISION_MANUAL, $decision->decision);
        $this->assertSame('missing_parent_context', $decision->reason);
    }

    private function makeReplyOnRestaurantWithMode(SendMode $mode): ReservationReply
    {
        $restaurant = Restaurant::factory()->create();
        $restaurant->forceFill(['send_mode' => $mode])->save();
        $request = ReservationRequest::factory()->create(['restaurant_id' => $restaurant->id]);

        return ReservationReply::factory()->create(['reservation_request_id' => $request->id]);
    }
}

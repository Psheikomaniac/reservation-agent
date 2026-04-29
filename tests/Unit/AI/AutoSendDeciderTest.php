<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\SendMode;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\AI\AutoSendDecider;
use App\Services\AI\AutoSendDecision;
use App\Services\AI\OpenAiReplyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_it_blocks_auto_send_when_needs_manual_review_is_true(): void
    {
        $reply = $this->makeReplyOnRestaurantWithMode(SendMode::Auto, requestOverrides: [
            'needs_manual_review' => true,
        ]);

        $decision = $this->decide($reply);

        $this->assertSame(AutoSendDecision::DECISION_MANUAL, $decision->decision);
        $this->assertSame(AutoSendDecider::REASON_NEEDS_MANUAL_REVIEW, $decision->reason);
    }

    public function test_it_blocks_auto_send_for_fallback_text(): void
    {
        $reply = $this->makeReplyOnRestaurantWithMode(SendMode::Auto, replyOverrides: [
            'body' => OpenAiReplyGenerator::FALLBACK_TEXT,
        ]);

        $decision = $this->decide($reply);

        $this->assertSame(AutoSendDecision::DECISION_MANUAL, $decision->decision);
        $this->assertSame(AutoSendDecider::REASON_FALLBACK_TEXT, $decision->reason);
    }

    public function test_it_blocks_auto_send_when_is_fallback_flag_is_true_regardless_of_body(): void
    {
        // Body is a normal-looking reply — only the flag distinguishes
        // it as a fallback. The decider must trust the persisted flag
        // ahead of the brittle string match.
        $reply = $this->makeReplyOnRestaurantWithMode(SendMode::Auto, replyOverrides: [
            'body' => 'Vielen Dank für Ihre Reservierungsanfrage. Gerne erwarten wir Sie.',
            'is_fallback' => true,
        ]);

        $decision = $this->decide($reply);

        $this->assertSame(AutoSendDecision::DECISION_MANUAL, $decision->decision);
        $this->assertSame(AutoSendDecider::REASON_FALLBACK_TEXT, $decision->reason);
    }

    public function test_it_blocks_auto_send_when_party_size_exceeds_limit(): void
    {
        $reply = $this->makeReplyOnRestaurantWithMode(
            SendMode::Auto,
            restaurantOverrides: ['auto_send_party_size_max' => 6],
            requestOverrides: ['party_size' => 7],
        );

        $decision = $this->decide($reply);

        $this->assertSame(AutoSendDecision::DECISION_MANUAL, $decision->decision);
        $this->assertSame(AutoSendDecider::REASON_PARTY_SIZE_OVER_LIMIT, $decision->reason);
    }

    public function test_it_blocks_auto_send_for_short_notice_requests(): void
    {
        Carbon::setTestNow('2026-04-30 18:00:00');

        $reply = $this->makeReplyOnRestaurantWithMode(
            SendMode::Auto,
            restaurantOverrides: ['auto_send_min_lead_time_minutes' => 90],
            requestOverrides: ['desired_at' => Carbon::parse('2026-04-30 19:00:00')], // 60 min away
        );

        $decision = $this->decide($reply);

        $this->assertSame(AutoSendDecision::DECISION_MANUAL, $decision->decision);
        $this->assertSame(AutoSendDecider::REASON_SHORT_NOTICE, $decision->reason);
    }

    public function test_it_blocks_auto_send_for_first_time_guests(): void
    {
        $reply = $this->makeReplyOnRestaurantWithMode(
            SendMode::Auto,
            requestOverrides: ['guest_email' => 'newcomer@example.com'],
            seedPriorConfirmed: false,
        );

        $decision = $this->decide($reply);

        $this->assertSame(AutoSendDecision::DECISION_MANUAL, $decision->decision);
        $this->assertSame(AutoSendDecider::REASON_FIRST_TIME_GUEST, $decision->reason);
    }

    public function test_it_blocks_auto_send_for_low_confidence_email_parses(): void
    {
        $reply = $this->makeReplyOnRestaurantWithMode(SendMode::Auto, requestOverrides: [
            'source' => ReservationSource::Email,
            'raw_payload' => ['confidence' => 0.7],
        ]);

        $decision = $this->decide($reply);

        $this->assertSame(AutoSendDecision::DECISION_MANUAL, $decision->decision);
        $this->assertSame(AutoSendDecider::REASON_LOW_CONFIDENCE_EMAIL, $decision->reason);
    }

    public function test_it_considers_a_guest_with_prior_confirmed_reservation_as_returning(): void
    {
        // Default helper already seeds a prior confirmed reservation for
        // the guest's email — that's exactly the scenario this test
        // documents. We still assert it explicitly.
        $reply = $this->makeReplyOnRestaurantWithMode(SendMode::Auto);

        $decision = $this->decide($reply);

        $this->assertSame(AutoSendDecision::DECISION_AUTO_SEND, $decision->decision);
        $this->assertSame('mode_auto', $decision->reason);
    }

    public function test_it_counts_a_guest_with_only_declined_prior_reservations_as_first_time(): void
    {
        // Decline doesn't build trust — operator declined for a reason.
        $restaurant = Restaurant::factory()->create();
        $restaurant->forceFill(['send_mode' => SendMode::Auto])->save();

        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'guest_email' => 'declined@example.com',
            'status' => ReservationStatus::Declined,
        ]);

        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'guest_email' => 'declined@example.com',
            'status' => ReservationStatus::New,
        ]);
        $reply = ReservationReply::factory()->create(['reservation_request_id' => $request->id]);

        $decision = $this->decide($reply);

        $this->assertSame(AutoSendDecision::DECISION_MANUAL, $decision->decision);
        $this->assertSame(AutoSendDecider::REASON_FIRST_TIME_GUEST, $decision->reason);
    }

    private function decide(ReservationReply $reply): AutoSendDecision
    {
        return (new AutoSendDecider(new NullLogger))->decide($reply);
    }

    /**
     * Build a reply on a configurable restaurant. Mode lives on the
     * restaurant, but each hard-gate test cares about specific request /
     * reply / restaurant fields, so they all flow through here as
     * structured overrides.
     *
     * By default the helper seeds a prior `confirmed` reservation for
     * the guest's email so the `first_time_guest` gate doesn't trip on
     * unrelated tests. Pass `seedPriorConfirmed: false` to test the
     * first-time-guest gate explicitly.
     *
     * @param  array<string, mixed>  $restaurantOverrides
     * @param  array<string, mixed>  $requestOverrides
     * @param  array<string, mixed>  $replyOverrides
     */
    private function makeReplyOnRestaurantWithMode(
        SendMode $mode,
        array $restaurantOverrides = [],
        array $requestOverrides = [],
        array $replyOverrides = [],
        bool $seedPriorConfirmed = true,
    ): ReservationReply {
        $restaurant = Restaurant::factory()->create();
        $restaurant->forceFill(['send_mode' => $mode] + $restaurantOverrides)->save();

        $request = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create($requestOverrides);

        if ($seedPriorConfirmed && $request->guest_email !== null) {
            ReservationRequest::factory()->forRestaurant($restaurant)->create([
                'guest_email' => $request->guest_email,
                'status' => ReservationStatus::Confirmed,
            ]);
        }

        return ReservationReply::factory()->create(
            ['reservation_request_id' => $request->id] + $replyOverrides
        );
    }
}

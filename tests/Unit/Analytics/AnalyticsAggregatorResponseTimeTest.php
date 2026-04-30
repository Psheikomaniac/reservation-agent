<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Enums\AnalyticsRange;
use App\Enums\ReservationReplyStatus;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Analytics\AnalyticsAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalyticsAggregatorResponseTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-04-30 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function aggregator(): AnalyticsAggregator
    {
        return $this->app->make(AnalyticsAggregator::class);
    }

    private function makeRequest(Restaurant $restaurant, Carbon $createdAt): ReservationRequest
    {
        return ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create(['created_at' => $createdAt]);
    }

    private function attachReply(
        ReservationRequest $request,
        ReservationReplyStatus $status,
        ?Carbon $sentAt,
    ): ReservationReply {
        return ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => $status,
            'sent_at' => $sentAt,
        ]);
    }

    public function test_it_computes_median_and_p90_response_time(): void
    {
        $restaurant = Restaurant::factory()->create();

        // Five requests, response deltas (in minutes): 5, 10, 30, 60, 240.
        // Sorted asc: 5, 10, 30, 60, 240.
        // Median (50th percentile, linear interpolation) = 30.
        // p90 → linear interpolation between index 3 (60) and index 4 (240)
        //       at fraction 0.6 → 60 + 0.6 * (240 - 60) = 168.
        $deltas = [5, 10, 30, 60, 240];

        foreach ($deltas as $minutes) {
            $createdAt = Carbon::now()->subDays(1);
            $request = $this->makeRequest($restaurant, $createdAt);
            $this->attachReply(
                $request,
                ReservationReplyStatus::Sent,
                $createdAt->copy()->addMinutes($minutes),
            );
        }

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        $this->assertSame(5, $snapshot->responseTime->sampleSize);
        $this->assertSame(30, $snapshot->responseTime->medianMinutes);
        $this->assertSame(168, $snapshot->responseTime->p90Minutes);
    }

    public function test_it_returns_null_when_no_replies_exist_in_range(): void
    {
        $restaurant = Restaurant::factory()->create();

        // Two requests, no replies attached.
        $this->makeRequest($restaurant, Carbon::now()->subDays(1));
        $this->makeRequest($restaurant, Carbon::now()->subDays(2));

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        $this->assertSame(0, $snapshot->responseTime->sampleSize);
        $this->assertNull($snapshot->responseTime->medianMinutes);
        $this->assertNull($snapshot->responseTime->p90Minutes);
    }

    public function test_it_ignores_replies_with_status_draft_or_shadow(): void
    {
        $restaurant = Restaurant::factory()->create();

        // Sent reply: must be counted (delta = 20 min).
        $sent = $this->makeRequest($restaurant, Carbon::now()->subDays(1));
        $this->attachReply(
            $sent,
            ReservationReplyStatus::Sent,
            $sent->created_at->copy()->addMinutes(20),
        );

        // Draft with sent_at set (irrealistic but defensive): must NOT count.
        $draft = $this->makeRequest($restaurant, Carbon::now()->subDays(1));
        $this->attachReply(
            $draft,
            ReservationReplyStatus::Draft,
            $draft->created_at->copy()->addMinutes(5),
        );

        // Shadow reply: must NOT count toward time-to-reply.
        $shadow = $this->makeRequest($restaurant, Carbon::now()->subDays(1));
        $this->attachReply(
            $shadow,
            ReservationReplyStatus::Shadow,
            $shadow->created_at->copy()->addMinutes(2),
        );

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        // Only the sent reply counts → sample size 1, both percentiles 20.
        $this->assertSame(1, $snapshot->responseTime->sampleSize);
        $this->assertSame(20, $snapshot->responseTime->medianMinutes);
        $this->assertSame(20, $snapshot->responseTime->p90Minutes);
    }

    public function test_it_uses_the_first_sent_reply_when_a_request_has_multiple(): void
    {
        $restaurant = Restaurant::factory()->create();

        $createdAt = Carbon::now()->subDays(1);
        $request = $this->makeRequest($restaurant, $createdAt);

        // First send (the one we should measure): +15 min.
        $this->attachReply(
            $request,
            ReservationReplyStatus::Sent,
            $createdAt->copy()->addMinutes(15),
        );

        // Second send (e.g. follow-up): +120 min — must not be picked.
        $this->attachReply(
            $request,
            ReservationReplyStatus::Sent,
            $createdAt->copy()->addMinutes(120),
        );

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        $this->assertSame(1, $snapshot->responseTime->sampleSize);
        $this->assertSame(15, $snapshot->responseTime->medianMinutes);
    }

    public function test_it_scopes_response_time_to_the_given_restaurant(): void
    {
        $own = Restaurant::factory()->create();
        $other = Restaurant::factory()->create();

        // Own: 100-minute response.
        $ownRequest = $this->makeRequest($own, Carbon::now()->subDays(1));
        $this->attachReply(
            $ownRequest,
            ReservationReplyStatus::Sent,
            $ownRequest->created_at->copy()->addMinutes(100),
        );

        // Other: 1-minute response — must not bleed into own snapshot.
        $otherRequest = $this->makeRequest($other, Carbon::now()->subDays(1));
        $this->attachReply(
            $otherRequest,
            ReservationReplyStatus::Sent,
            $otherRequest->created_at->copy()->addMinutes(1),
        );

        $snapshot = $this->aggregator()->aggregate($own, AnalyticsRange::Last7Days);

        $this->assertSame(1, $snapshot->responseTime->sampleSize);
        $this->assertSame(100, $snapshot->responseTime->medianMinutes);
    }
}

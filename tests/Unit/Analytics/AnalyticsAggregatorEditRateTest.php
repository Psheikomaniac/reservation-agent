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

class AnalyticsAggregatorEditRateTest extends TestCase
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

    private function makeReply(
        Restaurant $restaurant,
        string $body,
        ?string $originalBody,
        ReservationReplyStatus $status,
        Carbon $createdAt,
    ): ReservationReply {
        $request = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create(['created_at' => $createdAt]);

        $snapshot = $originalBody === null ? null : ['original_body' => $originalBody];

        return ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => $status,
            'body' => $body,
            'ai_prompt_snapshot' => $snapshot,
            'created_at' => $createdAt,
        ]);
    }

    public function test_it_computes_edit_rate_when_body_differs_from_original(): void
    {
        $restaurant = Restaurant::factory()->create();

        // 1 modified Sent reply.
        $this->makeReply(
            $restaurant,
            body: 'edited',
            originalBody: 'original',
            status: ReservationReplyStatus::Sent,
            createdAt: Carbon::now()->subDays(1),
        );
        // 1 unchanged Sent reply.
        $this->makeReply(
            $restaurant,
            body: 'verbatim',
            originalBody: 'verbatim',
            status: ReservationReplyStatus::Sent,
            createdAt: Carbon::now()->subDays(2),
        );
        // 2 unchanged Approved replies.
        $this->makeReply(
            $restaurant,
            body: 'a',
            originalBody: 'a',
            status: ReservationReplyStatus::Approved,
            createdAt: Carbon::now()->subDays(3),
        );
        $this->makeReply(
            $restaurant,
            body: 'b',
            originalBody: 'b',
            status: ReservationReplyStatus::Approved,
            createdAt: Carbon::now()->subDays(4),
        );

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        // 1 modified out of 4 → 0.25.
        $this->assertNotNull($snapshot->editRate);
        $this->assertEqualsWithDelta(0.25, $snapshot->editRate, 0.0001);
    }

    public function test_it_returns_null_edit_rate_when_no_comparable_replies(): void
    {
        $restaurant = Restaurant::factory()->create();

        // Drafts are not eligible for edit-rate (only Sent / Approved count).
        $this->makeReply(
            $restaurant,
            body: 'a',
            originalBody: 'a',
            status: ReservationReplyStatus::Draft,
            createdAt: Carbon::now()->subDays(1),
        );

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        $this->assertNull($snapshot->editRate);
    }

    public function test_it_treats_replies_without_original_body_snapshot_as_not_modified(): void
    {
        $restaurant = Restaurant::factory()->create();

        // Pre-PRD-008 reply without original_body in the snapshot — must
        // count toward total but never as modified (PRD-008 risk note).
        $this->makeReply(
            $restaurant,
            body: 'whatever',
            originalBody: null,
            status: ReservationReplyStatus::Sent,
            createdAt: Carbon::now()->subDays(1),
        );

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        // 0 modified out of 1 → 0.0.
        $this->assertNotNull($snapshot->editRate);
        $this->assertSame(0.0, $snapshot->editRate);
    }

    public function test_it_scopes_edit_rate_to_the_given_restaurant(): void
    {
        $own = Restaurant::factory()->create();
        $other = Restaurant::factory()->create();

        // Other restaurant has 5 modified replies — must not bleed in.
        for ($i = 0; $i < 5; $i++) {
            $this->makeReply(
                $other,
                body: 'edited',
                originalBody: 'original',
                status: ReservationReplyStatus::Sent,
                createdAt: Carbon::now()->subDays(1),
            );
        }

        // Own restaurant has 1 unchanged reply.
        $this->makeReply(
            $own,
            body: 'a',
            originalBody: 'a',
            status: ReservationReplyStatus::Sent,
            createdAt: Carbon::now()->subDays(1),
        );

        $snapshot = $this->aggregator()->aggregate($own, AnalyticsRange::Last7Days);

        $this->assertSame(0.0, $snapshot->editRate);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Enums\AnalyticsRange;
use App\Enums\ReservationReplyStatus;
use App\Enums\SendMode;
use App\Models\AutoSendAudit;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Analytics\AnalyticsAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalyticsAggregatorSendModeStatsTest extends TestCase
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
        SendMode $modeAtCreation,
        ReservationReplyStatus $status,
        ?bool $shadowWasModified = null,
        ?Carbon $shadowComparedAt = null,
        ?Carbon $createdAt = null,
    ): ReservationReply {
        $createdAt ??= Carbon::now()->subDays(1);

        $request = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create(['created_at' => $createdAt]);

        return ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => $status,
            'send_mode_at_creation' => $modeAtCreation,
            'shadow_was_modified' => $shadowWasModified ?? false,
            'shadow_compared_at' => $shadowComparedAt,
            'created_at' => $createdAt,
        ]);
    }

    private function makeAudit(
        Restaurant $restaurant,
        ReservationReply $reply,
        string $decision,
        string $reason,
        ?Carbon $createdAt = null,
    ): AutoSendAudit {
        return AutoSendAudit::create([
            'reservation_reply_id' => $reply->id,
            'restaurant_id' => $restaurant->id,
            'send_mode' => SendMode::Auto->value,
            'decision' => $decision,
            'reason' => $reason,
            'created_at' => $createdAt ?? Carbon::now()->subDays(1),
        ]);
    }

    public function test_it_returns_null_send_mode_stats_for_manual_mode_restaurants(): void
    {
        $restaurant = Restaurant::factory()->create(['send_mode' => SendMode::Manual]);

        $this->makeReply($restaurant, SendMode::Manual, ReservationReplyStatus::Sent);

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        $this->assertNull($snapshot->sendModeStats);
    }

    public function test_it_computes_shadow_takeover_rate(): void
    {
        $restaurant = Restaurant::factory()->create(['send_mode' => SendMode::Shadow]);

        // 4 shadow replies, 3 of them compared. 2 not modified, 1 modified.
        // takeoverRate = 2 / 3 ≈ 0.6667.
        $this->makeReply(
            $restaurant,
            SendMode::Shadow,
            ReservationReplyStatus::Shadow,
            shadowWasModified: false,
            shadowComparedAt: Carbon::now()->subHours(20),
        );
        $this->makeReply(
            $restaurant,
            SendMode::Shadow,
            ReservationReplyStatus::Shadow,
            shadowWasModified: false,
            shadowComparedAt: Carbon::now()->subHours(18),
        );
        $this->makeReply(
            $restaurant,
            SendMode::Shadow,
            ReservationReplyStatus::Shadow,
            shadowWasModified: true,
            shadowComparedAt: Carbon::now()->subHours(10),
        );
        // Fourth shadow reply not yet compared (operator still pending).
        $this->makeReply(
            $restaurant,
            SendMode::Shadow,
            ReservationReplyStatus::Shadow,
            shadowWasModified: false,
            shadowComparedAt: null,
        );

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        $this->assertNotNull($snapshot->sendModeStats);
        $this->assertSame(4, $snapshot->sendModeStats->shadow);
        $this->assertSame(3, $snapshot->sendModeStats->shadowComparedSampleSize);
        $this->assertNotNull($snapshot->sendModeStats->takeoverRate);
        $this->assertEqualsWithDelta(2 / 3, $snapshot->sendModeStats->takeoverRate, 0.0001);
    }

    public function test_it_returns_null_takeover_rate_when_no_shadow_replies_were_compared(): void
    {
        $restaurant = Restaurant::factory()->create(['send_mode' => SendMode::Shadow]);

        $this->makeReply(
            $restaurant,
            SendMode::Shadow,
            ReservationReplyStatus::Shadow,
            shadowComparedAt: null,
        );

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        $this->assertNotNull($snapshot->sendModeStats);
        $this->assertSame(0, $snapshot->sendModeStats->shadowComparedSampleSize);
        $this->assertNull($snapshot->sendModeStats->takeoverRate);
    }

    public function test_it_lists_top_3_hard_gate_reasons_in_auto_mode(): void
    {
        $restaurant = Restaurant::factory()->create(['send_mode' => SendMode::Auto]);

        // Reasons recorded as `manual` decisions in the audit log
        // (auto-mode hard-gate fallback path):
        //   short_notice           x4
        //   party_size_over_limit  x3
        //   first_time_guest       x2
        //   low_confidence_email   x1
        //   mode_manual            x5  → MUST be excluded
        $reasonCounts = [
            'short_notice' => 4,
            'party_size_over_limit' => 3,
            'first_time_guest' => 2,
            'low_confidence_email' => 1,
            'mode_manual' => 5,
        ];

        foreach ($reasonCounts as $reason => $count) {
            for ($i = 0; $i < $count; $i++) {
                $reply = $this->makeReply(
                    $restaurant,
                    SendMode::Auto,
                    ReservationReplyStatus::Draft,
                );
                $this->makeAudit(
                    $restaurant,
                    $reply,
                    AutoSendAudit::DECISION_MANUAL,
                    $reason,
                );
            }
        }

        // Five real auto-sends in the same window.
        for ($i = 0; $i < 5; $i++) {
            $reply = $this->makeReply(
                $restaurant,
                SendMode::Auto,
                ReservationReplyStatus::Sent,
            );
            $this->makeAudit(
                $restaurant,
                $reply,
                AutoSendAudit::DECISION_AUTO_SEND,
                'auto_send_passed',
            );
        }

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        $this->assertNotNull($snapshot->sendModeStats);
        $this->assertSame(5, $snapshot->sendModeStats->auto);

        $reasons = $snapshot->sendModeStats->topHardGateReasons;
        $this->assertCount(3, $reasons);
        $this->assertSame(
            ['short_notice', 'party_size_over_limit', 'first_time_guest'],
            array_column($reasons, 'reason'),
        );
        $this->assertSame([4, 3, 2], array_column($reasons, 'count'));
    }

    public function test_it_scopes_send_mode_stats_to_the_given_restaurant(): void
    {
        $own = Restaurant::factory()->create(['send_mode' => SendMode::Auto]);
        $other = Restaurant::factory()->create(['send_mode' => SendMode::Auto]);

        // Other restaurant: 10 hard-gate audits — must NOT show up.
        for ($i = 0; $i < 10; $i++) {
            $reply = $this->makeReply(
                $other,
                SendMode::Auto,
                ReservationReplyStatus::Draft,
            );
            $this->makeAudit(
                $other,
                $reply,
                AutoSendAudit::DECISION_MANUAL,
                'short_notice',
            );
        }

        // Own restaurant: 1 audit reason, 0 auto sends.
        $reply = $this->makeReply(
            $own,
            SendMode::Auto,
            ReservationReplyStatus::Draft,
        );
        $this->makeAudit(
            $own,
            $reply,
            AutoSendAudit::DECISION_MANUAL,
            'first_time_guest',
        );

        $snapshot = $this->aggregator()->aggregate($own, AnalyticsRange::Last7Days);

        $this->assertNotNull($snapshot->sendModeStats);
        $this->assertSame(0, $snapshot->sendModeStats->auto);
        $this->assertSame(
            [['reason' => 'first_time_guest', 'count' => 1]],
            $snapshot->sendModeStats->topHardGateReasons,
        );
    }
}

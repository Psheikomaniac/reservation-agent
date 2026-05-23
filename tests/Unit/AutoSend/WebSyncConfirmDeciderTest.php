<?php

declare(strict_types=1);

namespace Tests\Unit\AutoSend;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\SlotState;
use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Services\AutoSend\WebSyncConfirmDecider;
use App\Services\AutoSend\WebSyncConfirmDecision;
use App\Services\Availability\DTOs\SlotAvailabilityResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WebSyncConfirmDeciderTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze "now" inside the dinner window so lead-time and opening-hours
        // checks are deterministic. 2026-05-23 is a Saturday.
        Carbon::setTestNow('2026-05-23 18:00:00');

        $dinner = [['from' => '17:00', 'to' => '23:00']];
        $this->restaurant = Restaurant::factory()->create([
            'timezone' => 'UTC',
            'slot_buffer_minutes' => 90,
            'opening_hours' => [
                'mon' => $dinner, 'tue' => $dinner, 'wed' => $dinner, 'thu' => $dinner,
                'fri' => $dinner, 'sat' => $dinner, 'sun' => $dinner,
            ],
            'web_sync_confirm_enabled' => true,
            'auto_send_party_size_max' => 10,
            'auto_send_min_lead_time_minutes' => 90,
        ]);
        Table::factory()->for($this->restaurant)->create(['seats' => 8]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function decider(): WebSyncConfirmDecider
    {
        return app(WebSyncConfirmDecider::class);
    }

    private function reservation(string $desiredAtUtc, int $partySize = 2): ReservationRequest
    {
        return ReservationRequest::factory()->for($this->restaurant)->create([
            'source' => ReservationSource::WebForm,
            'status' => ReservationStatus::New,
            'desired_at' => CarbonImmutable::parse($desiredAtUtc, 'UTC'),
            'party_size' => $partySize,
        ]);
    }

    public function test_it_skips_when_global_kill_is_active(): void
    {
        config()->set('reservations.web_sync_confirm.kill', true);
        $request = $this->reservation('2026-06-15 19:00');

        $decision = $this->decider()->decide($request);

        $this->assertSame(WebSyncConfirmDecision::STATE_SKIP, $decision->state);
        $this->assertSame(WebSyncConfirmDecider::REASON_GLOBAL_KILL, $decision->reason);
    }

    public function test_it_skips_when_the_restaurant_toggle_is_off(): void
    {
        $this->restaurant->update(['web_sync_confirm_enabled' => false]);
        $request = $this->reservation('2026-06-15 19:00');

        $decision = $this->decider()->decide($request);

        $this->assertSame(WebSyncConfirmDecider::REASON_DISABLED, $decision->reason);
    }

    public function test_it_skips_when_the_slot_is_not_deterministic_free(): void
    {
        // Occupy the (single) table at the slot so it is full, not free.
        $busy = ReservationRequest::factory()->for($this->restaurant)->create([
            'status' => ReservationStatus::Confirmed,
            'desired_at' => CarbonImmutable::parse('2026-06-15 19:00', 'UTC'),
            'party_size' => 2,
        ]);
        ReservationTableAssignment::factory()
            ->for($busy, 'reservationRequest')
            ->for(Table::query()->firstOrFail())
            ->create();

        $request = $this->reservation('2026-06-15 19:00');

        $decision = $this->decider()->decide($request);

        $this->assertSame(WebSyncConfirmDecider::REASON_SLOT_NOT_FREE, $decision->reason);
    }

    public function test_it_skips_when_the_party_size_is_over_the_limit(): void
    {
        // A 12-seat table keeps the slot genuinely free for 11 (so the slot gate
        // passes), but 11 still exceeds the auto-send cap of 10.
        Table::factory()->for($this->restaurant)->create(['seats' => 12]);
        $request = $this->reservation('2026-06-15 19:00', partySize: 11);

        $decision = $this->decider()->decide($request);

        $this->assertSame(WebSyncConfirmDecider::REASON_PARTY_SIZE_OVER_LIMIT, $decision->reason);
    }

    public function test_it_skips_on_short_notice(): void
    {
        // 30 minutes out, inside the dinner window, but under the 90-min lead.
        $request = $this->reservation('2026-05-23 18:30');

        $decision = $this->decider()->decide($request);

        $this->assertSame(WebSyncConfirmDecider::REASON_SHORT_NOTICE, $decision->reason);
    }

    public function test_it_proceeds_when_all_gates_pass(): void
    {
        $request = $this->reservation('2026-06-15 19:00', partySize: 2);

        $decision = $this->decider()->decide($request);

        $this->assertTrue($decision->shouldProceed());
        $this->assertNull($decision->reason);
        $this->assertInstanceOf(SlotAvailabilityResult::class, $decision->slot);
        $this->assertSame(SlotState::Free, $decision->slot->state);
    }

    public function test_it_skips_when_the_slot_is_only_tight(): void
    {
        // setUp's 8-seat table is occupied and only a free 2-seat table remains:
        // 2/10 = 20% free (≤ 25%) → Tight, which must not proceed unattended.
        $eight = Table::query()->firstOrFail();
        Table::factory()->for($this->restaurant)->create(['seats' => 2]);
        $busy = ReservationRequest::factory()->for($this->restaurant)->create([
            'status' => ReservationStatus::Confirmed,
            'desired_at' => CarbonImmutable::parse('2026-06-15 19:00', 'UTC'),
            'party_size' => 2,
        ]);
        ReservationTableAssignment::factory()->for($busy, 'reservationRequest')->for($eight)->create();

        $decision = $this->decider()->decide($this->reservation('2026-06-15 19:00', partySize: 2));

        $this->assertSame(WebSyncConfirmDecider::REASON_SLOT_NOT_FREE, $decision->reason);
    }

    public function test_it_skips_when_desired_at_is_null(): void
    {
        $request = ReservationRequest::factory()->for($this->restaurant)->create([
            'source' => ReservationSource::WebForm,
            'status' => ReservationStatus::New,
            'desired_at' => null,
            'party_size' => 2,
        ]);

        $decision = $this->decider()->decide($request);

        $this->assertSame(WebSyncConfirmDecider::REASON_SLOT_NOT_FREE, $decision->reason);
    }

    public function test_it_skips_when_the_request_has_no_restaurant(): void
    {
        // An (unsaved) request pointing at a non-existent restaurant: the relation
        // resolves to null, so the decider logs and skips as disabled.
        $request = new ReservationRequest([
            'restaurant_id' => 999_999,
            'desired_at' => CarbonImmutable::parse('2026-06-15 19:00', 'UTC'),
            'party_size' => 2,
        ]);

        $decision = $this->decider()->decide($request);

        $this->assertSame(WebSyncConfirmDecider::REASON_DISABLED, $decision->reason);
    }

    public function test_it_proceeds_at_exactly_the_minimum_lead_time(): void
    {
        // now is frozen at 18:00; 19:30 is exactly the 90-min lead (not < 90).
        $decision = $this->decider()->decide($this->reservation('2026-05-23 19:30', partySize: 2));

        $this->assertTrue($decision->shouldProceed());
    }
}

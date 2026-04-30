<?php

declare(strict_types=1);

namespace Tests\Feature\AutoSend;

use App\Enums\ReservationReplyStatus;
use App\Enums\SendMode;
use App\Enums\UserRole;
use App\Models\AutoSendAudit;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KillswitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resets_mode_to_manual_on_killswitch(): void
    {
        $restaurant = $this->makeRestaurantWithMode(SendMode::Auto);
        $owner = $this->makeOwner($restaurant);

        $response = $this->actingAs($owner)
            ->post(route('restaurants.send-mode.killswitch', $restaurant));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $restaurant->refresh();
        $this->assertSame(SendMode::Manual, $restaurant->send_mode);
        $this->assertNotNull($restaurant->send_mode_changed_at);
        $this->assertSame($owner->id, $restaurant->send_mode_changed_by);
    }

    public function test_it_cancels_all_scheduled_auto_sends_within_killswitch_transaction(): void
    {
        $restaurant = $this->makeRestaurantWithMode(SendMode::Auto);
        $owner = $this->makeOwner($restaurant);

        $scheduled = $this->scheduledReply($restaurant);
        $alsoScheduled = $this->scheduledReply($restaurant);

        // Replies in unrelated states must not be touched.
        $draft = ReservationReply::factory()->create([
            'reservation_request_id' => ReservationRequest::factory()->forRestaurant($restaurant)->create()->id,
            'status' => ReservationReplyStatus::Draft,
        ]);

        $this->actingAs($owner)
            ->post(route('restaurants.send-mode.killswitch', $restaurant))
            ->assertRedirect();

        $this->assertSame(ReservationReplyStatus::CancelledAuto, $scheduled->fresh()->status);
        $this->assertSame(ReservationReplyStatus::CancelledAuto, $alsoScheduled->fresh()->status);
        $this->assertSame(ReservationReplyStatus::Draft, $draft->fresh()->status, 'Drafts must not be cancelled.');
    }

    public function test_it_writes_audit_entry_for_each_cancelled_reply(): void
    {
        $restaurant = $this->makeRestaurantWithMode(SendMode::Auto);
        $owner = $this->makeOwner($restaurant);

        $first = $this->scheduledReply($restaurant);
        $second = $this->scheduledReply($restaurant);

        $this->actingAs($owner)
            ->post(route('restaurants.send-mode.killswitch', $restaurant))
            ->assertRedirect();

        foreach ([$first, $second] as $reply) {
            $this->assertDatabaseHas('auto_send_audits', [
                'reservation_reply_id' => $reply->id,
                'restaurant_id' => $restaurant->id,
                'decision' => AutoSendAudit::DECISION_CANCELLED_AUTO,
                'reason' => 'killswitch',
                'triggered_by_user_id' => $owner->id,
            ]);
        }

        $this->assertSame(2, AutoSendAudit::query()->count());
    }

    public function test_it_works_when_active_mode_is_shadow(): void
    {
        $restaurant = $this->makeRestaurantWithMode(SendMode::Shadow);
        $owner = $this->makeOwner($restaurant);

        $this->actingAs($owner)
            ->post(route('restaurants.send-mode.killswitch', $restaurant))
            ->assertRedirect();

        $this->assertSame(SendMode::Manual, $restaurant->fresh()->send_mode);
    }

    public function test_it_forbids_killswitch_for_staff_users(): void
    {
        $restaurant = $this->makeRestaurantWithMode(SendMode::Auto);
        $staff = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'role' => UserRole::Staff,
        ]);

        $scheduled = $this->scheduledReply($restaurant);

        $this->actingAs($staff)
            ->post(route('restaurants.send-mode.killswitch', $restaurant))
            ->assertForbidden();

        $this->assertSame(SendMode::Auto, $restaurant->fresh()->send_mode);
        $this->assertSame(ReservationReplyStatus::ScheduledAutoSend, $scheduled->fresh()->status);
        $this->assertSame(0, AutoSendAudit::query()->count());
    }

    public function test_it_forbids_killswitch_from_owner_of_a_different_restaurant(): void
    {
        $targetRestaurant = $this->makeRestaurantWithMode(SendMode::Auto);
        $otherRestaurant = $this->makeRestaurantWithMode(SendMode::Manual);
        $foreignOwner = $this->makeOwner($otherRestaurant);

        $this->actingAs($foreignOwner)
            ->post(route('restaurants.send-mode.killswitch', $targetRestaurant))
            ->assertForbidden();

        $this->assertSame(SendMode::Auto, $targetRestaurant->fresh()->send_mode);
    }

    private function makeRestaurantWithMode(SendMode $mode): Restaurant
    {
        $restaurant = Restaurant::factory()->create();
        $restaurant->forceFill(['send_mode' => $mode])->save();

        return $restaurant;
    }

    private function makeOwner(Restaurant $restaurant): User
    {
        return User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'role' => UserRole::Owner,
        ]);
    }

    private function scheduledReply(Restaurant $restaurant): ReservationReply
    {
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create();

        return ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::ScheduledAutoSend,
        ]);
    }
}

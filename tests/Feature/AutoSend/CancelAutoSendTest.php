<?php

declare(strict_types=1);

namespace Tests\Feature\AutoSend;

use App\Enums\ReservationReplyStatus;
use App\Enums\UserRole;
use App\Models\AutoSendAudit;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelAutoSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_cancels_scheduled_send_when_owner_clicks_cancel(): void
    {
        $reply = $this->scheduledReply();
        $owner = User::factory()->create([
            'restaurant_id' => $reply->reservationRequest->restaurant_id,
            'role' => UserRole::Owner,
        ]);

        $this->actingAs($owner)
            ->post(route('reservation-replies.cancel-auto-send', $reply))
            ->assertRedirect()
            ->assertSessionHas('success');

        $reply->refresh();
        $this->assertSame(ReservationReplyStatus::CancelledAuto, $reply->status);

        $this->assertDatabaseHas('auto_send_audits', [
            'reservation_reply_id' => $reply->id,
            'decision' => AutoSendAudit::DECISION_CANCELLED_AUTO,
            'reason' => 'cancelled_by_owner',
            'triggered_by_user_id' => $owner->id,
        ]);
    }

    public function test_it_no_ops_when_reply_is_not_in_the_cancel_window(): void
    {
        $request = ReservationRequest::factory()->forRestaurant(Restaurant::factory()->create())->create();
        $reply = ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::Sent,
        ]);

        $owner = User::factory()->create([
            'restaurant_id' => $request->restaurant_id,
            'role' => UserRole::Owner,
        ]);

        $this->actingAs($owner)
            ->post(route('reservation-replies.cancel-auto-send', $reply))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(ReservationReplyStatus::Sent, $reply->fresh()->status);
        $this->assertSame(0, AutoSendAudit::query()->count());
    }

    public function test_it_blocks_a_user_from_cancelling_a_foreign_tenants_reply(): void
    {
        $reply = $this->scheduledReply();

        $foreignUser = User::factory()->create([
            'restaurant_id' => Restaurant::factory()->create()->id,
            'role' => UserRole::Owner,
        ]);

        // The global RestaurantScope on ReservationReply hides cross-tenant
        // rows entirely, so route-model binding 404s before the policy gets
        // a chance to 403. Either response means "not your reply" — both
        // protect the cancel-window from cross-tenant abuse.
        $this->actingAs($foreignUser)
            ->post(route('reservation-replies.cancel-auto-send', $reply))
            ->assertNotFound();

        $this->assertSame(ReservationReplyStatus::ScheduledAutoSend, $reply->fresh()->status);
    }

    private function scheduledReply(): ReservationReply
    {
        $request = ReservationRequest::factory()
            ->forRestaurant(Restaurant::factory()->create())
            ->create();

        return ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::ScheduledAutoSend,
            'auto_send_scheduled_for' => now()->addSeconds(60),
        ]);
    }
}

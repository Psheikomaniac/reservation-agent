<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Enums\ReservationReplyStatus;
use App\Jobs\SendReservationReplyJob;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApproveReservationReplyTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserAndDraft(): array
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create();
        $reply = ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::Draft,
            'body' => 'Original AI draft.',
        ]);

        return [$user, $reply];
    }

    public function test_approving_a_draft_dispatches_the_send_job_and_transitions_status(): void
    {
        Queue::fake();
        [$user, $reply] = $this->makeUserAndDraft();

        $this->actingAs($user)
            ->post(route('reservation-replies.approve', $reply))
            ->assertRedirect();

        Queue::assertPushed(SendReservationReplyJob::class, fn (SendReservationReplyJob $job): bool => $job->reservationReplyId === $reply->id);

        $reply->refresh();
        $this->assertSame(ReservationReplyStatus::Approved, $reply->status);
        $this->assertSame($user->id, $reply->approved_by);
        $this->assertNotNull($reply->approved_at);
    }

    public function test_an_edited_body_overwrites_the_draft_before_dispatch(): void
    {
        Queue::fake();
        [$user, $reply] = $this->makeUserAndDraft();

        $this->actingAs($user)
            ->post(route('reservation-replies.approve', $reply), [
                'body' => 'Bearbeiteter Text vom Gastronom.',
            ])
            ->assertRedirect();

        $this->assertSame('Bearbeiteter Text vom Gastronom.', $reply->fresh()->body);
        // The dispatched job will read this body when sending the mail.
        Queue::assertPushed(SendReservationReplyJob::class);
    }

    public function test_approving_without_body_keeps_the_original_draft(): void
    {
        Queue::fake();
        [$user, $reply] = $this->makeUserAndDraft();

        $this->actingAs($user)
            ->post(route('reservation-replies.approve', $reply))
            ->assertRedirect();

        $this->assertSame('Original AI draft.', $reply->fresh()->body);
    }

    public function test_approving_an_already_sent_reply_is_a_no_op(): void
    {
        Queue::fake();
        [$user, $reply] = $this->makeUserAndDraft();
        $reply->forceFill(['status' => ReservationReplyStatus::Sent, 'sent_at' => now()])->save();

        $this->actingAs($user)
            ->post(route('reservation-replies.approve', $reply))
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
        $this->assertSame(ReservationReplyStatus::Sent, $reply->fresh()->status);
    }

    public function test_approving_an_already_approved_reply_is_a_no_op(): void
    {
        Queue::fake();
        [$user, $reply] = $this->makeUserAndDraft();
        $reply->forceFill(['status' => ReservationReplyStatus::Approved, 'approved_at' => now()])->save();

        $this->actingAs($user)
            ->post(route('reservation-replies.approve', $reply))
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_users_cannot_approve_replies_of_other_restaurants(): void
    {
        Queue::fake();
        [, $reply] = $this->makeUserAndDraft();
        $otherRestaurant = Restaurant::factory()->create();
        $intruder = User::factory()->create(['restaurant_id' => $otherRestaurant->id]);

        // RestaurantScope hides replies of other restaurants from route
        // model binding, so the intruder sees a 404 — not a 403.
        // Either response keeps the reply private; 404 is the project's
        // tenant-isolation contract via the global scope.
        $this->actingAs($intruder)
            ->post(route('reservation-replies.approve', $reply))
            ->assertNotFound();

        Queue::assertNothingPushed();
        $this->assertSame(ReservationReplyStatus::Draft, $reply->fresh()->status);
    }

    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        Queue::fake();
        [, $reply] = $this->makeUserAndDraft();

        $this->post(route('reservation-replies.approve', $reply))
            ->assertRedirect(route('login'));

        Queue::assertNothingPushed();
    }

    public function test_body_field_validates_max_length(): void
    {
        Queue::fake();
        [$user, $reply] = $this->makeUserAndDraft();

        $this->actingAs($user)
            ->post(route('reservation-replies.approve', $reply), [
                'body' => str_repeat('x', 4001),
            ])
            ->assertSessionHasErrors('body');

        Queue::assertNothingPushed();
        $this->assertSame(ReservationReplyStatus::Draft, $reply->fresh()->status);
    }
}

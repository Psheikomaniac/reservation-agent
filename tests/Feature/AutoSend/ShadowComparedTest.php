<?php

declare(strict_types=1);

namespace Tests\Feature\AutoSend;

use App\Enums\ReservationReplyStatus;
use App\Enums\SendMode;
use App\Enums\UserRole;
use App\Jobs\SendReservationReplyJob;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShadowComparedTest extends TestCase
{
    use RefreshDatabase;

    private function shadowReplySetup(): array
    {
        $restaurant = Restaurant::factory()->create(['send_mode' => SendMode::Shadow]);
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create();
        $reply = ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::Shadow,
            'send_mode_at_creation' => SendMode::Shadow,
            'body' => 'Schatten-Vorschlag.',
            'shadow_compared_at' => null,
            'shadow_was_modified' => false,
        ]);

        $owner = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'role' => UserRole::Owner,
        ]);

        return [$owner, $reply];
    }

    public function test_it_marks_shadow_compared_on_first_call(): void
    {
        Carbon::setTestNow('2026-04-30 12:00:00');
        [$owner, $reply] = $this->shadowReplySetup();

        $this->actingAs($owner)
            ->post(route('reservation-replies.mark-shadow-compared', $reply))
            ->assertRedirect();

        $reply->refresh();
        $this->assertNotNull($reply->shadow_compared_at);
        $this->assertSame('2026-04-30 12:00:00', $reply->shadow_compared_at->toDateTimeString());
    }

    public function test_it_does_not_overwrite_an_existing_shadow_compared_timestamp(): void
    {
        Carbon::setTestNow('2026-04-30 12:00:00');
        [$owner, $reply] = $this->shadowReplySetup();
        $reply->forceFill(['shadow_compared_at' => Carbon::now()->subHour()])->save();
        $original = $reply->fresh()->shadow_compared_at;

        $this->actingAs($owner)
            ->post(route('reservation-replies.mark-shadow-compared', $reply))
            ->assertRedirect();

        $this->assertEquals($original, $reply->fresh()->shadow_compared_at);
    }

    public function test_it_no_ops_when_reply_is_no_longer_in_shadow_state(): void
    {
        [$owner, $reply] = $this->shadowReplySetup();
        $reply->forceFill(['status' => ReservationReplyStatus::Approved])->save();

        $this->actingAs($owner)
            ->post(route('reservation-replies.mark-shadow-compared', $reply))
            ->assertRedirect();

        $this->assertNull($reply->fresh()->shadow_compared_at);
    }

    public function test_cross_tenant_user_cannot_mark_shadow_compared(): void
    {
        [$_, $reply] = $this->shadowReplySetup();
        $intruder = User::factory()->create([
            'restaurant_id' => Restaurant::factory()->create()->id,
            'role' => UserRole::Owner,
        ]);

        // The global RestaurantScope hides the reply from a foreign
        // tenant before the policy even runs — route-model-binding
        // returns 404, which is what the rest of the codebase
        // (cancel-auto-send tests) also expects.
        $this->actingAs($intruder)
            ->post(route('reservation-replies.mark-shadow-compared', $reply))
            ->assertNotFound();

        $this->assertNull($reply->fresh()->shadow_compared_at);
    }

    public function test_shadow_promote_via_approve_records_unmodified_takeover(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-04-30 12:00:00');
        [$owner, $reply] = $this->shadowReplySetup();

        $this->actingAs($owner)
            ->post(route('reservation-replies.approve', $reply))
            ->assertRedirect();

        $reply->refresh();
        $this->assertSame(ReservationReplyStatus::Approved, $reply->status);
        $this->assertFalse($reply->shadow_was_modified);
        $this->assertNotNull($reply->shadow_compared_at);

        Queue::assertPushed(SendReservationReplyJob::class);
    }

    public function test_shadow_promote_via_approve_records_a_modified_takeover(): void
    {
        Queue::fake();
        [$owner, $reply] = $this->shadowReplySetup();

        $this->actingAs($owner)
            ->post(route('reservation-replies.approve', $reply), [
                'body' => 'Operator-überarbeitete Antwort.',
            ])
            ->assertRedirect();

        $reply->refresh();
        $this->assertSame(ReservationReplyStatus::Approved, $reply->status);
        $this->assertTrue($reply->shadow_was_modified);
        $this->assertSame('Operator-überarbeitete Antwort.', $reply->body);
    }
}

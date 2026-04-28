<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationReplyStatus;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the contract surface that the dashboard drawer (issue #75)
 * relies on: every selected reservation that has an AI draft reply
 * carries `latest_reply` with the operator-visible fields, AND the
 * internal `ai_prompt_snapshot` is NEVER exposed to the browser.
 */
class DashboardDrawerAiTest extends TestCase
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
            'ai_prompt_snapshot' => ['restaurant' => ['name' => 'X', 'tonality' => 'casual']],
        ]);

        return [$user, $request, $reply];
    }

    public function test_selected_request_carries_latest_reply_summary(): void
    {
        [$user, $request, $reply] = $this->makeUserAndDraft();

        $response = $this->actingAs($user)->get(route('dashboard', ['selected' => $request->id]));

        $response->assertOk()
            ->assertInertia(function ($page) use ($reply) {
                $page->where('selectedRequest.latest_reply.id', $reply->id)
                    ->where('selectedRequest.latest_reply.status', 'draft')
                    ->where('selectedRequest.latest_reply.body', 'Original AI draft.')
                    ->where('selectedRequest.latest_reply.error_message', null)
                    ->where('selectedRequest.latest_reply.sent_at', null);
            });
    }

    public function test_ai_prompt_snapshot_is_not_exposed_to_the_browser(): void
    {
        [$user, $request] = $this->makeUserAndDraft();

        $this->actingAs($user)
            ->get(route('dashboard', ['selected' => $request->id]))
            ->assertInertia(function ($page) {
                $page->missing('selectedRequest.latest_reply.ai_prompt_snapshot');
            });
    }

    public function test_selected_request_without_a_reply_has_null_latest_reply(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create();

        $this->actingAs($user)
            ->get(route('dashboard', ['selected' => $request->id]))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->where('selectedRequest.latest_reply', null);
            });
    }
}

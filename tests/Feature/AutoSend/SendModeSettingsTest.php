<?php

declare(strict_types=1);

namespace Tests\Feature\AutoSend;

use App\Enums\ReservationReplyStatus;
use App\Enums\SendMode;
use App\Enums\UserRole;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SendModeSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_owner_to_switch_from_manual_to_shadow(): void
    {
        $restaurant = $this->makeRestaurantWithMode(SendMode::Manual);
        $owner = $this->makeOwner($restaurant);

        $this->actingAs($owner)
            ->patch(route('settings.send-mode.update'), [
                'send_mode' => SendMode::Shadow->value,
                'auto_send_party_size_max' => 10,
                'auto_send_min_lead_time_minutes' => 90,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $restaurant->refresh();
        $this->assertSame(SendMode::Shadow, $restaurant->send_mode);
        $this->assertNotNull($restaurant->send_mode_changed_at);
        $this->assertSame($owner->id, $restaurant->send_mode_changed_by);
    }

    public function test_it_shows_takeover_stats_on_settings_page_when_in_shadow_mode(): void
    {
        $restaurant = $this->makeRestaurantWithMode(SendMode::Shadow);
        $owner = $this->makeOwner($restaurant);

        // 4 shadow replies in the window: 3 taken over verbatim, 1 modified.
        $this->seedShadowReplies($restaurant, takenOver: 3, modified: 1);

        $this->actingAs($owner)
            ->get(route('settings.send-mode.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/SendMode')
                ->where('sendMode', SendMode::Shadow->value)
                ->where('shadowStats.total', 4)
                ->where('shadowStats.takenOver', 3)
                ->where('shadowStats.takeoverRate', 0.75)
                ->where('shadowStats.hasData', true)
            );
    }

    public function test_it_shows_no_data_state_when_no_shadow_replies_exist(): void
    {
        $restaurant = $this->makeRestaurantWithMode(SendMode::Shadow);
        $owner = $this->makeOwner($restaurant);

        $this->actingAs($owner)
            ->get(route('settings.send-mode.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shadowStats.total', 0)
                ->where('shadowStats.hasData', false)
                ->where('shadowStats.takeoverRate', null)
            );
    }

    public function test_it_persists_party_size_limit_and_min_lead_time_changes(): void
    {
        $restaurant = $this->makeRestaurantWithMode(SendMode::Auto);
        $owner = $this->makeOwner($restaurant);

        $this->actingAs($owner)
            ->patch(route('settings.send-mode.update'), [
                'send_mode' => SendMode::Auto->value,
                'auto_send_party_size_max' => 6,
                'auto_send_min_lead_time_minutes' => 180,
            ])
            ->assertRedirect();

        $restaurant->refresh();
        $this->assertSame(6, $restaurant->auto_send_party_size_max);
        $this->assertSame(180, $restaurant->auto_send_min_lead_time_minutes);
    }

    public function test_it_does_not_touch_send_mode_changed_when_only_thresholds_change(): void
    {
        $restaurant = $this->makeRestaurantWithMode(SendMode::Auto);
        $restaurant->forceFill([
            'send_mode_changed_at' => now()->subDays(7),
            'send_mode_changed_by' => null,
        ])->save();
        $oldChangedAt = $restaurant->send_mode_changed_at->toIso8601String();
        $owner = $this->makeOwner($restaurant);

        $this->actingAs($owner)
            ->patch(route('settings.send-mode.update'), [
                'send_mode' => SendMode::Auto->value,
                'auto_send_party_size_max' => 7,
                'auto_send_min_lead_time_minutes' => 120,
            ])
            ->assertRedirect();

        $this->assertSame($oldChangedAt, $restaurant->fresh()->send_mode_changed_at->toIso8601String());
    }

    public function test_it_forbids_staff_from_changing_send_mode(): void
    {
        $restaurant = $this->makeRestaurantWithMode(SendMode::Manual);
        $staff = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($staff)
            ->patch(route('settings.send-mode.update'), [
                'send_mode' => SendMode::Auto->value,
                'auto_send_party_size_max' => 10,
                'auto_send_min_lead_time_minutes' => 90,
            ])
            ->assertForbidden();

        $this->assertSame(SendMode::Manual, $restaurant->fresh()->send_mode);
    }

    public function test_it_forbids_staff_from_viewing_send_mode_settings(): void
    {
        $restaurant = $this->makeRestaurantWithMode(SendMode::Manual);
        $staff = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($staff)
            ->get(route('settings.send-mode.edit'))
            ->assertForbidden();
    }

    public function test_it_validates_send_mode_value(): void
    {
        $restaurant = $this->makeRestaurantWithMode(SendMode::Manual);
        $owner = $this->makeOwner($restaurant);

        $this->actingAs($owner)
            ->patch(route('settings.send-mode.update'), [
                'send_mode' => 'rocket-launch',
                'auto_send_party_size_max' => 10,
                'auto_send_min_lead_time_minutes' => 90,
            ])
            ->assertSessionHasErrors('send_mode');
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

    private function seedShadowReplies(Restaurant $restaurant, int $takenOver, int $modified): void
    {
        for ($i = 0; $i < $takenOver; $i++) {
            $request = ReservationRequest::factory()->forRestaurant($restaurant)->create();
            ReservationReply::factory()->create([
                'reservation_request_id' => $request->id,
                'status' => ReservationReplyStatus::Shadow,
                'send_mode_at_creation' => SendMode::Shadow->value,
                'shadow_compared_at' => now()->subDays(2),
                'shadow_was_modified' => false,
            ]);
        }

        for ($i = 0; $i < $modified; $i++) {
            $request = ReservationRequest::factory()->forRestaurant($restaurant)->create();
            ReservationReply::factory()->create([
                'reservation_request_id' => $request->id,
                'status' => ReservationReplyStatus::Shadow,
                'send_mode_at_creation' => SendMode::Shadow->value,
                'shadow_compared_at' => now()->subDays(2),
                'shadow_was_modified' => true,
            ]);
        }
    }
}

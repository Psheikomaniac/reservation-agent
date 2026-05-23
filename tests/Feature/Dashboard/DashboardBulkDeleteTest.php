<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\UserRole;
use App\Models\GdprAudit;
use App\Models\ReservationMessage;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = Restaurant::factory()->create();
    }

    private function owner(): User
    {
        return User::factory()->create(['restaurant_id' => $this->restaurant->id, 'role' => UserRole::Owner]);
    }

    private function staff(): User
    {
        return User::factory()->create(['restaurant_id' => $this->restaurant->id, 'role' => UserRole::Staff]);
    }

    public function test_owner_can_bulk_delete_email_matches_with_related_records(): void
    {
        $match = ReservationRequest::factory()->for($this->restaurant)->create(['guest_email' => 'gast@gmail.com']);
        ReservationReply::factory()->create(['reservation_request_id' => $match->id]);
        ReservationMessage::factory()->create(['reservation_request_id' => $match->id]);
        $table = Table::factory()->for($this->restaurant)->create();
        ReservationTableAssignment::factory()->for($match, 'reservationRequest')->for($table)->create();

        $other = ReservationRequest::factory()->for($this->restaurant)->create(['guest_email' => 'someone-else@gmail.com']);

        $this->actingAs($this->owner())
            ->post(route('reservations.bulk-delete'), ['email' => 'gast@gmail.com'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('reservation_requests', ['id' => $match->id]);
        $this->assertDatabaseMissing('reservation_replies', ['reservation_request_id' => $match->id]);
        $this->assertDatabaseMissing('reservation_messages', ['reservation_request_id' => $match->id]);
        $this->assertDatabaseMissing('reservation_table_assignments', ['reservation_request_id' => $match->id]);

        // A non-matching email is untouched.
        $this->assertDatabaseHas('reservation_requests', ['id' => $other->id]);
    }

    public function test_match_is_on_exact_email_not_a_substring(): void
    {
        $exact = ReservationRequest::factory()->for($this->restaurant)->create(['guest_email' => 'anna@gmail.com']);
        $similar = ReservationRequest::factory()->for($this->restaurant)->create(['guest_email' => 'anna@gmail.community.org']);

        $this->actingAs($this->owner())
            ->post(route('reservations.bulk-delete'), ['email' => 'anna@gmail.com'])
            ->assertRedirect();

        $this->assertDatabaseMissing('reservation_requests', ['id' => $exact->id]);
        $this->assertDatabaseHas('reservation_requests', ['id' => $similar->id]);
    }

    public function test_service_user_cannot_bulk_delete(): void
    {
        $reservation = ReservationRequest::factory()->for($this->restaurant)->create(['guest_email' => 'gast@gmail.com']);

        $this->actingAs($this->staff())
            ->post(route('reservations.bulk-delete'), ['email' => 'gast@gmail.com'])
            ->assertForbidden();

        $this->assertDatabaseHas('reservation_requests', ['id' => $reservation->id]);
        $this->assertSame(0, GdprAudit::query()->count());
    }

    public function test_bulk_delete_writes_an_owner_audit_entry_without_pii(): void
    {
        ReservationRequest::factory()->for($this->restaurant)->create(['guest_email' => 'gast@gmail.com']);

        $this->actingAs($this->owner())
            ->post(route('reservations.bulk-delete'), ['email' => 'gast@gmail.com'])
            ->assertRedirect();

        $audit = GdprAudit::query()->sole();
        $this->assertSame(GdprAudit::ACTION_OWNER_BULK_DELETE, $audit->action);
        $this->assertSame($this->restaurant->id, $audit->restaurant_id);
        $this->assertStringNotContainsString('gast@gmail.com', json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_bulk_delete_does_not_cross_the_tenant_boundary(): void
    {
        $otherRestaurant = Restaurant::factory()->create();
        $foreign = ReservationRequest::factory()->for($otherRestaurant)->create(['guest_email' => 'gast@gmail.com']);
        $own = ReservationRequest::factory()->for($this->restaurant)->create(['guest_email' => 'gast@gmail.com']);

        $this->actingAs($this->owner())
            ->post(route('reservations.bulk-delete'), ['email' => 'gast@gmail.com'])
            ->assertRedirect();

        // Same email in another restaurant is never touched.
        $this->assertDatabaseHas('reservation_requests', ['id' => $foreign->id]);
        $this->assertDatabaseMissing('reservation_requests', ['id' => $own->id]);

        // The audit is scoped to the acting owner's restaurant.
        $audit = GdprAudit::query()->sole();
        $this->assertSame($this->restaurant->id, $audit->restaurant_id);
    }

    public function test_email_is_required(): void
    {
        $this->actingAs($this->owner())
            ->from('/dashboard')
            ->post(route('reservations.bulk-delete'), [])
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors('email');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Tables;

use App\Enums\UserRole;
use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TableCrudTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Restaurant $restaurant): User
    {
        return User::factory()->forRestaurant($restaurant)->create(['role' => UserRole::Owner]);
    }

    private function staff(Restaurant $restaurant): User
    {
        return User::factory()->forRestaurant($restaurant)->create(['role' => UserRole::Staff]);
    }

    public function test_guests_are_redirected_from_the_table_index(): void
    {
        $this->get(route('tables.index'))->assertRedirect('/login');
    }

    public function test_index_lists_only_own_restaurant_tables(): void
    {
        $home = Restaurant::factory()->create();
        $foreign = Restaurant::factory()->create();
        Table::factory()->for($home)->count(2)->create();
        Table::factory()->for($foreign)->create();

        $this->actingAs($this->owner($home))
            ->get(route('tables.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tables', false)
                ->has('tables.data', 2)
            );
    }

    public function test_index_includes_inactive_tables_so_they_can_be_managed(): void
    {
        $home = Restaurant::factory()->create();
        Table::factory()->for($home)->create();
        Table::factory()->for($home)->inactive()->create();

        $this->actingAs($this->owner($home))
            ->get(route('tables.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tables', false)
                ->has('tables.data', 2)
            );
    }

    public function test_staff_may_view_the_table_index(): void
    {
        $home = Restaurant::factory()->create();
        Table::factory()->for($home)->create();

        $this->actingAs($this->staff($home))
            ->get(route('tables.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tables', false));
    }

    public function test_owner_can_create_a_table(): void
    {
        $home = Restaurant::factory()->create();

        $response = $this->actingAs($this->owner($home))->post(route('tables.store'), [
            'label' => 'Tisch 7',
            'seats' => 4,
            'room_tag' => 'Innen',
            'sort_order' => 1,
            'active' => true,
            'combinable_with' => null,
        ]);

        $response->assertRedirect(route('tables.index'));
        $this->assertDatabaseHas('tables', [
            'restaurant_id' => $home->id,
            'label' => 'Tisch 7',
            'seats' => 4,
            'room_tag' => 'Innen',
        ]);
    }

    public function test_store_ignores_a_client_supplied_restaurant_id(): void
    {
        $home = Restaurant::factory()->create();
        $foreign = Restaurant::factory()->create();

        $this->actingAs($this->owner($home))->post(route('tables.store'), [
            'restaurant_id' => $foreign->id,
            'label' => 'Tisch 1',
            'seats' => 2,
        ]);

        $this->assertDatabaseHas('tables', ['label' => 'Tisch 1', 'restaurant_id' => $home->id]);
        $this->assertDatabaseMissing('tables', ['label' => 'Tisch 1', 'restaurant_id' => $foreign->id]);
    }

    public function test_staff_cannot_create_a_table(): void
    {
        $home = Restaurant::factory()->create();

        $this->actingAs($this->staff($home))
            ->post(route('tables.store'), ['label' => 'Tisch 9', 'seats' => 2])
            ->assertForbidden();

        $this->assertDatabaseMissing('tables', ['label' => 'Tisch 9']);
    }

    public function test_store_rejects_seats_outside_one_to_twenty(): void
    {
        $home = Restaurant::factory()->create();
        $owner = $this->owner($home);

        $this->actingAs($owner)
            ->post(route('tables.store'), ['label' => 'Riesentisch', 'seats' => 25])
            ->assertSessionHasErrors('seats');

        $this->actingAs($owner)
            ->post(route('tables.store'), ['label' => 'Phantomtisch', 'seats' => 0])
            ->assertSessionHasErrors('seats');
    }

    public function test_store_requires_a_label(): void
    {
        $home = Restaurant::factory()->create();

        $this->actingAs($this->owner($home))
            ->post(route('tables.store'), ['seats' => 4])
            ->assertSessionHasErrors('label');
    }

    public function test_store_rejects_an_explicit_null_active(): void
    {
        $home = Restaurant::factory()->create();

        // active is a NOT NULL column: an explicit null must be a 422, never a
        // null write that the production DB (MySQL/Postgres) would reject.
        $this->actingAs($this->owner($home))
            ->post(route('tables.store'), ['label' => 'Tisch 2', 'seats' => 4, 'active' => null])
            ->assertSessionHasErrors('active');

        $this->assertDatabaseMissing('tables', ['label' => 'Tisch 2']);
    }

    public function test_owner_can_update_own_table(): void
    {
        $home = Restaurant::factory()->create();
        $table = Table::factory()->for($home)->create(['label' => 'Alt', 'seats' => 2]);

        $this->actingAs($this->owner($home))
            ->patch(route('tables.update', $table), ['label' => 'Neu', 'seats' => 6])
            ->assertRedirect(route('tables.index'));

        $this->assertDatabaseHas('tables', ['id' => $table->id, 'label' => 'Neu', 'seats' => 6]);
    }

    public function test_cross_tenant_update_returns_404_and_leaves_table_untouched(): void
    {
        $home = Restaurant::factory()->create();
        $foreign = Restaurant::factory()->create();
        $foreignTable = Table::factory()->for($foreign)->create(['label' => 'Fremd']);

        $this->actingAs($this->owner($home))
            ->patch(route('tables.update', $foreignTable), ['label' => 'Hijack', 'seats' => 4])
            ->assertNotFound();

        $this->assertDatabaseHas('tables', ['id' => $foreignTable->id, 'label' => 'Fremd']);
    }

    public function test_staff_cannot_update_a_table(): void
    {
        $home = Restaurant::factory()->create();
        $table = Table::factory()->for($home)->create(['label' => 'Alt']);

        $this->actingAs($this->staff($home))
            ->patch(route('tables.update', $table), ['label' => 'Neu', 'seats' => 4])
            ->assertForbidden();

        $this->assertDatabaseHas('tables', ['id' => $table->id, 'label' => 'Alt']);
    }

    public function test_update_rejects_combinable_with_from_a_foreign_restaurant(): void
    {
        $home = Restaurant::factory()->create();
        $foreign = Restaurant::factory()->create();
        $ownTable = Table::factory()->for($home)->create();
        $foreignTable = Table::factory()->for($foreign)->create();

        // The tenant-scoped combinable_with rule must hold on the update path
        // too, not just on store.
        $this->actingAs($this->owner($home))
            ->patch(route('tables.update', $ownTable), [
                'label' => 'Kombiniert',
                'seats' => 4,
                'combinable_with' => [$foreignTable->id],
            ])
            ->assertSessionHasErrors('combinable_with.0');

        $this->assertDatabaseMissing('tables', ['id' => $ownTable->id, 'label' => 'Kombiniert']);
    }

    public function test_destroy_soft_deactivates_the_table(): void
    {
        $home = Restaurant::factory()->create();
        $table = Table::factory()->for($home)->create(['active' => true]);

        $this->actingAs($this->owner($home))
            ->delete(route('tables.destroy', $table))
            ->assertRedirect(route('tables.index'));

        $this->assertDatabaseHas('tables', ['id' => $table->id, 'active' => false]);
    }

    public function test_destroy_keeps_table_with_assignments_intact(): void
    {
        $home = Restaurant::factory()->create();
        $table = Table::factory()->for($home)->create(['active' => true]);
        ReservationTableAssignment::factory()
            ->for(ReservationRequest::factory()->for($home), 'reservationRequest')
            ->for($table)
            ->create();

        $this->actingAs($this->owner($home))
            ->delete(route('tables.destroy', $table))
            ->assertRedirect(route('tables.index'));

        // Soft-deactivation never removes the row, so historical assignments
        // stay referenceable.
        $this->assertNotNull(Table::find($table->id));
        $this->assertDatabaseHas('tables', ['id' => $table->id, 'active' => false]);
    }

    public function test_staff_cannot_destroy_a_table(): void
    {
        $home = Restaurant::factory()->create();
        $table = Table::factory()->for($home)->create(['active' => true]);

        $this->actingAs($this->staff($home))
            ->delete(route('tables.destroy', $table))
            ->assertForbidden();

        $this->assertDatabaseHas('tables', ['id' => $table->id, 'active' => true]);
    }

    public function test_cross_tenant_destroy_returns_404(): void
    {
        $home = Restaurant::factory()->create();
        $foreign = Restaurant::factory()->create();
        $foreignTable = Table::factory()->for($foreign)->create(['active' => true]);

        $this->actingAs($this->owner($home))
            ->delete(route('tables.destroy', $foreignTable))
            ->assertNotFound();

        $this->assertDatabaseHas('tables', ['id' => $foreignTable->id, 'active' => true]);
    }

    public function test_combinable_with_referencing_a_foreign_table_is_rejected(): void
    {
        $home = Restaurant::factory()->create();
        $foreign = Restaurant::factory()->create();
        $foreignTable = Table::factory()->for($foreign)->create();

        $this->actingAs($this->owner($home))
            ->post(route('tables.store'), [
                'label' => 'Tisch 3',
                'seats' => 4,
                'combinable_with' => [$foreignTable->id],
            ])
            ->assertSessionHasErrors('combinable_with.0');

        $this->assertDatabaseMissing('tables', ['label' => 'Tisch 3']);
    }

    public function test_combinable_with_referencing_an_own_table_is_accepted(): void
    {
        $home = Restaurant::factory()->create();
        $sibling = Table::factory()->for($home)->create();

        $this->actingAs($this->owner($home))
            ->post(route('tables.store'), [
                'label' => 'Tisch 4',
                'seats' => 4,
                'combinable_with' => [$sibling->id],
            ])
            ->assertRedirect(route('tables.index'));

        $this->assertDatabaseHas('tables', ['label' => 'Tisch 4', 'restaurant_id' => $home->id]);
    }
}

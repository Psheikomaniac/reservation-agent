<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use App\Policies\TablePolicy;
use App\Providers\AuthServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class TablePolicyTest extends TestCase
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

    public function test_policy_is_registered_in_auth_service_provider(): void
    {
        $this->assertSame(
            TablePolicy::class,
            AuthServiceProvider::$policies[Table::class] ?? null
        );

        $this->assertInstanceOf(TablePolicy::class, Gate::getPolicyFor(Table::class));
    }

    public function test_owner_has_full_crud_on_own_tables(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->owner($restaurant);
        $table = Table::factory()->for($restaurant)->create();

        $this->assertTrue($owner->can('viewAny', Table::class));
        $this->assertTrue($owner->can('view', $table));
        $this->assertTrue($owner->can('create', Table::class));
        $this->assertTrue($owner->can('update', $table));
        $this->assertTrue($owner->can('delete', $table));
    }

    public function test_staff_may_view_but_not_mutate_tables(): void
    {
        $restaurant = Restaurant::factory()->create();
        $staff = $this->staff($restaurant);
        $table = Table::factory()->for($restaurant)->create();

        $this->assertTrue($staff->can('viewAny', Table::class));
        $this->assertTrue($staff->can('view', $table));
        $this->assertFalse($staff->can('create', Table::class));
        $this->assertFalse($staff->can('update', $table));
        $this->assertFalse($staff->can('delete', $table));
    }

    public function test_user_from_another_restaurant_is_denied_table_access(): void
    {
        [$home, $foreign] = Restaurant::factory()->count(2)->create();
        $foreignTable = Table::factory()->for($foreign)->create();
        $owner = $this->owner($home);

        $this->assertFalse($owner->can('view', $foreignTable));
        $this->assertFalse($owner->can('update', $foreignTable));
        $this->assertFalse($owner->can('delete', $foreignTable));
    }

    public function test_guest_is_denied_all_table_actions(): void
    {
        $restaurant = Restaurant::factory()->create();
        $table = Table::factory()->for($restaurant)->create();

        $this->assertFalse(Gate::forUser(null)->allows('viewAny', Table::class));
        $this->assertFalse(Gate::forUser(null)->allows('view', $table));
        $this->assertFalse(Gate::forUser(null)->allows('create', Table::class));
        $this->assertFalse(Gate::forUser(null)->allows('update', $table));
        $this->assertFalse(Gate::forUser(null)->allows('delete', $table));
    }
}

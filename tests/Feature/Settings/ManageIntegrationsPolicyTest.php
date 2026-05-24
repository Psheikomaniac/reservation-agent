<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class ManageIntegrationsPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_owner_of_the_restaurant_may_manage_integrations(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();
        $staff = User::factory()->forRestaurant($restaurant)->create();
        $otherOwner = User::factory()->owner()->forRestaurant(Restaurant::factory()->create())->create();

        $this->assertTrue(Gate::forUser($owner)->allows('manageIntegrations', $restaurant));
        $this->assertFalse(Gate::forUser($staff)->allows('manageIntegrations', $restaurant));
        $this->assertFalse(Gate::forUser($otherOwner)->allows('manageIntegrations', $restaurant));
    }
}

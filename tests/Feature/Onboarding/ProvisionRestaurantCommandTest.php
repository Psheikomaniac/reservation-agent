<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProvisionRestaurantCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_provisions_and_prints_the_acceptance_link(): void
    {
        $this->artisan('restaurant:provision', [
            '--name' => 'Trattoria Bella',
            '--slug' => 'trattoria-bella',
            '--email' => 'chef@bella.test',
        ])
            ->expectsOutputToContain('/onboarding/accept/')
            ->assertSuccessful();

        $this->assertDatabaseHas('restaurants', ['slug' => 'trattoria-bella']);
        $this->assertDatabaseHas('users', ['email' => 'chef@bella.test', 'role' => 'owner']);
        $this->assertDatabaseHas('invitations', ['email' => 'chef@bella.test', 'role' => 'owner']);
    }

    public function test_it_fails_on_a_duplicate_slug(): void
    {
        Restaurant::factory()->create(['slug' => 'taken']);

        $this->artisan('restaurant:provision', [
            '--name' => 'X',
            '--slug' => 'taken',
            '--email' => 'new@bella.test',
        ])
            ->expectsOutputToContain('slug')
            ->assertFailed();
    }

    public function test_it_fails_when_required_options_are_missing(): void
    {
        $this->artisan('restaurant:provision', ['--name' => 'Only Name'])
            ->expectsOutputToContain('required')
            ->assertFailed();
    }
}

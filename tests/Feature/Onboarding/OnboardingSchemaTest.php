<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class OnboardingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_be_persisted_without_a_password(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->assertNull($user->fresh()->password);
    }

    public function test_restaurants_onboarding_completed_at_is_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('restaurants', 'onboarding_completed_at'));

        $restaurant = Restaurant::factory()->pendingOnboarding()->create();

        $this->assertNull($restaurant->fresh()->onboarding_completed_at);
    }

    public function test_invitations_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('invitations'));
        $this->assertTrue(Schema::hasColumns('invitations', [
            'id',
            'restaurant_id',
            'email',
            'role',
            'token',
            'expires_at',
            'accepted_at',
            'created_at',
            'updated_at',
        ]));
    }
}

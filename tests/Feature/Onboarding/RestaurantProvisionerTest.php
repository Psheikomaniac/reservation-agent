<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\Tonality;
use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\RestaurantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class RestaurantProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private function provisioner(): RestaurantProvisioner
    {
        return $this->app->make(RestaurantProvisioner::class);
    }

    public function test_it_creates_restaurant_owner_and_invitation_with_safe_defaults(): void
    {
        $result = $this->provisioner()->provision(
            name: 'Trattoria Bella',
            slug: 'trattoria-bella',
            email: 'chef@bella.test',
            timezone: 'Europe/Berlin',
        );

        $restaurant = Restaurant::query()->where('slug', 'trattoria-bella')->sole();
        $this->assertSame(0, $restaurant->capacity);
        $this->assertSame([], $restaurant->opening_hours);
        $this->assertSame(Tonality::Formal, $restaurant->tonality);
        $this->assertNull($restaurant->onboarding_completed_at);

        $owner = User::query()->where('email', 'chef@bella.test')->sole();
        $this->assertSame(UserRole::Owner, $owner->role);
        $this->assertSame($restaurant->id, $owner->restaurant_id);
        $this->assertNull($owner->password);

        $this->assertInstanceOf(Invitation::class, $result->invitation);
        $this->assertSame(UserRole::Owner, $result->invitation->role);
        $this->assertNotEmpty($result->plainToken);
        $this->assertTrue(Invitation::findByToken($result->plainToken)?->is($result->invitation));
    }

    public function test_it_rejects_a_duplicate_slug(): void
    {
        Restaurant::factory()->create(['slug' => 'taken']);

        $this->expectException(ValidationException::class);

        $this->provisioner()->provision('X', 'taken', 'x@example.test', 'Europe/Berlin');
    }

    public function test_it_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dupe@example.test']);

        $this->expectException(ValidationException::class);

        $this->provisioner()->provision('X', 'fresh-slug', 'dupe@example.test', 'Europe/Berlin');
    }

    public function test_it_rejects_an_invalid_timezone(): void
    {
        $this->expectException(ValidationException::class);

        $this->provisioner()->provision('X', 'tz-slug', 'tz@example.test', 'Mars/Phobos');
    }
}

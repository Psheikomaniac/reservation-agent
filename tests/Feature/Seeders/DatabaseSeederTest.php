<?php

namespace Tests\Feature\Seeders;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_a_demo_restaurant_with_opening_hours(): void
    {
        $this->seed(DatabaseSeeder::class);

        $restaurant = Restaurant::query()->where('slug', 'demo-restaurant')->sole();

        $this->assertSame('Demo Restaurant', $restaurant->name);
        $this->assertSame('Europe/Berlin', $restaurant->timezone);
        $this->assertIsArray($restaurant->opening_hours);
        $this->assertArrayHasKey('mon', $restaurant->opening_hours);
        $this->assertSame([], $restaurant->opening_hours['tue'], 'Tuesday should be a rest day');
    }

    public function test_seeder_creates_owner_user_with_known_password(): void
    {
        $this->seed(DatabaseSeeder::class);

        $owner = User::query()->where('email', 'owner@demo.test')->sole();
        $restaurant = Restaurant::query()->where('slug', 'demo-restaurant')->sole();

        $this->assertSame($restaurant->id, $owner->restaurant_id);
        $this->assertSame(UserRole::Owner, $owner->role);
        $this->assertTrue(Hash::check('password', $owner->password));
        $this->assertNotNull($owner->email_verified_at);
    }

    public function test_seeder_creates_three_reservations_in_different_statuses(): void
    {
        $this->seed(DatabaseSeeder::class);

        $restaurant = Restaurant::query()->where('slug', 'demo-restaurant')->sole();

        $requests = ReservationRequest::query()
            ->withoutGlobalScopes()
            ->where('restaurant_id', $restaurant->id)
            ->get();

        $this->assertCount(3, $requests);
        $this->assertEqualsCanonicalizing(
            [
                ReservationStatus::New->value,
                ReservationStatus::InReview->value,
                ReservationStatus::Confirmed->value,
            ],
            $requests->pluck('status')->map(fn (ReservationStatus $s) => $s->value)->all()
        );
    }

    public function test_seeder_is_idempotent_on_reruns(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Restaurant::query()->where('slug', 'demo-restaurant')->count());
        $this->assertSame(1, User::query()->where('email', 'owner@demo.test')->count());

        $restaurant = Restaurant::query()->where('slug', 'demo-restaurant')->sole();

        $this->assertSame(
            3,
            ReservationRequest::query()
                ->withoutGlobalScopes()
                ->where('restaurant_id', $restaurant->id)
                ->count()
        );
    }

    public function test_seeder_does_not_leak_between_restaurants_via_guest_email_match(): void
    {
        $other = Restaurant::factory()->create(['slug' => 'other']);
        ReservationRequest::factory()
            ->forRestaurant($other)
            ->create(['guest_email' => 'anna.becker@demo.test']);

        $this->seed(DatabaseSeeder::class);

        $demo = Restaurant::query()->where('slug', 'demo-restaurant')->sole();

        $this->assertSame(
            3,
            ReservationRequest::query()
                ->withoutGlobalScopes()
                ->where('restaurant_id', $demo->id)
                ->count()
        );
    }
}

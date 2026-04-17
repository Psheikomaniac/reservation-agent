<?php

namespace Tests\Feature\Models;

use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_restaurant_factory_state_assigns_the_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();

        $user = User::factory()->forRestaurant($restaurant)->create();

        $this->assertSame($restaurant->id, $user->restaurant_id);
        $this->assertTrue($user->restaurant->is($restaurant));
    }

    public function test_restaurant_id_is_nullable_for_platform_admins(): void
    {
        $user = User::factory()->create(['restaurant_id' => null]);

        $this->assertNull($user->restaurant_id);
        $this->assertNull($user->restaurant);
    }

    public function test_role_is_cast_to_the_user_role_enum(): void
    {
        $user = User::factory()->create(['role' => UserRole::Owner]);

        $this->assertSame(UserRole::Owner, $user->fresh()->role);
        $this->assertSame('owner', DB::table('users')->where('id', $user->id)->value('role'));
    }

    public function test_role_defaults_to_staff_when_not_provided(): void
    {
        DB::table('users')->insert([
            'name' => 'Default Role User',
            'email' => 'default-role@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(UserRole::Staff, User::query()->sole()->role);
    }

    public function test_users_are_cascade_deleted_when_their_restaurant_is_deleted(): void
    {
        $restaurant = Restaurant::factory()->create();
        $users = User::factory()->count(3)->forRestaurant($restaurant)->create();
        $untouchedUser = User::factory()->create(['restaurant_id' => null]);

        $restaurant->delete();

        foreach ($users as $user) {
            $this->assertDatabaseMissing('users', ['id' => $user->id]);
        }
        $this->assertDatabaseHas('users', ['id' => $untouchedUser->id]);
    }

    public function test_restaurant_relation_returns_null_when_restaurant_id_is_null(): void
    {
        $user = User::factory()->create(['restaurant_id' => null]);

        $this->assertNull($user->restaurant()->first());
    }
}

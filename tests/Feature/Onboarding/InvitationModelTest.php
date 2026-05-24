<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InvitationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_hashes_a_plaintext_token_and_finds_by_it(): void
    {
        $restaurant = Restaurant::factory()->create();
        $plain = Invitation::generateToken();

        $invitation = Invitation::create([
            'restaurant_id' => $restaurant->id,
            'email' => 'owner@example.test',
            'role' => UserRole::Owner,
            'token' => Invitation::hashToken($plain),
            'expires_at' => now()->addDays(7),
        ]);

        $this->assertNotSame($plain, $invitation->token);
        $this->assertSame(64, strlen(Invitation::hashToken($plain)));
        $this->assertTrue(Invitation::findByToken($plain)?->is($invitation));
        $this->assertNull(Invitation::findByToken('wrong-token'));
        $this->assertSame(UserRole::Owner, $invitation->role);
    }

    public function test_pending_expired_and_accepted_states(): void
    {
        $restaurant = Restaurant::factory()->create();

        $pending = Invitation::factory()->for($restaurant)->create();
        $expired = Invitation::factory()->for($restaurant)->expired()->create();
        $accepted = Invitation::factory()->for($restaurant)->accepted()->create();

        $this->assertTrue($pending->isPending());
        $this->assertFalse($pending->isExpired());
        $this->assertFalse($pending->isAccepted());

        $this->assertTrue($expired->isExpired());
        $this->assertFalse($expired->isPending());

        $this->assertTrue($accepted->isAccepted());
        $this->assertFalse($accepted->isPending());
    }

    public function test_the_token_hash_is_never_serialized(): void
    {
        $invitation = Invitation::factory()->for(Restaurant::factory())->create();

        $this->assertArrayNotHasKey('token', $invitation->toArray());
        $this->assertArrayNotHasKey('token', json_decode((string) json_encode($invitation), true));
    }

    public function test_find_by_token_ignores_the_tenant_scope(): void
    {
        // No authenticated user (acceptance happens before login), so the
        // lookup must bypass RestaurantScope to find the invitation at all.
        $restaurant = Restaurant::factory()->create();
        $plain = Invitation::generateToken();
        $invitation = Invitation::factory()->for($restaurant)->create([
            'token' => Invitation::hashToken($plain),
        ]);

        $this->assertTrue(Invitation::findByToken($plain)?->is($invitation));
    }
}

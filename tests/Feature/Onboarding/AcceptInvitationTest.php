<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class AcceptInvitationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: Invitation}
     */
    private function ownerInvitation(array $state = []): array
    {
        $restaurant = Restaurant::factory()->pendingOnboarding()->create();
        User::factory()->owner()->forRestaurant($restaurant)->create([
            'email' => 'owner@example.test',
            'password' => null,
        ]);
        $plain = Invitation::generateToken();
        $invitation = Invitation::factory()->for($restaurant)->owner()->create([
            'email' => 'owner@example.test',
            'token' => Invitation::hashToken($plain),
            ...$state,
        ]);

        return [$plain, $invitation];
    }

    public function test_a_valid_token_renders_the_acceptance_form(): void
    {
        [$plain] = $this->ownerInvitation();

        $this->get(route('onboarding.accept', ['token' => $plain]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Onboarding/AcceptInvitation')
                ->where('email', 'owner@example.test')
                ->where('token', $plain)
            );
    }

    public function test_unknown_expired_and_accepted_tokens_render_the_error_page(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->get(route('onboarding.accept', ['token' => 'nope']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Onboarding/InvitationError')->where('reason', 'invalid'));

        $expired = Invitation::generateToken();
        Invitation::factory()->for($restaurant)->expired()->create(['token' => Invitation::hashToken($expired)]);
        $this->get(route('onboarding.accept', ['token' => $expired]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Onboarding/InvitationError')->where('reason', 'expired'));

        $accepted = Invitation::generateToken();
        Invitation::factory()->for($restaurant)->accepted()->create(['token' => Invitation::hashToken($accepted)]);
        $this->get(route('onboarding.accept', ['token' => $accepted]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Onboarding/InvitationError')->where('reason', 'accepted'));
    }

    public function test_owner_acceptance_sets_name_password_logs_in_and_redirects_to_dashboard(): void
    {
        [$plain] = $this->ownerInvitation();
        $owner = User::where('email', 'owner@example.test')->sole();

        $this->post(route('onboarding.accept.store', ['token' => $plain]), [
            'name' => 'Mara Chef',
            'password' => 'sup3r-secret-pw',
            'password_confirmation' => 'sup3r-secret-pw',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($owner->fresh());
        $owner->refresh();
        $this->assertSame('Mara Chef', $owner->name);
        $this->assertSame(UserRole::Owner, $owner->role);
        $this->assertTrue(Hash::check('sup3r-secret-pw', $owner->password));
        $this->assertNotNull($owner->email_verified_at);
        $this->assertNotNull(Invitation::findByToken($plain)->accepted_at);
    }

    public function test_staff_acceptance_creates_the_user_from_the_invitation(): void
    {
        $restaurant = Restaurant::factory()->onboarded()->create();
        $plain = Invitation::generateToken();
        Invitation::factory()->for($restaurant)->create([
            'email' => 'server@example.test',
            'role' => UserRole::Staff,
            'token' => Invitation::hashToken($plain),
        ]);

        $this->post(route('onboarding.accept.store', ['token' => $plain]), [
            'name' => 'Sam Server',
            'password' => 'staff-secret-pw',
            'password_confirmation' => 'staff-secret-pw',
        ])->assertRedirect(route('dashboard'));

        $staff = User::where('email', 'server@example.test')->sole();
        $this->assertSame($restaurant->id, $staff->restaurant_id);
        $this->assertSame(UserRole::Staff, $staff->role);
        $this->assertAuthenticatedAs($staff);
    }

    public function test_a_used_token_cannot_be_replayed(): void
    {
        [$plain] = $this->ownerInvitation();

        $this->post(route('onboarding.accept.store', ['token' => $plain]), [
            'name' => 'Mara Chef',
            'password' => 'sup3r-secret-pw',
            'password_confirmation' => 'sup3r-secret-pw',
        ])->assertRedirect(route('dashboard'));

        $this->post('/logout');

        $this->followingRedirects()
            ->post(route('onboarding.accept.store', ['token' => $plain]), [
                'name' => 'Intruder',
                'password' => 'another-pw-1234',
                'password_confirmation' => 'another-pw-1234',
            ])
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Onboarding/InvitationError'));
    }

    public function test_validation_errors_are_returned(): void
    {
        [$plain] = $this->ownerInvitation();

        $this->from(route('onboarding.accept', ['token' => $plain]))
            ->post(route('onboarding.accept.store', ['token' => $plain]), [
                'name' => '',
                'password' => 'short',
                'password_confirmation' => 'mismatch',
            ])
            ->assertSessionHasErrors(['name', 'password']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\Tonality;
use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class OnboardingWizardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Restaurant}
     */
    private function owner(): array
    {
        $restaurant = Restaurant::factory()->pendingOnboarding()->create(['name' => 'Bella']);
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        return [$owner, $restaurant];
    }

    public function test_owner_sees_the_wizard_with_progress_and_restaurant_data(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)
            ->get(route('onboarding.wizard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Onboarding/Wizard')
                ->where('restaurant.name', 'Bella')
                ->where('progress.nextCoreStep', 'hours')
                ->where('progress.coreComplete', false)
                ->has('progress.steps')
                ->has('tonalities')
            );
    }

    public function test_staff_cannot_open_the_wizard(): void
    {
        [, $restaurant] = $this->owner();
        $staff = User::factory()->forRestaurant($restaurant)->create();

        $this->actingAs($staff)->get(route('onboarding.wizard'))->assertForbidden();
    }

    public function test_owner_updates_restaurant_info(): void
    {
        [$owner, $restaurant] = $this->owner();

        $this->actingAs($owner)
            ->patch(route('onboarding.restaurant.update'), [
                'name' => 'Neue Bella',
                'slug' => 'neue-bella',
                'timezone' => 'Europe/Vienna',
            ])
            ->assertRedirect(route('onboarding.wizard'));

        $restaurant->refresh();
        $this->assertSame('Neue Bella', $restaurant->name);
        $this->assertSame('neue-bella', $restaurant->slug);
        $this->assertSame('Europe/Vienna', $restaurant->timezone);
    }

    public function test_owner_saves_opening_hours(): void
    {
        [$owner, $restaurant] = $this->owner();

        $this->actingAs($owner)
            ->patch(route('onboarding.hours.update'), [
                'opening_hours' => ['mon' => [['from' => '18:00', 'to' => '22:00']], 'tue' => []],
            ])
            ->assertRedirect(route('onboarding.wizard'));

        $this->assertSame(
            ['mon' => [['from' => '18:00', 'to' => '22:00']], 'tue' => []],
            $restaurant->fresh()->opening_hours,
        );
    }

    public function test_adding_a_table_recomputes_capacity(): void
    {
        [$owner, $restaurant] = $this->owner();

        $this->actingAs($owner)
            ->post(route('onboarding.tables.store'), ['label' => 'Tisch 1', 'seats' => 4])
            ->assertRedirect(route('onboarding.wizard'));
        $this->actingAs($owner)
            ->post(route('onboarding.tables.store'), ['label' => 'Tisch 2', 'seats' => 2]);

        $restaurant->refresh();
        $this->assertSame(2, $restaurant->tables()->count());
        $this->assertSame(6, $restaurant->capacity);
    }

    public function test_completing_the_core_marks_the_restaurant_live(): void
    {
        [$owner, $restaurant] = $this->owner();

        $this->actingAs($owner)->patch(route('onboarding.hours.update'), [
            'opening_hours' => ['mon' => [['from' => '18:00', 'to' => '22:00']]],
        ]);
        $this->assertNull($restaurant->fresh()->onboarding_completed_at);

        $this->actingAs($owner)->post(route('onboarding.tables.store'), ['label' => 'T1', 'seats' => 4]);

        $this->assertNotNull($restaurant->fresh()->onboarding_completed_at);
    }

    public function test_owner_sets_tonality(): void
    {
        [$owner, $restaurant] = $this->owner();

        $this->actingAs($owner)->patch(route('onboarding.tonality.update'), [
            'tonality' => Tonality::Casual->value,
        ])->assertRedirect(route('onboarding.wizard'));

        $this->assertSame(Tonality::Casual, $restaurant->fresh()->tonality);
    }

    public function test_owner_invites_staff_but_staff_cannot(): void
    {
        [$owner, $restaurant] = $this->owner();

        $this->actingAs($owner)->post(route('onboarding.team.store'), [
            'email' => 'server@bella.test',
        ])->assertRedirect(route('onboarding.wizard'));

        $this->assertDatabaseHas('invitations', [
            'restaurant_id' => $restaurant->id,
            'email' => 'server@bella.test',
            'role' => UserRole::Staff->value,
        ]);

        $staff = User::factory()->forRestaurant($restaurant)->create();
        $this->actingAs($staff)->post(route('onboarding.team.store'), [
            'email' => 'other@bella.test',
        ])->assertForbidden();
    }
}

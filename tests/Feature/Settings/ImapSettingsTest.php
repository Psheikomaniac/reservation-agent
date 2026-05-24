<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class ImapSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_configure_imap_and_password_is_masked(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->patch(route('settings.imap.update'), [
            'imap_host' => 'imap.bella.test',
            'imap_username' => 'inbox@bella.test',
            'imap_password' => 'imap-pass-4321',
        ])->assertRedirect();

        $restaurant->refresh();
        $this->assertSame('imap.bella.test', $restaurant->imap_host);
        $this->assertSame('imap-pass-4321', $restaurant->imap_password);

        $this->actingAs($owner)->get(route('settings.imap.edit'))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('settings/Imap')
                ->where('imap.host', 'imap.bella.test')
                ->where('imap.password_masked', '••••4321')
                ->missing('imap.password'));
    }

    public function test_empty_password_keeps_the_existing_one(): void
    {
        $restaurant = Restaurant::factory()->create(['imap_host' => 'h', 'imap_password' => 'keep-7777']);
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->patch(route('settings.imap.update'), [
            'imap_host' => 'h2', 'imap_username' => 'u', 'imap_password' => '',
        ])->assertRedirect();

        $restaurant->refresh();
        $this->assertSame('h2', $restaurant->imap_host);
        $this->assertSame('keep-7777', $restaurant->imap_password);
    }

    public function test_staff_cannot_access(): void
    {
        $restaurant = Restaurant::factory()->create();
        $staff = User::factory()->forRestaurant($restaurant)->create();

        $this->actingAs($staff)->get(route('settings.imap.edit'))->assertForbidden();
        $this->actingAs($staff)->patch(route('settings.imap.update'), [
            'imap_host' => 'h', 'imap_username' => 'u', 'imap_password' => 'p',
        ])->assertForbidden();
    }
}

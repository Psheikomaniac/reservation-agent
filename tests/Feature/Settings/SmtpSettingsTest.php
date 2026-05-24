<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class SmtpSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return [
            'smtp_host' => 'smtp.bella.test',
            'smtp_port' => 587,
            'smtp_username' => 'mailer@bella.test',
            'smtp_password' => 'smtp-pass-9999',
            'smtp_from_address' => 'hallo@bella.test',
            'smtp_from_name' => 'Bella',
            ...$overrides,
        ];
    }

    public function test_owner_can_save_smtp_config_and_password_is_masked_on_read(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->patch(route('settings.smtp.update'), $this->validPayload())->assertRedirect();

        $restaurant->refresh();
        $this->assertSame('smtp.bella.test', $restaurant->smtp_host);
        $this->assertSame('smtp-pass-9999', $restaurant->smtp_password);
        $this->assertSame('hallo@bella.test', $restaurant->smtp_from_address);

        $this->actingAs($owner)->get(route('settings.smtp.edit'))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('settings/Smtp')
                ->where('smtp.host', 'smtp.bella.test')
                ->where('smtp.password_masked', '••••9999')
                ->missing('smtp.password'));
    }

    public function test_empty_password_keeps_the_existing_one(): void
    {
        $restaurant = Restaurant::factory()->create(['smtp_host' => 'h', 'smtp_password' => 'keep-1234']);
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)
            ->patch(route('settings.smtp.update'), $this->validPayload(['smtp_host' => 'h2', 'smtp_password' => '']))
            ->assertRedirect();

        $restaurant->refresh();
        $this->assertSame('h2', $restaurant->smtp_host);
        $this->assertSame('keep-1234', $restaurant->smtp_password);
    }

    public function test_validation_rejects_a_bad_from_address(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)
            ->from(route('settings.smtp.edit'))
            ->patch(route('settings.smtp.update'), $this->validPayload(['smtp_from_address' => 'not-an-email']))
            ->assertSessionHasErrors('smtp_from_address');
    }

    public function test_staff_cannot_access(): void
    {
        $restaurant = Restaurant::factory()->create();
        $staff = User::factory()->forRestaurant($restaurant)->create();

        $this->actingAs($staff)->get(route('settings.smtp.edit'))->assertForbidden();
        $this->actingAs($staff)->patch(route('settings.smtp.update'), $this->validPayload())->assertForbidden();
    }
}

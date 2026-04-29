<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\SendMode;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SendModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_case_carries_a_german_label(): void
    {
        $this->assertSame('Manuelle Freigabe', SendMode::Manual->label());
        $this->assertSame('Shadow-Modus (Test)', SendMode::Shadow->label());
        $this->assertSame('Automatischer Versand', SendMode::Auto->label());
    }

    public function test_restaurant_model_casts_send_mode_to_the_enum(): void
    {
        $restaurant = Restaurant::factory()->create();
        $restaurant->refresh();

        $this->assertInstanceOf(SendMode::class, $restaurant->send_mode);
        $this->assertSame(SendMode::Manual, $restaurant->send_mode);
    }

    public function test_send_mode_can_be_persisted_via_enum_assignment(): void
    {
        $restaurant = Restaurant::factory()->create();

        $restaurant->forceFill(['send_mode' => SendMode::Auto])->save();
        $restaurant->refresh();

        $this->assertSame(SendMode::Auto, $restaurant->send_mode);
    }
}

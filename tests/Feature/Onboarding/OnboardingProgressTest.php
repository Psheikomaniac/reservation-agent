<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\Tonality;
use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\Restaurant;
use App\Models\Table;
use App\Support\OnboardingProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OnboardingProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_is_incomplete_without_hours_and_tables(): void
    {
        $restaurant = Restaurant::factory()->pendingOnboarding()->create();
        $progress = OnboardingProgress::for($restaurant);

        $this->assertTrue($progress->stepComplete('restaurant')); // master data exists post-provision
        $this->assertFalse($progress->stepComplete('hours'));
        $this->assertFalse($progress->stepComplete('tables'));
        $this->assertFalse($progress->isCoreComplete());
        $this->assertSame('hours', $progress->nextCoreStep());
    }

    public function test_core_is_complete_with_hours_and_at_least_one_active_table(): void
    {
        $restaurant = Restaurant::factory()->create([
            'opening_hours' => ['mon' => [['from' => '18:00', 'to' => '22:00']]],
        ]);
        Table::factory()->for($restaurant)->create(['active' => true]);

        $progress = OnboardingProgress::for($restaurant);

        $this->assertTrue($progress->stepComplete('hours'));
        $this->assertTrue($progress->stepComplete('tables'));
        $this->assertTrue($progress->isCoreComplete());
        $this->assertNull($progress->nextCoreStep());
    }

    public function test_an_inactive_table_does_not_satisfy_the_tables_step(): void
    {
        $restaurant = Restaurant::factory()->create([
            'opening_hours' => ['mon' => [['from' => '18:00', 'to' => '22:00']]],
        ]);
        Table::factory()->for($restaurant)->create(['active' => false]);

        $this->assertFalse(OnboardingProgress::for($restaurant)->stepComplete('tables'));
    }

    public function test_optional_steps_tracking_and_pending_list(): void
    {
        $restaurant = Restaurant::factory()->create(['tonality' => Tonality::Casual]);
        $progress = OnboardingProgress::for($restaurant);

        $this->assertTrue($progress->stepComplete('tonality'));
        $this->assertFalse($progress->stepComplete('team'));
        $this->assertSame(['team'], $progress->pendingOptionalSteps());

        Invitation::factory()->for($restaurant)->create(['role' => UserRole::Staff]);
        $this->assertTrue(OnboardingProgress::for($restaurant)->stepComplete('team'));
        $this->assertSame([], OnboardingProgress::for($restaurant)->pendingOptionalSteps());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\PruneFailedEmailImportsJob;
use App\Models\FailedEmailImport;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PruneFailedEmailImportsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'reservations.failed_email_imports.prune.enabled' => true,
            'reservations.failed_email_imports.prune.retention_days' => 30,
        ]);
    }

    public function test_it_deletes_entries_older_than_retention_window(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->createFailureAt($restaurant, Carbon::now()->subDays(31));
        $this->createFailureAt($restaurant, Carbon::now()->subDays(45));

        (new PruneFailedEmailImportsJob)->handle();

        $this->assertSame(0, FailedEmailImport::withoutGlobalScopes()->count());
    }

    public function test_it_keeps_entries_within_retention_window(): void
    {
        $restaurant = Restaurant::factory()->create();

        $fresh = $this->createFailureAt($restaurant, Carbon::now()->subDays(5));
        $atBoundary = $this->createFailureAt($restaurant, Carbon::now()->subDays(30));

        (new PruneFailedEmailImportsJob)->handle();

        $remaining = FailedEmailImport::withoutGlobalScopes()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$fresh->id, $atBoundary->id], $remaining);
    }

    public function test_it_respects_a_custom_retention_window(): void
    {
        config(['reservations.failed_email_imports.prune.retention_days' => 7]);

        $restaurant = Restaurant::factory()->create();

        $this->createFailureAt($restaurant, Carbon::now()->subDays(8));
        $kept = $this->createFailureAt($restaurant, Carbon::now()->subDays(6));

        (new PruneFailedEmailImportsJob)->handle();

        $this->assertSame(
            [$kept->id],
            FailedEmailImport::withoutGlobalScopes()->pluck('id')->all(),
        );
    }

    public function test_it_is_a_noop_when_flag_is_disabled(): void
    {
        config(['reservations.failed_email_imports.prune.enabled' => false]);

        $restaurant = Restaurant::factory()->create();
        $ancient = $this->createFailureAt($restaurant, Carbon::now()->subDays(365));

        (new PruneFailedEmailImportsJob)->handle();

        $this->assertTrue(FailedEmailImport::withoutGlobalScopes()->whereKey($ancient->id)->exists());
    }

    public function test_it_is_a_noop_when_retention_days_is_zero_or_negative(): void
    {
        config(['reservations.failed_email_imports.prune.retention_days' => 0]);

        $restaurant = Restaurant::factory()->create();
        $entry = $this->createFailureAt($restaurant, Carbon::now()->subDays(100));

        (new PruneFailedEmailImportsJob)->handle();

        $this->assertTrue(FailedEmailImport::withoutGlobalScopes()->whereKey($entry->id)->exists());
    }

    public function test_it_logs_a_pii_free_audit_summary(): void
    {
        $restaurant = Restaurant::factory()->create();
        $oldest = $this->createFailureAt($restaurant, Carbon::now()->subDays(90));
        $this->createFailureAt($restaurant, Carbon::now()->subDays(40));

        $captured = null;
        Log::shouldReceive('info')
            ->once()
            ->with('reservation.failed_email_imports.pruned', \Mockery::capture($captured));

        (new PruneFailedEmailImportsJob)->handle();

        $this->assertIsArray($captured);
        $this->assertSame(2, $captured['deleted_count']);
        $this->assertSame(30, $captured['retention_days']);
        $this->assertNotNull($captured['cutoff']);
        $this->assertSame(
            $oldest->created_at->toIso8601String(),
            $captured['oldest_deleted_at'],
        );

        $forbiddenKeys = ['message_id', 'raw_headers', 'raw_body', 'error', 'restaurant_id', 'sender', 'body'];
        foreach ($forbiddenKeys as $key) {
            $this->assertArrayNotHasKey($key, $captured, "Audit log must not contain '{$key}'");
        }
    }

    public function test_audit_log_oldest_deleted_at_is_null_when_nothing_is_deleted(): void
    {
        $restaurant = Restaurant::factory()->create();
        $this->createFailureAt($restaurant, Carbon::now()->subDays(5));

        $captured = null;
        Log::shouldReceive('info')
            ->once()
            ->with('reservation.failed_email_imports.pruned', \Mockery::capture($captured));

        (new PruneFailedEmailImportsJob)->handle();

        $this->assertSame(0, $captured['deleted_count']);
        $this->assertNull($captured['oldest_deleted_at']);
    }

    public function test_it_crosses_the_restaurant_global_scope(): void
    {
        $a = Restaurant::factory()->create();
        $b = Restaurant::factory()->create();

        $this->createFailureAt($a, Carbon::now()->subDays(60));
        $this->createFailureAt($b, Carbon::now()->subDays(60));

        (new PruneFailedEmailImportsJob)->handle();

        $this->assertSame(0, FailedEmailImport::withoutGlobalScopes()->count());
    }

    private function createFailureAt(Restaurant $restaurant, Carbon $createdAt): FailedEmailImport
    {
        return FailedEmailImport::factory()
            ->forRestaurant($restaurant)
            ->create(['created_at' => $createdAt]);
    }
}

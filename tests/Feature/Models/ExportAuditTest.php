<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\ExportFormat;
use App\Models\ExportAudit;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExportAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-04-30 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_open_writes_an_audit_row_with_the_user_context(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $audit = ExportAudit::open(
            $user,
            ExportFormat::Csv,
            ['status' => ['confirmed', 'replied']],
            42,
        );

        $this->assertSame($restaurant->id, $audit->restaurant_id);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame(ExportFormat::Csv, $audit->format);
        $this->assertSame(42, $audit->record_count);
        $this->assertNull($audit->storage_path);
        $this->assertNull($audit->downloaded_at);
        $this->assertNull($audit->expires_at);
        $this->assertSame(
            ['status' => ['confirmed', 'replied']],
            $audit->filter_snapshot->getArrayCopy(),
        );
        $this->assertSame('2026-04-30 12:00:00', $audit->created_at->toDateTimeString());
    }

    public function test_open_supports_pdf_format(): void
    {
        $user = User::factory()->forRestaurant(Restaurant::factory()->create())->create();

        $audit = ExportAudit::open($user, ExportFormat::Pdf, [], 0);

        $this->assertSame(ExportFormat::Pdf, $audit->format);
        $this->assertSame(0, $audit->record_count);
        $this->assertSame([], $audit->filter_snapshot->getArrayCopy());
    }

    public function test_restaurant_relation_returns_owning_tenant(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => 'Trattoria Trabant']);
        $user = User::factory()->forRestaurant($restaurant)->create();

        $audit = ExportAudit::open($user, ExportFormat::Csv, [], 1);

        $this->assertSame($restaurant->id, $audit->restaurant->id);
        $this->assertSame('Trattoria Trabant', $audit->restaurant->name);
    }

    public function test_restaurant_has_many_export_audits(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        ExportAudit::open($user, ExportFormat::Csv, [], 10);
        ExportAudit::open($user, ExportFormat::Pdf, [], 20);

        $this->assertCount(2, $restaurant->exportAudits);
    }

    public function test_filter_snapshot_round_trips_complex_arrays(): void
    {
        $user = User::factory()->forRestaurant(Restaurant::factory()->create())->create();

        $filters = [
            'status' => ['new', 'in_review'],
            'source' => ['email'],
            'from' => '2026-04-01',
            'to' => '2026-04-30',
            'q' => 'Geburtstag',
        ];

        $audit = ExportAudit::open($user, ExportFormat::Csv, $filters, 7);
        $audit->refresh();

        $this->assertSame($filters, $audit->filter_snapshot->getArrayCopy());
    }

    public function test_storage_path_and_expires_at_can_be_set_after_async_run_completes(): void
    {
        $user = User::factory()->forRestaurant(Restaurant::factory()->create())->create();
        $audit = ExportAudit::open($user, ExportFormat::Csv, [], 200);

        $audit->forceFill([
            'storage_path' => 'exports/2026-04/200.csv',
            'expires_at' => Carbon::now()->addDay(),
        ])->save();
        $audit->refresh();

        $this->assertSame('exports/2026-04/200.csv', $audit->storage_path);
        $this->assertSame(
            '2026-05-01 12:00:00',
            $audit->expires_at?->toDateTimeString(),
        );
    }
}

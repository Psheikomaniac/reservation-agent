<?php

declare(strict_types=1);

namespace Tests\Feature\Gdpr;

use App\Models\GdprAudit;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GdprAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_writes_a_row_with_action_and_restaurant_only(): void
    {
        Carbon::setTestNow('2026-05-23 12:00:00');
        $restaurant = Restaurant::factory()->create();

        $audit = GdprAudit::record(GdprAudit::ACTION_VIEW, $restaurant->id);

        $this->assertDatabaseCount('gdpr_audits', 1);
        $this->assertSame(GdprAudit::ACTION_VIEW, $audit->action);
        $this->assertSame($restaurant->id, $audit->restaurant_id);
        $this->assertTrue(Carbon::parse('2026-05-23 12:00:00')->equalTo($audit->created_at));

        Carbon::setTestNow();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actionProvider(): iterable
    {
        yield 'view' => [GdprAudit::ACTION_VIEW];
        yield 'delete' => [GdprAudit::ACTION_DELETE];
        yield 'owner_bulk_delete' => [GdprAudit::ACTION_OWNER_BULK_DELETE];
    }

    #[DataProvider('actionProvider')]
    public function test_record_persists_each_action_constant(string $action): void
    {
        $restaurant = Restaurant::factory()->create();

        GdprAudit::record($action, $restaurant->id);

        $this->assertDatabaseHas('gdpr_audits', [
            'action' => $action,
            'restaurant_id' => $restaurant->id,
        ]);
    }

    public function test_the_table_has_no_pii_columns(): void
    {
        $columns = Schema::getColumnListing('gdpr_audits');

        sort($columns);
        $this->assertSame(['action', 'created_at', 'id', 'restaurant_id'], $columns);

        // Explicit guard against PII creeping in via a later migration.
        foreach (['guest_email', 'guest_name', 'guest_phone', 'reservation_id', 'reservation_request_id', 'ip_address', 'user_agent'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "gdpr_audits must not carry the PII column [{$forbidden}].");
        }
    }

    public function test_the_table_has_no_updated_at(): void
    {
        // Append-only: a row is either written or absent, never updated.
        $this->assertFalse(Schema::hasColumn('gdpr_audits', 'updated_at'));
    }
}

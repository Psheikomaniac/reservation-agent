<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Enums\ExportFormat;
use App\Jobs\ExportReservationsJob;
use App\Models\ExportAudit;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function userWithRestaurant(): User
    {
        return User::factory()
            ->forRestaurant(Restaurant::factory()->create())
            ->create();
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $this->post('/exports', ['format' => 'csv'])->assertRedirect('/login');
    }

    public function test_user_without_restaurant_gets_404(): void
    {
        $user = User::factory()->create(['restaurant_id' => null]);

        $this->actingAs($user)
            ->post(route('exports.store'), ['format' => 'csv'])
            ->assertNotFound();
    }

    public function test_invalid_format_fails_validation(): void
    {
        $user = $this->userWithRestaurant();

        $this->actingAs($user)
            ->from('/dashboard')
            ->post(route('exports.store'), ['format' => 'xlsx'])
            ->assertSessionHasErrors('format');
    }

    public function test_sync_path_returns_streamed_csv_for_small_result_set(): void
    {
        $user = $this->userWithRestaurant();
        ReservationRequest::factory()->forRestaurant($user->restaurant)->count(5)->create();

        $response = $this->actingAs($user)
            ->post(route('exports.store'), ['format' => 'csv']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        // Audit row written, sync path → no storage_path / no expiry.
        $audit = ExportAudit::query()->where('user_id', $user->id)->sole();
        $this->assertSame(5, $audit->record_count);
        $this->assertSame(ExportFormat::Csv, $audit->format);
        $this->assertNull($audit->storage_path);
    }

    public function test_async_path_dispatches_job_and_returns_redirect_with_flash(): void
    {
        Queue::fake();
        $user = $this->userWithRestaurant();
        ReservationRequest::factory()->forRestaurant($user->restaurant)->count(150)->create();

        $response = $this->actingAs($user)
            ->from('/dashboard')
            ->post(route('exports.store'), ['format' => 'pdf']);

        $response->assertRedirect();
        $response->assertSessionHas('flash.export');

        Queue::assertPushed(ExportReservationsJob::class);
    }

    public function test_sync_export_does_not_include_rows_from_another_restaurant(): void
    {
        $own = $this->userWithRestaurant();
        ReservationRequest::factory()
            ->forRestaurant($own->restaurant)
            ->create(['guest_name' => 'OwnGuest_'.uniqid()]);

        // Foreign tenant's data must NOT appear in the export
        // payload, even though both restaurants live in the same
        // table — the generator runs through `withoutGlobalScopes`
        // + explicit `where('restaurant_id', ...)` from the user's
        // restaurant.
        $foreign = Restaurant::factory()->create();
        ReservationRequest::factory()
            ->forRestaurant($foreign)
            ->count(3)
            ->create(['guest_name' => 'ForeignGuest_'.uniqid()]);

        $response = $this->actingAs($own)
            ->post(route('exports.store'), ['format' => 'csv']);

        $response->assertOk();

        ob_start();
        $response->sendContent();
        $payload = (string) ob_get_clean();

        $this->assertStringContainsString('OwnGuest_', $payload);
        $this->assertStringNotContainsString('ForeignGuest_', $payload);
    }

    public function test_async_export_audit_carries_only_the_callers_restaurant_id(): void
    {
        Queue::fake();

        $own = $this->userWithRestaurant();
        ReservationRequest::factory()->forRestaurant($own->restaurant)->count(150)->create();

        // A foreign restaurant exists in the table; the audit row
        // and the queued job's `restaurantId` must reference the
        // caller's tenant only.
        $foreign = Restaurant::factory()->create();
        ReservationRequest::factory()->forRestaurant($foreign)->count(50)->create();

        $this->actingAs($own)
            ->post(route('exports.store'), ['format' => 'pdf']);

        $audit = ExportAudit::query()->where('user_id', $own->id)->sole();
        $this->assertSame($own->restaurant_id, $audit->restaurant_id);
        $this->assertSame(150, $audit->record_count);

        Queue::assertPushed(
            ExportReservationsJob::class,
            fn ($job) => $job->restaurantId === $own->restaurant_id,
        );
    }

    public function test_filters_pass_through_to_the_audit_snapshot(): void
    {
        $user = $this->userWithRestaurant();
        ReservationRequest::factory()->forRestaurant($user->restaurant)->count(2)->create();

        $this->actingAs($user)
            ->post(route('exports.store'), [
                'format' => 'csv',
                'status' => ['confirmed'],
                'q' => 'Geburtstag',
            ])
            ->assertOk();

        $audit = ExportAudit::query()->where('user_id', $user->id)->sole();
        $this->assertEqualsCanonicalizing(
            ['status' => ['confirmed'], 'q' => 'Geburtstag'],
            $audit->filter_snapshot->getArrayCopy(),
        );
    }
}

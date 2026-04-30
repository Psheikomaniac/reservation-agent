<?php

declare(strict_types=1);

namespace Tests\Unit\Exports;

use App\Enums\ExportFormat;
use App\Jobs\ExportReservationsJob;
use App\Models\ExportAudit;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Exports\Contracts\ExportGenerator;
use App\Services\Exports\ExportDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class ExportDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function userWithRestaurant(): User
    {
        $restaurant = Restaurant::factory()->create();

        return User::factory()->forRestaurant($restaurant)->create();
    }

    private function seedRequests(User $user, int $count): void
    {
        ReservationRequest::factory()
            ->forRestaurant($user->restaurant)
            ->count($count)
            ->create();
    }

    public function test_it_picks_sync_path_under_threshold(): void
    {
        $user = $this->userWithRestaurant();
        $this->actingAs($user);

        $this->seedRequests($user, 50);

        $generator = Mockery::mock(ExportGenerator::class);
        $generator->shouldReceive('generateSync')
            ->once()
            ->with(
                ExportFormat::Csv,
                Mockery::on(fn ($r) => $r instanceof Restaurant && $r->id === $user->restaurant_id),
                ['status' => ['confirmed']],
            )
            ->andReturn(new StreamedResponse);

        Queue::fake();

        $dispatcher = new ExportDispatcher($generator);
        $response = $dispatcher->dispatch(
            ExportFormat::Csv,
            ['status' => ['confirmed']],
            $user,
        );

        $this->assertInstanceOf(StreamedResponse::class, $response);
        Queue::assertNothingPushed();
    }

    public function test_it_picks_async_path_over_threshold(): void
    {
        Queue::fake();
        $user = $this->userWithRestaurant();
        $this->actingAs($user);

        $this->seedRequests($user, ExportDispatcher::SYNC_THRESHOLD + 1);

        $generator = Mockery::mock(ExportGenerator::class);
        $generator->shouldNotReceive('generateSync');

        $dispatcher = new ExportDispatcher($generator);
        $response = $dispatcher->dispatch(
            ExportFormat::Pdf,
            [],
            $user,
        );

        Queue::assertPushed(
            ExportReservationsJob::class,
            fn (ExportReservationsJob $job): bool => $job->format === ExportFormat::Pdf
                && $job->userId === $user->id
                && $job->restaurantId === $user->restaurant_id
                && $job->filters === [],
        );

        $this->assertNotInstanceOf(StreamedResponse::class, $response);
    }

    public function test_it_writes_audit_row_in_sync_path(): void
    {
        $user = $this->userWithRestaurant();
        $this->actingAs($user);
        $this->seedRequests($user, 5);

        $generator = Mockery::mock(ExportGenerator::class);
        $generator->shouldReceive('generateSync')->andReturn(new StreamedResponse);

        // Use the `web_form` source to match the factory default,
        // so the filter actually returns the 5 seeded rows and the
        // audit's `record_count` reflects that.
        $dispatcher = new ExportDispatcher($generator);
        $dispatcher->dispatch(ExportFormat::Csv, ['source' => ['web_form']], $user);

        $this->assertDatabaseHas('export_audits', [
            'restaurant_id' => $user->restaurant_id,
            'user_id' => $user->id,
            'format' => 'csv',
            'record_count' => 5,
        ]);
    }

    public function test_it_writes_audit_row_in_async_path(): void
    {
        Queue::fake();
        $user = $this->userWithRestaurant();
        $this->actingAs($user);
        $this->seedRequests($user, 250);

        $generator = Mockery::mock(ExportGenerator::class);

        $dispatcher = new ExportDispatcher($generator);
        $dispatcher->dispatch(ExportFormat::Pdf, ['status' => ['new']], $user);

        $this->assertDatabaseHas('export_audits', [
            'restaurant_id' => $user->restaurant_id,
            'user_id' => $user->id,
            'format' => 'pdf',
            'record_count' => 250,
        ]);

        $audit = ExportAudit::query()->where('user_id', $user->id)->sole();
        Queue::assertPushed(
            ExportReservationsJob::class,
            fn (ExportReservationsJob $job): bool => $job->exportAuditId === $audit->id,
        );
    }

    public function test_threshold_boundary_exactly_100_uses_sync_path(): void
    {
        Queue::fake();
        $user = $this->userWithRestaurant();
        $this->actingAs($user);
        $this->seedRequests($user, ExportDispatcher::SYNC_THRESHOLD);

        $generator = Mockery::mock(ExportGenerator::class);
        $generator->shouldReceive('generateSync')->once()->andReturn(new StreamedResponse);

        $dispatcher = new ExportDispatcher($generator);
        $dispatcher->dispatch(ExportFormat::Csv, [], $user);

        Queue::assertNothingPushed();
    }
}

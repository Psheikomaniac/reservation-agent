<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Enums\ExportFormat;
use App\Jobs\ExportReservationsJob;
use App\Mail\ExportReadyMail;
use App\Models\ExportAudit;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Exports\Contracts\ExportGenerator;
use App\Services\Exports\ExportDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AsyncExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-04-30 12:00:00');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWithRestaurant(): User
    {
        return User::factory()
            ->forRestaurant(Restaurant::factory()->create())
            ->create();
    }

    private function seedAudit(User $user, ExportFormat $format = ExportFormat::Csv, int $count = 200): ExportAudit
    {
        ReservationRequest::factory()
            ->forRestaurant($user->restaurant)
            ->count($count)
            ->create();

        return ExportAudit::open($user, $format, [], $count);
    }

    public function test_it_dispatches_export_job_when_record_count_exceeds_threshold(): void
    {
        Queue::fake();
        $user = $this->userWithRestaurant();
        $this->actingAs($user);

        ReservationRequest::factory()
            ->forRestaurant($user->restaurant)
            ->count(150)
            ->create();

        $dispatcher = $this->app->make(ExportDispatcher::class);
        $dispatcher->dispatch(ExportFormat::Csv, [], $user);

        Queue::assertPushed(
            ExportReservationsJob::class,
            fn (ExportReservationsJob $job): bool => $job->format === ExportFormat::Csv
                && $job->userId === $user->id
                && $job->restaurantId === $user->restaurant_id,
        );
    }

    public function test_it_writes_file_and_sends_email_with_signed_url_after_job_completes(): void
    {
        Mail::fake();
        $user = $this->userWithRestaurant();
        $audit = $this->seedAudit($user, ExportFormat::Csv, 200);

        (new ExportReservationsJob(
            $audit->id,
            ExportFormat::Csv,
            [],
            $user->id,
            $user->restaurant_id,
        ))->handle($this->app->make(ExportGenerator::class));

        $audit->refresh();
        $this->assertNotNull($audit->storage_path);
        $this->assertStringStartsWith('exports/'.$user->id.'/', $audit->storage_path);
        $this->assertStringEndsWith('.csv', $audit->storage_path);
        Storage::disk('local')->assertExists($audit->storage_path);

        $this->assertNotNull($audit->expires_at);
        $this->assertSame(
            '2026-05-01 12:00:00',
            $audit->expires_at->toDateTimeString(),
        );

        Mail::assertSent(ExportReadyMail::class, function (ExportReadyMail $mail) use ($user, $audit): bool {
            return $mail->hasTo($user->email)
                && $mail->format === ExportFormat::Csv
                && $mail->recordCount === $audit->record_count
                && str_contains($mail->downloadUrl, route('exports.download', ['token' => $audit->id], false));
        });
    }

    public function test_it_allows_download_for_owner_of_audit_within_signed_window(): void
    {
        $user = $this->userWithRestaurant();
        $audit = ExportAudit::open($user, ExportFormat::Csv, [], 5);

        Storage::disk('local')->put('exports/'.$user->id.'/'.$audit->id.'.csv', 'id;name');
        $audit->forceFill([
            'storage_path' => 'exports/'.$user->id.'/'.$audit->id.'.csv',
            'expires_at' => Carbon::now()->addDay(),
        ])->save();

        $signedUrl = URL::temporarySignedRoute(
            'exports.download',
            Carbon::now()->addDay(),
            ['token' => $audit->id],
        );

        $this->actingAs($user)
            ->get($signedUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->assertNotNull($audit->fresh()->downloaded_at);
    }

    public function test_it_rejects_download_after_expiry(): void
    {
        $user = $this->userWithRestaurant();
        $audit = ExportAudit::open($user, ExportFormat::Csv, [], 5);

        Storage::disk('local')->put('exports/'.$user->id.'/expired.csv', 'id;name');
        $audit->forceFill([
            'storage_path' => 'exports/'.$user->id.'/expired.csv',
            'expires_at' => Carbon::now()->subMinute(),
        ])->save();

        // The signed URL itself was emitted with the expired
        // timestamp, so Laravel's signed middleware would also
        // have rejected it — our application guard kicks in even
        // if someone managed to refresh the signature manually.
        $url = URL::temporarySignedRoute(
            'exports.download',
            Carbon::now()->addDay(),
            ['token' => $audit->id],
        );

        $this->actingAs($user)
            ->get($url)
            ->assertForbidden();
    }

    public function test_it_rejects_download_for_a_different_user(): void
    {
        $owner = $this->userWithRestaurant();
        $intruder = User::factory()->forRestaurant($owner->restaurant)->create();

        $audit = ExportAudit::open($owner, ExportFormat::Csv, [], 5);
        Storage::disk('local')->put('exports/'.$owner->id.'/'.$audit->id.'.csv', 'id;name');
        $audit->forceFill([
            'storage_path' => 'exports/'.$owner->id.'/'.$audit->id.'.csv',
            'expires_at' => Carbon::now()->addDay(),
        ])->save();

        $url = URL::temporarySignedRoute(
            'exports.download',
            Carbon::now()->addDay(),
            ['token' => $audit->id],
        );

        $this->actingAs($intruder)
            ->get($url)
            ->assertForbidden();

        $this->assertNull($audit->fresh()->downloaded_at);
    }

    public function test_unsigned_download_url_is_rejected_by_signed_middleware(): void
    {
        $user = $this->userWithRestaurant();
        $audit = ExportAudit::open($user, ExportFormat::Csv, [], 5);

        // No signature query parameter at all — the `signed`
        // middleware bails before our controller even runs.
        $this->actingAs($user)
            ->get(route('exports.download', ['token' => $audit->id]))
            ->assertForbidden();
    }

    public function test_download_returns_404_when_file_was_already_purged(): void
    {
        $user = $this->userWithRestaurant();
        $audit = ExportAudit::open($user, ExportFormat::Csv, [], 5);
        $audit->forceFill([
            'storage_path' => 'exports/'.$user->id.'/missing.csv',
            'expires_at' => Carbon::now()->addDay(),
        ])->save();

        $url = URL::temporarySignedRoute(
            'exports.download',
            Carbon::now()->addDay(),
            ['token' => $audit->id],
        );

        $this->actingAs($user)
            ->get($url)
            ->assertNotFound();
    }
}

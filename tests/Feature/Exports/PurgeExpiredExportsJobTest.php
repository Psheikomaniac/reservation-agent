<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Enums\ExportFormat;
use App\Jobs\PurgeExpiredExportsJob;
use App\Models\ExportAudit;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeExpiredExportsJobTest extends TestCase
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

    private function expiredAuditWithFile(User $user, string $relativePath = 'exports/old/file.csv'): ExportAudit
    {
        Storage::disk('local')->put($relativePath, 'id;name');

        $audit = ExportAudit::open($user, ExportFormat::Csv, [], 5);
        $audit->forceFill([
            'storage_path' => $relativePath,
            'expires_at' => Carbon::now()->subHour(),
        ])->save();

        return $audit;
    }

    public function test_it_deletes_files_older_than_expiry(): void
    {
        $user = $this->userWithRestaurant();
        $audit = $this->expiredAuditWithFile($user, 'exports/'.$user->id.'/expired.csv');

        Storage::disk('local')->assertExists($audit->storage_path);

        (new PurgeExpiredExportsJob)->handle();

        Storage::disk('local')->assertMissing('exports/'.$user->id.'/expired.csv');
    }

    public function test_it_keeps_audit_record_after_file_delete(): void
    {
        $user = $this->userWithRestaurant();
        $audit = $this->expiredAuditWithFile($user);

        (new PurgeExpiredExportsJob)->handle();

        $this->assertNotNull(ExportAudit::query()->find($audit->id));
    }

    public function test_it_sets_storage_path_to_null_after_delete(): void
    {
        $user = $this->userWithRestaurant();
        $audit = $this->expiredAuditWithFile($user);

        (new PurgeExpiredExportsJob)->handle();

        $audit->refresh();
        $this->assertNull($audit->storage_path);
    }

    public function test_it_skips_audits_that_have_not_yet_expired(): void
    {
        $user = $this->userWithRestaurant();
        $audit = ExportAudit::open($user, ExportFormat::Csv, [], 5);
        Storage::disk('local')->put('exports/'.$user->id.'/fresh.csv', 'id;name');
        $audit->forceFill([
            'storage_path' => 'exports/'.$user->id.'/fresh.csv',
            'expires_at' => Carbon::now()->addDay(),
        ])->save();

        (new PurgeExpiredExportsJob)->handle();

        Storage::disk('local')->assertExists('exports/'.$user->id.'/fresh.csv');
        $this->assertSame(
            'exports/'.$user->id.'/fresh.csv',
            $audit->fresh()->storage_path,
        );
    }

    public function test_it_is_idempotent_on_a_second_run(): void
    {
        $user = $this->userWithRestaurant();
        $audit = $this->expiredAuditWithFile($user);

        (new PurgeExpiredExportsJob)->handle();
        $firstRunPath = $audit->fresh()->storage_path;

        // Second run touches no rows because every expired audit
        // already has storage_path = null.
        (new PurgeExpiredExportsJob)->handle();
        $secondRunPath = $audit->fresh()->storage_path;

        $this->assertNull($firstRunPath);
        $this->assertNull($secondRunPath);
    }

    public function test_it_processes_audits_across_multiple_restaurants_and_users(): void
    {
        $userA = $this->userWithRestaurant();
        $userB = $this->userWithRestaurant();

        $expiredA = $this->expiredAuditWithFile($userA, 'exports/a.csv');
        $expiredB = $this->expiredAuditWithFile($userB, 'exports/b.csv');

        (new PurgeExpiredExportsJob)->handle();

        Storage::disk('local')->assertMissing('exports/a.csv');
        Storage::disk('local')->assertMissing('exports/b.csv');
        $this->assertNull($expiredA->fresh()->storage_path);
        $this->assertNull($expiredB->fresh()->storage_path);
    }
}

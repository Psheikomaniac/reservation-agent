<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Enums\ExportFormat;
use App\Enums\ReservationReplyStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Exports\CsvExportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class CsvExportTest extends TestCase
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

    private function generator(): CsvExportGenerator
    {
        return $this->app->make(CsvExportGenerator::class);
    }

    private function captureCsv(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    public function test_it_streams_a_csv_download_for_small_result_sets(): void
    {
        $restaurant = Restaurant::factory()->create([
            'slug' => 'le-bistro',
            'timezone' => 'Europe/Berlin',
        ]);
        ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->count(3)
            ->create();

        $response = $this->generator()->generateSync(ExportFormat::Csv, $restaurant, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'attachment; filename="reservations-le-bistro-',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringEndsWith('.csv"', (string) $response->headers->get('Content-Disposition'));

        $payload = $this->captureCsv($response);
        $lines = explode("\n", trim($payload));
        // 1 header + 3 data rows.
        $this->assertCount(4, $lines);
    }

    public function test_payload_starts_with_excel_bom_and_uses_semicolon_separator(): void
    {
        $restaurant = Restaurant::factory()->create();
        ReservationRequest::factory()->forRestaurant($restaurant)->create();

        $response = $this->generator()->generateSync(ExportFormat::Csv, $restaurant, []);
        $payload = $this->captureCsv($response);

        $this->assertSame("\xEF\xBB\xBF", substr($payload, 0, 3));

        $headerLine = explode("\n", substr($payload, 3))[0];
        // league/csv quotes header cells that contain spaces — both
        // forms (raw `Manuelle Prüfung` or quoted `"Manuelle Prüfung"`)
        // are valid Excel-DE input. We only care that the columns are
        // present in order with `;` separators.
        $expectedColumns = [
            'ID',
            'Eingegangen',
            'Status',
            'Quelle',
            'Wunschdatum',
            'Personen',
            'Name',
            'E-Mail',
            'Telefon',
            'Manuelle Prüfung',
            'Letzte Antwort',
        ];
        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString($column, $headerLine);
        }
        $this->assertGreaterThanOrEqual(10, substr_count($headerLine, ';'));
    }

    public function test_it_applies_dashboard_filters(): void
    {
        $restaurant = Restaurant::factory()->create();

        ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create([
                'guest_name' => 'Anna Müller',
                'source' => ReservationSource::Email,
                'status' => ReservationStatus::Confirmed,
            ]);
        ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create([
                'guest_name' => 'Bert Schmidt',
                'source' => ReservationSource::WebForm,
                'status' => ReservationStatus::New,
            ]);

        $response = $this->generator()->generateSync(
            ExportFormat::Csv,
            $restaurant,
            ['source' => ['email']],
        );
        $payload = $this->captureCsv($response);

        $this->assertStringContainsString('Anna Müller', $payload);
        $this->assertStringNotContainsString('Bert Schmidt', $payload);
    }

    public function test_it_renders_guest_email_party_size_phone_and_manual_review_flag(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'UTC']);
        $request = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create([
                'guest_name' => 'Carla Engels',
                'guest_email' => 'carla@example.com',
                'guest_phone' => '+49 30 1234567',
                'party_size' => 6,
                'needs_manual_review' => true,
                'status' => ReservationStatus::Confirmed,
                'source' => ReservationSource::Email,
                'created_at' => Carbon::parse('2026-04-29 10:30:00'),
                'desired_at' => Carbon::parse('2026-05-02 19:00:00'),
            ]);
        ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::Sent,
        ]);

        $payload = $this->captureCsv(
            $this->generator()->generateSync(ExportFormat::Csv, $restaurant, []),
        );

        // Skip BOM + header line.
        $dataLine = explode("\n", trim(substr($payload, 3)))[1];

        $this->assertStringContainsString('Carla Engels', $dataLine);
        $this->assertStringContainsString('carla@example.com', $dataLine);
        $this->assertStringContainsString('+49 30 1234567', $dataLine);
        $this->assertStringContainsString(';6;', $dataLine);
        $this->assertStringContainsString(';Bestätigt;', $dataLine);
        $this->assertStringContainsString(';E-Mail;', $dataLine);
        $this->assertStringContainsString('2026-04-29 10:30', $dataLine);
        $this->assertStringContainsString('2026-05-02 19:00', $dataLine);
        $this->assertStringContainsString(';Ja;', $dataLine);
        $this->assertStringContainsString('Versendet', $dataLine);
    }

    public function test_it_does_not_include_rows_from_other_restaurants(): void
    {
        $own = Restaurant::factory()->create();
        $other = Restaurant::factory()->create();

        ReservationRequest::factory()->forRestaurant($own)->create([
            'guest_name' => 'Own Tenant Guest',
        ]);
        ReservationRequest::factory()->forRestaurant($other)->count(3)->create([
            'guest_name' => 'Other Tenant Guest',
        ]);

        $payload = $this->captureCsv(
            $this->generator()->generateSync(ExportFormat::Csv, $own, []),
        );

        $this->assertStringContainsString('Own Tenant Guest', $payload);
        $this->assertStringNotContainsString('Other Tenant Guest', $payload);
    }

    public function test_it_renders_dates_in_the_restaurant_timezone(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'Europe/Berlin']);
        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'created_at' => Carbon::parse('2026-04-29 22:30:00', 'UTC'),
        ]);

        $payload = $this->captureCsv(
            $this->generator()->generateSync(ExportFormat::Csv, $restaurant, []),
        );

        // 22:30 UTC = 00:30 Berlin (CEST, +02:00) — the date should
        // surface in the operator's local timezone.
        $this->assertStringContainsString('2026-04-30 00:30', $payload);
    }

    public function test_it_rejects_pdf_format(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->generator()->generateSync(ExportFormat::Pdf, $restaurant, []);
    }

    public function test_it_sanitizes_csv_injection_attempts_in_guest_columns(): void
    {
        $restaurant = Restaurant::factory()->create();
        ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create([
                'guest_name' => '=HYPERLINK("http://evil.example","Click")',
                'guest_email' => '+evil@example.com',
                'guest_phone' => '-49301234567',
            ]);

        $payload = $this->captureCsv(
            $this->generator()->generateSync(ExportFormat::Csv, $restaurant, []),
        );

        // Each operator-untrusted cell that starts with =/+/-/@
        // gets a tab prefix so spreadsheets parse it as text, not a
        // live formula. The original value is preserved after the tab.
        $this->assertStringContainsString("\t=HYPERLINK", $payload);
        $this->assertStringContainsString("\t+evil@example.com", $payload);
        $this->assertStringContainsString("\t-49301234567", $payload);
    }

    public function test_render_to_string_supplies_async_path_payload(): void
    {
        $restaurant = Restaurant::factory()->create();
        ReservationRequest::factory()->forRestaurant($restaurant)->count(2)->create();

        $payload = $this->generator()->renderToString($restaurant, []);

        $this->assertSame("\xEF\xBB\xBF", substr($payload, 0, 3));
        $this->assertStringContainsString('ID;Eingegangen', $payload);
    }
}

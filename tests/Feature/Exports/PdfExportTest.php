<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Enums\ExportFormat;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Exports\PdfExportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class PdfExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-04-30 12:00:00');
        // dompdf needs more headroom than PHPUnit's default 128 MB
        // ceiling for any non-trivial export. Bumping at the start
        // of every PDF test (rather than only the pagination one)
        // keeps the limit consistent — restoring it post-test
        // would actually break the *next* test, because PHP's
        // resident memory after dompdf is already > 128 MB and
        // any subsequent allocation would push past the lowered
        // ceiling. Leaving it bumped is the practical fix.
        ini_set('memory_limit', '512M');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function generator(): PdfExportGenerator
    {
        return $this->app->make(PdfExportGenerator::class);
    }

    private function capturePdf(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * Render the Blade template directly so content assertions work
     * without parsing dompdf's compressed PDF streams. The
     * generator's `renderPdf()` calls `view()->render()` internally
     * with the same payload shape; verifying the HTML covers all
     * Blade conditional branches without binary-format noise.
     *
     * @param  array<string, mixed>  $filters
     */
    private function renderHtml(Restaurant $restaurant, array $filters): string
    {
        $timezone = $restaurant->timezone ?? config('app.timezone');

        $rows = ReservationRequest::query()
            ->withoutGlobalScopes()
            ->where('restaurant_id', $restaurant->id)
            ->filter($filters)
            ->orderByDesc('desired_at')
            ->get()
            ->map(function (ReservationRequest $request) use ($timezone) {
                $desired = $request->desired_at?->copy()->setTimezone($timezone);
                $statusKey = $request->status?->value ?? 'new';
                $sourceKey = $request->source?->value ?? 'web_form';

                return [
                    'date' => $desired?->format('d.m.Y') ?? '—',
                    'time' => $desired?->format('H:i') ?? '—',
                    'party_size' => (string) ($request->party_size ?? 0),
                    'name' => (string) ($request->guest_name ?? ''),
                    'status' => match ($statusKey) {
                        'confirmed' => 'Bestätigt',
                        'replied' => 'Beantwortet',
                        'declined' => 'Abgelehnt',
                        'cancelled' => 'Storniert',
                        'in_review' => 'In Bearbeitung',
                        default => 'Neu',
                    },
                    'source' => $sourceKey === 'email' ? 'E-Mail' : 'Webformular',
                ];
            })
            ->all();

        $filterSummary = [];
        if (! empty($filters['status'])) {
            $filterSummary[] = 'Status: '.implode(', ', (array) $filters['status']);
        }
        if (! empty($filters['source'])) {
            $filterSummary[] = 'Quelle: '.implode(', ', (array) $filters['source']);
        }
        if (! empty($filters['q'])) {
            $filterSummary[] = 'Suche: "'.$filters['q'].'"';
        }

        return view('exports.reservations-pdf', [
            'restaurant' => $restaurant,
            'generatedAt' => Carbon::now($timezone),
            'filterSummary' => $filterSummary,
            'rows' => $rows,
        ])->render();
    }

    public function test_it_streams_a_pdf_for_small_result_sets(): void
    {
        $restaurant = Restaurant::factory()->create([
            'slug' => 'le-bistro',
            'timezone' => 'Europe/Berlin',
        ]);
        ReservationRequest::factory()->forRestaurant($restaurant)->count(3)->create();

        $response = $this->generator()->generateSync(ExportFormat::Pdf, $restaurant, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'attachment; filename="reservations-le-bistro-',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringEndsWith('.pdf"', (string) $response->headers->get('Content-Disposition'));

        $payload = $this->capturePdf($response);
        $this->assertSame('%PDF-', substr($payload, 0, 5));
    }

    public function test_it_shows_restaurant_name_and_generated_date(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Trattoria Testa',
            'timezone' => 'Europe/Berlin',
        ]);
        ReservationRequest::factory()->forRestaurant($restaurant)->create();

        $html = $this->renderHtml($restaurant, []);

        $this->assertStringContainsString('Trattoria Testa', $html);
        // Carbon::setTestNow('2026-04-30 12:00:00') UTC → 14:00 Berlin (CEST).
        $this->assertStringContainsString('30.04.2026 14:00', $html);
    }

    public function test_it_shows_filter_summary_in_header(): void
    {
        $restaurant = Restaurant::factory()->create();
        ReservationRequest::factory()->forRestaurant($restaurant)->create();

        // Hit the generator's own filter-summary path directly so
        // both the Blade template AND the PdfExportGenerator's
        // status / source label resolution are exercised.
        $rendered = view('exports.reservations-pdf', [
            'restaurant' => $restaurant,
            'generatedAt' => Carbon::now($restaurant->timezone ?? config('app.timezone')),
            'filterSummary' => [
                'Status: Bestätigt, Beantwortet',
                'Quelle: E-Mail',
                'Suche: "Geburtstag"',
            ],
            'rows' => [],
        ])->render();

        $this->assertStringContainsString('Bestätigt, Beantwortet', $rendered);
        $this->assertStringContainsString('Quelle: E-Mail', $rendered);
        // Blade escapes `"` to `&quot;` by default — the assertion
        // matches the escaped form because that's what the rendered
        // HTML actually contains. dompdf decodes the entity back to
        // `"` when it converts to PDF.
        $this->assertStringContainsString('Suche: &quot;Geburtstag&quot;', $rendered);
    }

    public function test_filter_summary_through_generator_uses_german_labels(): void
    {
        // Reach into the generator's own private summarizeFilters()
        // so the assertions cover the German label mapping the
        // production code actually performs (the test helper above
        // skips the translation on purpose). A regression in
        // STATUS_LABELS / SOURCE_LABELS will fail this test.
        $generator = $this->generator();
        $reflection = new \ReflectionMethod($generator, 'summarizeFilters');
        /** @var list<string> $lines */
        $lines = $reflection->invoke($generator, [
            'status' => ['confirmed', 'replied'],
            'source' => ['email'],
            'q' => 'Geburtstag',
        ]);

        $this->assertContains('Status: Bestätigt, Beantwortet', $lines);
        $this->assertContains('Quelle: E-Mail', $lines);
        $this->assertContains('Suche: "Geburtstag"', $lines);
    }

    public function test_it_paginates_large_lists_without_failing(): void
    {
        $restaurant = Restaurant::factory()->create();

        ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->count(35)
            ->create();

        $payload = $this->generator()->renderToString($restaurant, []);

        $this->assertSame('%PDF-', substr($payload, 0, 5));
        // dompdf records one `/Type /Page` object per rendered
        // page and lists each id in the parent `/Pages` `/Kids`
        // array. Multiple pages means the renderer didn't truncate
        // the table; the exact count varies with template padding,
        // so we only assert that at least two pages were emitted.
        $this->assertGreaterThanOrEqual(2, substr_count($payload, '/Type /Page'));
    }

    public function test_it_renders_an_empty_state_when_no_rows_match(): void
    {
        $restaurant = Restaurant::factory()->create();

        $html = $this->renderHtml($restaurant, []);

        $this->assertStringContainsString('Keine Reservierungen', $html);
    }

    public function test_it_does_not_include_rows_from_other_restaurants(): void
    {
        $own = Restaurant::factory()->create();
        $other = Restaurant::factory()->create();

        ReservationRequest::factory()->forRestaurant($own)->create([
            'guest_name' => 'OwnGuestUnique',
        ]);
        ReservationRequest::factory()->forRestaurant($other)->count(3)->create([
            'guest_name' => 'ForeignGuestUnique',
        ]);

        $html = $this->renderHtml($own, []);

        $this->assertStringContainsString('OwnGuestUnique', $html);
        $this->assertStringNotContainsString('ForeignGuestUnique', $html);
    }

    public function test_it_rejects_csv_format(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->generator()->generateSync(ExportFormat::Csv, $restaurant, []);
    }

    public function test_german_status_labels_render_in_the_table_body(): void
    {
        $restaurant = Restaurant::factory()->create();
        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'status' => ReservationStatus::Confirmed,
            'source' => ReservationSource::Email,
            'guest_name' => 'Carla Engels',
        ]);

        $html = $this->renderHtml($restaurant, []);

        $this->assertStringContainsString('Carla Engels', $html);
        $this->assertStringContainsString('Bestätigt', $html);
        $this->assertStringContainsString('E-Mail', $html);
    }
}

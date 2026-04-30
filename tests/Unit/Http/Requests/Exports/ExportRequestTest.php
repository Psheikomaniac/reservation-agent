<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Exports;

use App\Enums\ExportFormat;
use App\Http\Requests\Exports\ExportRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ExportRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run Laravel's validator against the FormRequest's rules so
     * we can assert the validation contract without spinning up a
     * route. The actual export controller / route lands in #239 —
     * the feature-level happy path test belongs there.
     *
     * @param  array<string, mixed>  $payload
     */
    private function validate(array $payload): \Illuminate\Validation\Validator
    {
        $request = new ExportRequest;

        return Validator::make($payload, $request->rules(), $request->messages());
    }

    public function test_format_csv_or_pdf_passes_with_an_empty_filter_set(): void
    {
        $this->assertTrue($this->validate(['format' => 'csv'])->passes());
        $this->assertTrue($this->validate(['format' => 'pdf'])->passes());
    }

    public function test_format_is_required(): void
    {
        $validator = $this->validate([]);

        $this->assertFalse($validator->passes());
        $this->assertSame(
            'Bitte ein Export-Format wählen.',
            $validator->errors()->first('format'),
        );
    }

    public function test_unknown_format_is_rejected(): void
    {
        $validator = $this->validate(['format' => 'xlsx']);

        $this->assertFalse($validator->passes());
        $this->assertSame(
            'Nur CSV und PDF stehen als Export-Format zur Auswahl.',
            $validator->errors()->first('format'),
        );
    }

    public function test_invalid_status_value_is_rejected(): void
    {
        $validator = $this->validate([
            'format' => 'csv',
            'status' => ['confirmed', 'unknown_status'],
        ]);

        $this->assertFalse($validator->passes());
        $this->assertSame(
            'Mindestens ein Status ist ungültig.',
            $validator->errors()->first('status.1'),
        );
    }

    public function test_invalid_source_value_is_rejected(): void
    {
        $validator = $this->validate([
            'format' => 'pdf',
            'source' => ['twitter'],
        ]);

        $this->assertFalse($validator->passes());
        $this->assertSame(
            'Mindestens eine Quelle ist ungültig.',
            $validator->errors()->first('source.0'),
        );
    }

    public function test_to_must_be_after_or_equal_from(): void
    {
        $validator = $this->validate([
            'format' => 'csv',
            'from' => '2026-04-30',
            'to' => '2026-04-29',
        ]);

        $this->assertFalse($validator->passes());
        $this->assertSame(
            'Das Bis-Datum darf nicht vor dem Von-Datum liegen.',
            $validator->errors()->first('to'),
        );
    }

    public function test_search_q_max_120_characters(): void
    {
        $validator = $this->validate([
            'format' => 'csv',
            'q' => str_repeat('a', 121),
        ]);

        $this->assertFalse($validator->passes());
    }

    public function test_authorize_returns_true_unconditionally(): void
    {
        // The tenant guard (user without restaurant → 404) is the
        // controller's job, mirroring AnalyticsController. The form
        // request stays generic so the controller can choose the
        // correct response code.
        $request = ExportRequest::create('/exports', 'POST', ['format' => 'csv']);
        $request->setUserResolver(fn () => User::factory()->create(['restaurant_id' => null]));
        $this->assertTrue($request->authorize());
    }

    private function resolvedRequest(array $body): ExportRequest
    {
        $user = User::factory()->forRestaurant(Restaurant::factory()->create())->create();
        $request = ExportRequest::create('/exports', 'POST', $body);
        $request->setUserResolver(fn () => $user);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        return $request;
    }

    public function test_export_format_helper_returns_the_typed_enum(): void
    {
        $request = $this->resolvedRequest(['format' => 'pdf']);

        $this->assertSame(ExportFormat::Pdf, $request->exportFormat());
    }

    public function test_filter_snapshot_strips_empty_keys_and_format(): void
    {
        $request = $this->resolvedRequest([
            'format' => 'csv',
            'status' => ['confirmed'],
            'source' => [],
            'from' => '',
            'q' => 'Geburtstag',
        ]);

        $snapshot = $request->filterSnapshot();

        $this->assertEqualsCanonicalizing(
            ['status' => ['confirmed'], 'q' => 'Geburtstag'],
            $snapshot,
        );
        $this->assertArrayNotHasKey('format', $snapshot);
        $this->assertArrayNotHasKey('source', $snapshot);
        $this->assertArrayNotHasKey('from', $snapshot);
    }

    public function test_unused_request_class_param_silences_phpstan(): void
    {
        // No-op: avoids an "unused class" warning when Tests\Unit
        // helpers strip imports we do reference indirectly.
        $this->assertInstanceOf(Request::class, new ExportRequest);
    }
}

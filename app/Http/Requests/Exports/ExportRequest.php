<?php

declare(strict_types=1);

namespace App\Http\Requests\Exports;

use App\Enums\ExportFormat;
use App\Http\Requests\Concerns\WithDashboardFilters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a CSV / PDF export request (PRD-009 § Routing +
 * Controller). Reuses the shared dashboard filter schema so a
 * filter the operator has open in the dashboard transfers
 * verbatim into the export — without code duplication.
 *
 * `authorize()` returns `true` unconditionally. The tenant
 * guard (user without restaurant → 404) lives in the export
 * controller, mirroring `AnalyticsController` and keeping the
 * "missing tenant = 404, not 403" convention consistent across
 * V2.0 dashboards.
 */
class ExportRequest extends FormRequest
{
    use WithDashboardFilters;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            ...$this->dashboardFilterRules(),
            'format' => ['required', Rule::enum(ExportFormat::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->dashboardFilterMessages(),
            'format.required' => 'Bitte ein Export-Format wählen.',
            'format.enum' => 'Nur CSV und PDF stehen als Export-Format zur Auswahl.',
        ];
    }

    /**
     * Resolve the validated `format` query/body parameter into
     * the typed enum so call sites don't have to repeat the
     * `from(...)` boilerplate. Named `exportFormat()` so it does
     * not collide with Symfony's `Request::format()`. Always
     * present because the rule is `required`.
     */
    public function exportFormat(): ExportFormat
    {
        return ExportFormat::from($this->validated('format'));
    }

    /**
     * Filter snapshot in the same shape `ReservationRequest::scopeFilter`
     * already consumes. Keys without values are stripped so the
     * audit's `filter_snapshot` doesn't carry empty strings or
     * empty arrays.
     *
     * @return array<string, mixed>
     */
    public function filterSnapshot(): array
    {
        $validated = $this->validated();
        unset($validated['format']);

        foreach ($validated as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                unset($validated[$key]);
            }
        }

        return $validated;
    }
}

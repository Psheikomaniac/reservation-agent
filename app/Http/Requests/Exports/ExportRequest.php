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
 * Authorization keeps it simple in V1.0: the user must have a
 * resolved restaurant (one user → one restaurant). The actual
 * tenant scoping happens downstream in the export pipeline,
 * where the filtered query runs through the global
 * `RestaurantScope`.
 */
class ExportRequest extends FormRequest
{
    use WithDashboardFilters;

    public function authorize(): bool
    {
        return $this->user()?->restaurant_id !== null;
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

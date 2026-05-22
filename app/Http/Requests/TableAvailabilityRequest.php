<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the `date` query parameter for the table-availability grid
 * (PRD-011). Authorization is enforced by the `can:viewAny,Table` middleware on
 * the route, so this request is validation-only.
 */
class TableAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Default an empty `date` to today so opening the Belegung tab without an
     * explicit day still resolves to a valid grid.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('date')) {
            $this->merge(['date' => now()->format('Y-m-d')]);
        }
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'Bitte ein Datum angeben.',
            'date.date_format' => 'Das Datum muss im Format JJJJ-MM-TT vorliegen.',
            'date.after_or_equal' => 'Das Datum darf nicht in der Vergangenheit liegen.',
        ];
    }
}

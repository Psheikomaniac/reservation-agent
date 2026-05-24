<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the opening-hours schedule against the PRD-001 shape:
 *   ['mon' => [['from' => 'HH:MM', 'to' => 'HH:MM'], ...], 'tue' => [], ...]
 * Day keys are the seven lowercase three-letter abbreviations. At least one
 * day must carry one open block, otherwise the restaurant is closed forever.
 */
class OpeningHoursRequest extends FormRequest
{
    private const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    private const TIME = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, ValidationRule|string|Closure>>
     */
    public function rules(): array
    {
        return [
            'opening_hours' => ['required', 'array:'.implode(',', self::DAYS), $this->atLeastOneOpenBlock()],
            'opening_hours.*' => ['array'],
            'opening_hours.*.*.from' => ['required', 'string', self::TIME],
            'opening_hours.*.*.to' => ['required', 'string', self::TIME],
        ];
    }

    private function atLeastOneOpenBlock(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                $fail('Öffnungszeiten fehlen.');

                return;
            }

            foreach ($value as $blocks) {
                if (is_array($blocks) && $blocks !== []) {
                    return;
                }
            }

            $fail('Mindestens ein Tag muss eine Öffnungszeit haben.');
        };
    }
}

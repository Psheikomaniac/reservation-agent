<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AnalyticsRange;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the `range` query parameter for the analytics page
 * (PRD-008). The form-request maps the string token (`today` /
 * `7d` / `30d`) onto the {@see AnalyticsRange} enum so the
 * aggregator never has to defend against unknown ranges.
 */
class AnalyticsIndexRequest extends FormRequest
{
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
            'range' => ['nullable', Rule::enum(AnalyticsRange::class)],
        ];
    }

    /**
     * Resolved range with the PRD-008 default (`30d`) for empty
     * query strings.
     */
    public function range(): AnalyticsRange
    {
        $value = $this->validated()['range'] ?? null;

        return $value === null
            ? AnalyticsRange::Last30Days
            : AnalyticsRange::from($value);
    }
}

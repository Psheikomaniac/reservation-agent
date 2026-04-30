<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Shared dashboard-filter validation schema. PRD-004 introduced
 * the rules on `DashboardFilterRequest`; PRD-009 reuses the
 * exact same shape for the CSV / PDF export so a filter the
 * operator has open in the dashboard transfers verbatim into the
 * export query — no copy-paste, no drift.
 *
 * Concrete FormRequests pull `dashboardFilterRules()` /
 * `dashboardFilterMessages()` into their own `rules()` /
 * `messages()` and add request-specific keys (e.g. `format` for
 * the export request).
 */
trait WithDashboardFilters
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    protected function dashboardFilterRules(): array
    {
        return [
            'status' => ['array'],
            'status.*' => [Rule::enum(ReservationStatus::class)],
            'source' => ['array'],
            'source.*' => [Rule::enum(ReservationSource::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function dashboardFilterMessages(): array
    {
        return [
            'status.array' => 'Der Statusfilter muss als Liste übergeben werden.',
            'status.*.enum' => 'Mindestens ein Status ist ungültig.',
            'source.array' => 'Der Quellfilter muss als Liste übergeben werden.',
            'source.*.enum' => 'Mindestens eine Quelle ist ungültig.',
            'from.date' => 'Das Von-Datum ist ungültig.',
            'to.date' => 'Das Bis-Datum ist ungültig.',
            'to.after_or_equal' => 'Das Bis-Datum darf nicht vor dem Von-Datum liegen.',
            'q.string' => 'Der Suchbegriff muss aus Text bestehen.',
            'q.max' => 'Der Suchbegriff darf höchstens 120 Zeichen lang sein.',
        ];
    }
}

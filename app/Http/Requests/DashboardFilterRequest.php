<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardFilterRequest extends FormRequest
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
    public function messages(): array
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

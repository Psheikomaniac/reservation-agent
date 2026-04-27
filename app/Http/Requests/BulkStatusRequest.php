<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class BulkStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('bulkUpdate', ReservationRequest::class);
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'min:1', 'distinct'],
            'status' => ['required', 'string', Rule::enum(ReservationStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Bitte mindestens eine Reservierung auswählen.',
            'ids.array' => 'Die Auswahl muss als Liste übergeben werden.',
            'ids.min' => 'Bitte mindestens eine Reservierung auswählen.',
            'ids.max' => 'Es dürfen höchstens 200 Reservierungen auf einmal aktualisiert werden.',
            'ids.*.integer' => 'Jede Reservierungs-ID muss eine ganze Zahl sein.',
            'ids.*.min' => 'Jede Reservierungs-ID muss positiv sein.',
            'ids.*.distinct' => 'Reservierungs-IDs dürfen sich nicht wiederholen.',
            'status.required' => 'Bitte einen Zielstatus angeben.',
            'status.enum' => 'Der Zielstatus ist ungültig.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ReservationRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a manual phone/walk-in reservation submission (PRD-012).
 * Authorization is the route's `can:viewAny,Table` gate (same operator
 * capability as the create form). `table_id` is constrained to an active table
 * of the acting user's own restaurant, so a client-supplied id can never point
 * at a foreign or inactive table.
 */
class QuickReservationStoreRequest extends FormRequest
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
            'source' => ['required', 'in:phone,walk_in'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'party_size' => ['required', 'integer', 'min:1', 'max:'.ReservationRequest::MAX_PARTY_SIZE],
            'guest_name' => ['required', 'string', 'max:200'],
            'guest_phone' => ['nullable', 'string', 'max:50', 'regex:/^[+0-9 ()\/-]+$/'],
            'guest_email' => ['nullable', 'email'],
            'note' => ['nullable', 'string', 'max:500'],
            'table_id' => [
                'nullable',
                'integer',
                Rule::exists('tables', 'id')
                    ->where('restaurant_id', $this->user()->restaurant_id)
                    ->where('active', true),
            ],
        ];
    }
}

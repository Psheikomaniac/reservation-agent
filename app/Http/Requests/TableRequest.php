<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for table master data (PRD-011).
 *
 * Authorization is enforced by the `can:` middleware on the table routes
 * (TablePolicy create/update), so this request is validation-only and
 * returns true — mirroring the public StoreReservationRequest pattern.
 */
class TableRequest extends FormRequest
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
        $restaurantId = $this->user()?->restaurant_id;

        return [
            'label' => ['required', 'string', 'max:100'],
            'seats' => ['required', 'integer', 'between:1,20'],
            'room_tag' => ['nullable', 'string', 'max:50'],
            // active and sort_order are NOT NULL columns with DB defaults, so
            // they are optional (omit → default) but must not be written as
            // null. `sometimes` skips an absent field yet rejects an explicit
            // null as a clean 422 instead of letting it hit the database.
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],
            'active' => ['sometimes', 'boolean'],
            'combinable_with' => ['nullable', 'array', 'max:20'],
            // Combinable ids must reference tables of the acting user's own
            // restaurant. Rule::exists hits the query builder, which bypasses
            // the global RestaurantScope, so the tenant constraint is spelled
            // out here explicitly — this is validation, not a controller
            // tenant-scope query.
            'combinable_with.*' => [
                'integer',
                Rule::exists('tables', 'id')->where(
                    fn ($query) => $query->where('restaurant_id', $restaurantId)
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.required' => 'Bitte geben Sie eine Tischbezeichnung an.',
            'label.max' => 'Die Tischbezeichnung darf höchstens 100 Zeichen lang sein.',

            'seats.required' => 'Bitte geben Sie die Anzahl der Sitzplätze an.',
            'seats.integer' => 'Die Anzahl der Sitzplätze muss eine ganze Zahl sein.',
            'seats.between' => 'Ein Tisch hat zwischen 1 und 20 Sitzplätze.',

            'room_tag.max' => 'Die Bereichsangabe darf höchstens 50 Zeichen lang sein.',

            'combinable_with.array' => 'Die kombinierbaren Tische müssen als Liste übergeben werden.',
            'combinable_with.*.exists' => 'Ein kombinierbarer Tisch gehört nicht zu diesem Restaurant.',
        ];
    }
}

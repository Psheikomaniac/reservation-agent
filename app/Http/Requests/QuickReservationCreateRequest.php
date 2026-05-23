<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ReservationRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the optional date/time/party_size query parameters that drive the
 * live availability preview on the quick-entry form (PRD-012). All three are
 * nullable: opening the page with no parameters falls back to the controller's
 * smart defaults. Authorization is the route's `auth`/`verified` middleware; the
 * tenant scope comes from the authenticated user's restaurant, so there is no
 * model binding to guard here. Past-date rejection is enforced authoritatively
 * on the store path (#343), not on this read path.
 */
class QuickReservationCreateRequest extends FormRequest
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
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:'.ReservationRequest::MAX_PARTY_SIZE],
        ];
    }
}

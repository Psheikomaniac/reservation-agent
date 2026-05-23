<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Owner-only dashboard GDPR bulk-delete by email (PRD-015 § Dashboard-Bulk-Delete).
 * Authorization is the `manage` policy on the user's own restaurant — owners
 * only, staff get a 403 (deletion is destructive and Art. 17 is owner-driven
 * here). Tenant scope on the actual delete comes from the global RestaurantScope.
 */
class BulkGdprDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $restaurant = $this->user()?->restaurant;

        return $restaurant !== null && Gate::allows('manage', $restaurant);
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:190'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Bitte eine E-Mail-Adresse angeben.',
        ];
    }
}

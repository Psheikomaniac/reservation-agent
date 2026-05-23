<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the GDPR self-service delete confirmation (PRD-015). Authorization
 * is the route's `signed` middleware; the anti-bot check (the typed date must
 * equal the reservation date) lives in the controller, which has the
 * reservation in scope.
 */
class GdprDeleteRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'confirm_date' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_date.required' => 'Bitte bestätige durch Eingabe des Reservierungs-Datums.',
        ];
    }
}

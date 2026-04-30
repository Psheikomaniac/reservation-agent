<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a partial update to the authenticated user's
 * notification preferences (PRD-010).
 *
 * Authorization is implicit: the route only ever updates
 * `auth()->user()`, so there's no target id to gate. Cross-user
 * edits are mathematically impossible because the controller
 * never reads a user id off the request body.
 */
class UpdateNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'browser_notifications' => ['required', 'boolean'],
            'sound_alerts' => ['required', 'boolean'],
            'sound' => ['required', Rule::in(['default', 'chime', 'tap'])],
            'volume' => ['required', 'integer', 'min:0', 'max:100'],
            'daily_digest' => ['required', 'boolean'],
            // HH:MM in 24-hour format. Per PRD-010 the digest job
            // wakes hourly and matches against this string, so the
            // `:` separator and zero-padding are part of the contract.
            'daily_digest_at' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sound.in' => 'Diese Sound-Auswahl ist nicht verfügbar.',
            'volume.integer' => 'Die Lautstärke muss eine ganze Zahl sein.',
            'volume.min' => 'Die Lautstärke darf nicht negativ sein.',
            'volume.max' => 'Die Lautstärke darf höchstens 100 % betragen.',
            'daily_digest_at.regex' => 'Bitte eine Uhrzeit im Format HH:MM angeben.',
        ];
    }
}

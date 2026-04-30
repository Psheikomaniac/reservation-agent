<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\SendMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the owner-driven send-mode + hard-gate configuration form
 * (PRD-007 § Settings-UI). Authorization happens upstream via the
 * `manageSendMode` policy on the route, so `authorize()` returns true.
 */
class SendModeSettingsUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'send_mode' => ['required', 'string', Rule::enum(SendMode::class)],
            'auto_send_party_size_max' => ['required', 'integer', 'min:1', 'max:50'],
            'auto_send_min_lead_time_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
        ];
    }
}

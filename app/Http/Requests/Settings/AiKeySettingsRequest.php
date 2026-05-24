<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the per-restaurant OpenAI key. Authorization happens in the
 * controller via the `manageIntegrations` policy. An empty value means
 * "leave the stored key unchanged".
 */
class AiKeySettingsRequest extends FormRequest
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
            'openai_api_key' => ['nullable', 'string', 'max:255', 'starts_with:sk-'],
        ];
    }
}

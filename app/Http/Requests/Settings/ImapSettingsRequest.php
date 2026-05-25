<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the per-restaurant IMAP receive config. An empty password means
 * "leave the stored password unchanged".
 */
class ImapSettingsRequest extends FormRequest
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
            'imap_host' => ['required', 'string', 'max:255'],
            'imap_username' => ['required', 'string', 'max:255'],
            'imap_password' => ['nullable', 'string', 'max:255'],
        ];
    }
}

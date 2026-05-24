<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Enums\Tonality;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TonalityRequest extends FormRequest
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
            'tonality' => ['required', Rule::enum(Tonality::class)],
        ];
    }
}

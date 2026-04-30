<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\WithDashboardFilters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DashboardFilterRequest extends FormRequest
{
    use WithDashboardFilters;

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
            ...$this->dashboardFilterRules(),
            'selected' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->dashboardFilterMessages();
    }
}

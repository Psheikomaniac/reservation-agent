<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Models\Restaurant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RestaurantInfoRequest extends FormRequest
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
        $restaurantId = $this->user()?->restaurant_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique(Restaurant::class, 'slug')->ignore($restaurantId),
            ],
            'timezone' => ['required', 'timezone'],
        ];
    }
}

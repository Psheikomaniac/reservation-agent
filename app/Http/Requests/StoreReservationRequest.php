<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Restaurant;
use App\Support\Timezone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('desired_at')) {
                    return;
                }

                $restaurant = $this->route('restaurant');
                if (! $restaurant instanceof Restaurant) {
                    return;
                }

                try {
                    Timezone::localToUtc((string) $this->input('desired_at'), $restaurant->timezone);
                } catch (InvalidArgumentException) {
                    $validator->errors()->add('desired_at', 'Datum und Uhrzeit sind ungültig.');
                }
            },
        ];
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'guest_name' => ['required', 'string', 'max:120'],
            'guest_email' => ['required', 'email:rfc,dns', 'max:190'],
            'guest_phone' => ['nullable', 'string', 'max:40'],
            'party_size' => ['required', 'integer', 'min:1', 'max:20'],
            'desired_at' => ['required', 'date', 'after:now'],
            'message' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'guest_name.required' => 'Bitte geben Sie Ihren Namen an.',
            'guest_name.string' => 'Der Name muss aus Text bestehen.',
            'guest_name.max' => 'Der Name darf höchstens 120 Zeichen lang sein.',

            'guest_email.required' => 'Bitte geben Sie eine E-Mail-Adresse an.',
            'guest_email.email' => 'Bitte geben Sie eine gültige E-Mail-Adresse an.',
            'guest_email.max' => 'Die E-Mail-Adresse darf höchstens 190 Zeichen lang sein.',

            'guest_phone.string' => 'Die Telefonnummer muss aus Text bestehen.',
            'guest_phone.max' => 'Die Telefonnummer darf höchstens 40 Zeichen lang sein.',

            'party_size.required' => 'Bitte geben Sie die Personenzahl an.',
            'party_size.integer' => 'Die Personenzahl muss eine ganze Zahl sein.',
            'party_size.min' => 'Die Reservierung muss für mindestens 1 Person sein.',
            'party_size.max' => 'Pro Anfrage sind höchstens 20 Personen möglich.',

            'desired_at.required' => 'Bitte geben Sie das gewünschte Datum samt Uhrzeit an.',
            'desired_at.date' => 'Datum und Uhrzeit sind ungültig.',
            'desired_at.after' => 'Der Wunschtermin muss in der Zukunft liegen.',

            'message.string' => 'Die Nachricht muss aus Text bestehen.',
            'message.max' => 'Die Nachricht darf höchstens 2000 Zeichen lang sein.',
        ];
    }
}

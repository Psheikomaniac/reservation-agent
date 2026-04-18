<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StoreReservationRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'guest_name' => 'Alice Example',
            'guest_email' => 'alice@gmail.com',
            'guest_phone' => '+49 30 1234567',
            'party_size' => 4,
            'desired_at' => Carbon::now()->addDays(3)->format('Y-m-d H:i'),
            'message' => 'Fensterplatz wäre toll.',
        ], $overrides);
    }

    public function test_optional_fields_may_be_omitted(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'demo']);

        $payload = $this->validPayload();
        unset($payload['guest_phone'], $payload['message']);

        $this->post(route('public.reservations.store', $restaurant), $payload)
            ->assertRedirect(route('public.reservations.thanks', $restaurant));
    }

    public function test_guest_name_is_required(): void
    {
        $this->postValid(['guest_name' => null])
            ->assertSessionHasErrors(['guest_name' => 'Bitte geben Sie Ihren Namen an.']);
    }

    public function test_guest_name_is_capped_at_120_characters(): void
    {
        $this->postValid(['guest_name' => str_repeat('a', 121)])
            ->assertSessionHasErrors(['guest_name' => 'Der Name darf höchstens 120 Zeichen lang sein.']);
    }

    public function test_guest_email_is_required(): void
    {
        $this->postValid(['guest_email' => null])
            ->assertSessionHasErrors(['guest_email' => 'Bitte geben Sie eine E-Mail-Adresse an.']);
    }

    public function test_guest_email_error_message_is_german(): void
    {
        $this->postValid(['guest_email' => 'not-an-email'])
            ->assertSessionHasErrors(['guest_email' => 'Bitte geben Sie eine gültige E-Mail-Adresse an.']);
    }

    public function test_guest_phone_is_optional_but_capped_at_40_characters(): void
    {
        $this->postValid(['guest_phone' => str_repeat('1', 41)])
            ->assertSessionHasErrors(['guest_phone' => 'Die Telefonnummer darf höchstens 40 Zeichen lang sein.']);
    }

    public function test_party_size_is_required(): void
    {
        $this->postValid(['party_size' => null])
            ->assertSessionHasErrors(['party_size' => 'Bitte geben Sie die Personenzahl an.']);
    }

    public function test_party_size_must_be_at_least_one(): void
    {
        $this->postValid(['party_size' => 0])
            ->assertSessionHasErrors(['party_size' => 'Die Reservierung muss für mindestens 1 Person sein.']);
    }

    public function test_party_size_above_20_error_message_is_german(): void
    {
        $this->postValid(['party_size' => 21])
            ->assertSessionHasErrors(['party_size' => 'Pro Anfrage sind höchstens 20 Personen möglich.']);
    }

    public function test_party_size_must_be_an_integer(): void
    {
        $this->postValid(['party_size' => 'four'])
            ->assertSessionHasErrors(['party_size' => 'Die Personenzahl muss eine ganze Zahl sein.']);
    }

    public function test_desired_at_is_required(): void
    {
        $this->postValid(['desired_at' => null])
            ->assertSessionHasErrors(['desired_at' => 'Bitte geben Sie das gewünschte Datum samt Uhrzeit an.']);
    }

    public function test_desired_at_past_error_message_is_german(): void
    {
        $this->postValid(['desired_at' => Carbon::now()->subHour()->format('Y-m-d H:i')])
            ->assertSessionHasErrors(['desired_at' => 'Der Wunschtermin muss in der Zukunft liegen.']);
    }

    public function test_message_is_capped_at_2000_characters(): void
    {
        $this->postValid(['message' => str_repeat('a', 2001)])
            ->assertSessionHasErrors(['message' => 'Die Nachricht darf höchstens 2000 Zeichen lang sein.']);
    }

    private function postValid(array $overrides)
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'demo']);

        return $this->from(route('public.reservations.create', $restaurant))
            ->post(route('public.reservations.store', $restaurant), $this->validPayload($overrides));
    }
}

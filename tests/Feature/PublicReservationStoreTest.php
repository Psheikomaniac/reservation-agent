<?php

namespace Tests\Feature;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\Tonality;
use App\Events\ReservationRequestReceived;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PublicReservationStoreTest extends TestCase
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
            'desired_at' => Carbon::now('Europe/Berlin')->addDays(3)->format('Y-m-d H:i'),
            'message' => 'Fensterplatz wäre toll.',
        ], $overrides);
    }

    public function test_create_page_exposes_restaurant_tonality_for_greeting(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Osteria Luna',
            'slug' => 'luna',
            'tonality' => Tonality::Family,
        ]);

        $this->get(route('public.reservations.create', $restaurant))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/ReservationForm')
                ->where('restaurant.name', 'Osteria Luna')
                ->where('restaurant.slug', 'luna')
                ->where('restaurant.tonality', Tonality::Family->value)
            );
    }

    public function test_valid_post_persists_reservation_request_with_source_and_status(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'demo']);

        $this->post(route('public.reservations.store', $restaurant), $this->validPayload())
            ->assertRedirect(route('public.reservations.thanks', $restaurant));

        $reservation = ReservationRequest::withoutGlobalScopes()->sole();
        $this->assertSame($restaurant->id, $reservation->restaurant_id);
        $this->assertSame(ReservationSource::WebForm, $reservation->source);
        $this->assertSame(ReservationStatus::New, $reservation->status);
        $this->assertSame('Alice Example', $reservation->guest_name);
        $this->assertSame('alice@gmail.com', $reservation->guest_email);
        $this->assertSame('+49 30 1234567', $reservation->guest_phone);
        $this->assertSame(4, $reservation->party_size);
        $this->assertSame('Fensterplatz wäre toll.', $reservation->message);
    }

    public function test_raw_payload_captures_submitted_fields(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'demo']);
        $payload = $this->validPayload();

        $this->post(route('public.reservations.store', $restaurant), $payload)
            ->assertRedirect();

        $reservation = ReservationRequest::withoutGlobalScopes()->sole();
        $this->assertSame($payload['guest_name'], $reservation->raw_payload['guest_name']);
        $this->assertSame($payload['guest_email'], $reservation->raw_payload['guest_email']);
        $this->assertSame($payload['desired_at'], $reservation->raw_payload['desired_at']);
    }

    public function test_desired_at_is_converted_from_restaurant_timezone_to_utc(): void
    {
        $restaurant = Restaurant::factory()->create([
            'slug' => 'demo',
            'timezone' => 'Europe/Berlin',
        ]);

        // 2026-07-15 19:00 Berlin (CEST, +02:00) → 17:00 UTC
        $this->post(
            route('public.reservations.store', $restaurant),
            $this->validPayload(['desired_at' => '2026-07-15 19:00'])
        )->assertRedirect();

        $reservation = ReservationRequest::withoutGlobalScopes()->sole();
        $this->assertSame('UTC', $reservation->desired_at->timezoneName);
        $this->assertSame('2026-07-15 17:00:00', $reservation->desired_at->format('Y-m-d H:i:s'));
    }

    public function test_event_is_dispatched_on_successful_store(): void
    {
        Event::fake([ReservationRequestReceived::class]);
        $restaurant = Restaurant::factory()->create(['slug' => 'demo']);

        $this->post(route('public.reservations.store', $restaurant), $this->validPayload())
            ->assertRedirect();

        Event::assertDispatched(
            ReservationRequestReceived::class,
            fn (ReservationRequestReceived $event) => $event->request->restaurant_id === $restaurant->id
                && $event->request->source === ReservationSource::WebForm
        );
    }

    public function test_filled_honeypot_redirects_to_thanks_without_persistence(): void
    {
        Event::fake([ReservationRequestReceived::class]);
        $restaurant = Restaurant::factory()->create(['slug' => 'demo']);

        $this->post(
            route('public.reservations.store', $restaurant),
            $this->validPayload(['website' => 'http://spam.example'])
        )->assertRedirect(route('public.reservations.thanks', $restaurant));

        $this->assertSame(0, ReservationRequest::withoutGlobalScopes()->count());
        Event::assertNotDispatched(ReservationRequestReceived::class);
    }

    public function test_thanks_page_renders_restaurant_name(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Osteria Luna',
            'slug' => 'luna',
        ]);

        $this->get(route('public.reservations.thanks', $restaurant))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Thanks')
                ->where('restaurant.name', 'Osteria Luna')
            );
    }
}

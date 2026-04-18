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

class PublicReservationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
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

    public function test_it_shows_the_public_form_for_an_existing_restaurant_slug(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Demo Restaurant',
            'slug' => 'demo',
        ]);

        $this->get(route('public.reservations.create', $restaurant))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/ReservationForm')
                ->where('restaurant.name', 'Demo Restaurant')
                ->where('restaurant.slug', 'demo')
            );
    }

    public function test_it_exposes_restaurant_tonality_for_the_greeting(): void
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
                ->where('restaurant.tonality', Tonality::Family->value)
            );
    }

    public function test_it_creates_a_reservation_request_on_valid_submission(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'demo']);

        $this->post(route('public.reservations.store', $restaurant), $this->validPayload())
            ->assertRedirect(route('public.reservations.thanks', $restaurant));

        $reservation = ReservationRequest::withoutGlobalScopes()->sole();
        $this->assertSame($restaurant->id, $reservation->restaurant_id);
        $this->assertSame(ReservationSource::WebForm, $reservation->source);
        $this->assertSame(ReservationStatus::New, $reservation->status);
        $this->assertSame('Alice Example', $reservation->guest_name);
        $this->assertSame(4, $reservation->party_size);
    }

    public function test_it_dispatches_reservation_request_received(): void
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

    public function test_it_rejects_past_dates(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'demo']);

        $this->from(route('public.reservations.create', $restaurant))
            ->post(
                route('public.reservations.store', $restaurant),
                $this->validPayload(['desired_at' => Carbon::now()->subHour()->format('Y-m-d H:i')])
            )
            ->assertSessionHasErrors('desired_at');

        $this->assertSame(0, ReservationRequest::withoutGlobalScopes()->count());
    }

    public function test_it_rejects_party_sizes_above_20(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'demo']);

        $this->from(route('public.reservations.create', $restaurant))
            ->post(route('public.reservations.store', $restaurant), $this->validPayload(['party_size' => 21]))
            ->assertSessionHasErrors('party_size');

        $this->assertSame(0, ReservationRequest::withoutGlobalScopes()->count());
    }

    public function test_it_rejects_malformed_emails(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'demo']);

        $this->from(route('public.reservations.create', $restaurant))
            ->post(route('public.reservations.store', $restaurant), $this->validPayload(['guest_email' => 'not-an-email']))
            ->assertSessionHasErrors('guest_email');

        $this->assertSame(0, ReservationRequest::withoutGlobalScopes()->count());
    }

    public function test_it_silently_ignores_submissions_with_a_filled_honeypot(): void
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

    public function test_it_rate_limits_after_10_requests_per_minute_per_ip(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'demo']);
        $url = route('public.reservations.store', $restaurant);

        for ($i = 0; $i < 10; $i++) {
            $this->post($url)->assertRedirect();
        }

        $this->post($url)->assertStatus(429);
    }

    public function test_it_returns_an_inertia_friendly_throttle_error_on_the_eleventh_request(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'demo']);
        $url = route('public.reservations.store', $restaurant);

        for ($i = 0; $i < 10; $i++) {
            $this->post($url)->assertRedirect();
        }

        $this->withHeaders(['X-Inertia' => 'true'])
            ->post($url)
            ->assertRedirect()
            ->assertSessionHasErrors('throttle');
    }

    public function test_it_returns_404_for_an_unknown_slug(): void
    {
        $this->get('/r/unknown-slug/reservations')->assertNotFound();
        $this->post('/r/unknown-slug/reservations')->assertNotFound();
        $this->get('/r/unknown-slug/reservations/thanks')->assertNotFound();
    }

    public function test_it_persists_desired_at_in_utc_even_when_input_is_local_time(): void
    {
        $restaurant = Restaurant::factory()->create([
            'slug' => 'demo',
            'timezone' => 'Europe/Berlin',
        ]);

        // 2026-07-15 19:00 Europe/Berlin (CEST, +02:00) → 17:00 UTC
        $this->post(
            route('public.reservations.store', $restaurant),
            $this->validPayload(['desired_at' => '2026-07-15 19:00'])
        )->assertRedirect();

        $reservation = ReservationRequest::withoutGlobalScopes()->sole();
        $this->assertSame('UTC', $reservation->desired_at->timezoneName);
        $this->assertSame('2026-07-15 17:00:00', $reservation->desired_at->format('Y-m-d H:i:s'));
    }
}

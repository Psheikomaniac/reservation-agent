<?php

declare(strict_types=1);

namespace Tests\Feature\Gdpr;

use App\Models\GdprAudit;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class GdprSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private function reservation(Restaurant $restaurant, array $overrides = []): ReservationRequest
    {
        return ReservationRequest::factory()->for($restaurant)->create(array_merge([
            'guest_name' => 'Anna Müller',
            'guest_email' => 'anna@gmail.com',
        ], $overrides));
    }

    private function signedShowUrl(ReservationRequest $reservation, ?Carbon $expiresAt = null): string
    {
        return URL::temporarySignedRoute(
            'gdpr.self-service',
            $expiresAt ?? now()->addDays(30),
            ['reservation' => $reservation->id],
        );
    }

    public function test_it_renders_self_service_page_with_a_valid_token(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => 'La Trattoria']);
        $reservation = $this->reservation($restaurant, ['party_size' => 4]);

        $this->get($this->signedShowUrl($reservation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/GdprSelfService', false)
                ->where('reservation.guest_name', 'Anna Müller')
                ->where('reservation.guest_email', 'anna@gmail.com')
                ->where('reservation.party_size', 4)
                ->where('restaurant.name', 'La Trattoria')
            );
    }

    public function test_it_returns_403_without_a_signature(): void
    {
        $restaurant = Restaurant::factory()->create();
        $reservation = $this->reservation($restaurant);

        $this->get(route('gdpr.self-service', ['reservation' => $reservation->id]))
            ->assertForbidden();
    }

    public function test_it_returns_403_with_an_expired_signature(): void
    {
        $restaurant = Restaurant::factory()->create();
        $reservation = $this->reservation($restaurant);

        $url = $this->signedShowUrl($reservation, now()->subMinute());

        $this->get($url)->assertForbidden();
    }

    public function test_it_records_a_view_audit_without_pii(): void
    {
        $restaurant = Restaurant::factory()->create();
        $reservation = $this->reservation($restaurant);

        $this->get($this->signedShowUrl($reservation))->assertOk();

        $this->assertDatabaseCount('gdpr_audits', 1);
        $audit = GdprAudit::query()->sole();
        $this->assertSame(GdprAudit::ACTION_VIEW, $audit->action);
        $this->assertSame($restaurant->id, $audit->restaurant_id);
        // The audit row carries no PII by schema; guard the serialized row too.
        $this->assertStringNotContainsString('anna@gmail.com', json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_a_signature_for_one_reservation_does_not_resolve_another(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $reservationA = $this->reservation($restaurantA);
        $reservationB = $this->reservation($restaurantB);

        // Take A's valid signature and swap in B's id: the signature no longer
        // matches the URL, so cross-tenant access is rejected.
        $signedForA = $this->signedShowUrl($reservationA);
        $tampered = str_replace(
            '/gdpr/'.$reservationA->id.'?',
            '/gdpr/'.$reservationB->id.'?',
            $signedForA,
        );

        $this->get($tampered)->assertForbidden();
    }

    public function test_it_does_not_log_pii_on_show(): void
    {
        $restaurant = Restaurant::factory()->create();
        $reservation = $this->reservation($restaurant, ['guest_email' => 'leak-check@gmail.com']);

        $captured = [];
        Log::listen(function ($message) use (&$captured): void {
            $captured[] = json_encode([$message->message, $message->context], JSON_THROW_ON_ERROR);
        });

        $this->get($this->signedShowUrl($reservation))->assertOk();

        $this->assertStringNotContainsString('leak-check@gmail.com', implode("\n", $captured));
        $this->assertStringNotContainsString('Anna Müller', implode("\n", $captured));
    }
}

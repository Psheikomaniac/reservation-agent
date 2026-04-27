<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_lands_on_dashboard_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard'));
    }

    public function test_dashboard_receives_restaurant_name_via_shared_props(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => 'Trattoria Testa']);
        $user = User::factory()->forRestaurant($restaurant)->create();

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('restaurant.id', $restaurant->id)
                ->where('restaurant.name', 'Trattoria Testa')
                ->where('restaurant.timezone', $restaurant->timezone)
                ->etc()
            );
    }

    public function test_dashboard_emits_desired_at_as_utc_iso_for_restaurant_timezone_rendering(): void
    {
        // The client renders desired_at in restaurant-local time using the shared
        // restaurant.timezone prop (see resources/js/lib/format-datetime.ts).
        // That requires the API to keep emitting UTC ISO-8601 unconditionally.
        $restaurant = Restaurant::factory()->create(['timezone' => 'Europe/Berlin']);
        $user = User::factory()->forRestaurant($restaurant)->create();

        // 18:00 UTC is 20:00 in Berlin during DST. The payload must remain UTC.
        $desiredAt = Carbon::parse('2025-06-15T18:00:00Z');

        ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create(['desired_at' => $desiredAt, 'status' => ReservationStatus::New]);

        $this->actingAs($user)
            ->get('/dashboard?clear=1&status[]=new')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('restaurant.timezone', 'Europe/Berlin')
                ->where('requests.data.0.desired_at', $desiredAt->toIso8601String())
                ->etc()
            );
    }

    public function test_user_without_restaurant_receives_null_restaurant_prop(): void
    {
        $user = User::factory()->create(['restaurant_id' => null]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('restaurant', null)
            );
    }

    public function test_seeded_demo_owner_can_login_and_reach_dashboard_with_restaurant_name(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->post('/login', [
            'email' => 'owner@demo.test',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('restaurant.name', 'Demo Restaurant')
                ->etc()
            );
    }

    public function test_lists_reservations_scoped_to_own_restaurant(): void
    {
        [$ownRestaurant, $otherRestaurant] = Restaurant::factory()->count(2)->create();
        $user = User::factory()->forRestaurant($ownRestaurant)->create();

        ReservationRequest::factory()->forRestaurant($ownRestaurant)->count(3)->create();
        ReservationRequest::factory()->forRestaurant($otherRestaurant)->count(2)->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('requests.data', 3)
                ->etc()
            );
    }

    public function test_forbids_listing_other_restaurants_reservations_in_payload(): void
    {
        [$ownRestaurant, $otherRestaurant] = Restaurant::factory()->count(2)->create();
        $user = User::factory()->forRestaurant($ownRestaurant)->create();

        ReservationRequest::factory()
            ->forRestaurant($otherRestaurant)
            ->create(['guest_name' => 'Foreign Guest']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('requests.data', 0)
                ->etc()
            )
            ->assertDontSee('Foreign Guest');
    }

    public function test_applies_default_filters_on_first_visit(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $today = Carbon::now($restaurant->timezone)->startOfDay();

        // Should appear: status in [new, in_review] AND desired_at >= today
        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'status' => ReservationStatus::New,
            'desired_at' => $today->copy()->addDay(),
        ]);
        ReservationRequest::factory()->forRestaurant($restaurant)->inReview()->create([
            'desired_at' => $today->copy()->addDays(2),
        ]);

        // Excluded: wrong status
        ReservationRequest::factory()->forRestaurant($restaurant)->confirmed()->create([
            'desired_at' => $today->copy()->addDay(),
        ]);

        // Excluded: desired_at before today
        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'status' => ReservationStatus::New,
            'desired_at' => $today->copy()->subDays(3),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('requests.data', 2)
                ->where('filters.status', [
                    ReservationStatus::New->value,
                    ReservationStatus::InReview->value,
                ])
                ->where('filters.from', $today->toDateString())
                ->etc()
            );
    }

    public function test_combines_status_source_and_date_filters(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $today = Carbon::now($restaurant->timezone)->startOfDay();

        // Match: confirmed + email + within range
        $matching = ReservationRequest::factory()->forRestaurant($restaurant)->confirmed()->create([
            'source' => ReservationSource::Email,
            'desired_at' => $today->copy()->addDays(3),
            'guest_name' => 'Matching Guest',
        ]);

        // Wrong status (new vs confirmed filter)
        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'status' => ReservationStatus::New,
            'source' => ReservationSource::Email,
            'desired_at' => $today->copy()->addDays(3),
        ]);

        // Wrong source (web_form vs email filter)
        ReservationRequest::factory()->forRestaurant($restaurant)->confirmed()->create([
            'source' => ReservationSource::WebForm,
            'desired_at' => $today->copy()->addDays(3),
        ]);

        // Outside date range
        ReservationRequest::factory()->forRestaurant($restaurant)->confirmed()->create([
            'source' => ReservationSource::Email,
            'desired_at' => $today->copy()->addDays(20),
        ]);

        $this->actingAs($user)
            ->get('/dashboard?'.http_build_query([
                'status' => [ReservationStatus::Confirmed->value],
                'source' => [ReservationSource::Email->value],
                'from' => $today->toDateString(),
                'to' => $today->copy()->addDays(7)->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('requests.data', 1)
                ->where('requests.data.0.id', $matching->id)
                ->etc()
            );
    }

    public function test_searches_by_guest_name_and_email(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $today = Carbon::now($restaurant->timezone)->startOfDay()->addDay();

        $byName = ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'guest_name' => 'Anneliese Schmidt',
            'guest_email' => 'unrelated@example.com',
            'desired_at' => $today,
        ]);

        $byEmail = ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'guest_name' => 'Bob Jones',
            'guest_email' => 'anneliese.fan@example.com',
            'desired_at' => $today,
        ]);

        $excluded = ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'guest_name' => 'Carla Klein',
            'guest_email' => 'carla@example.com',
            'desired_at' => $today,
        ]);

        $response = $this->actingAs($user)
            ->get('/dashboard?'.http_build_query([
                'q' => 'anneliese',
                'from' => Carbon::now($restaurant->timezone)->subDay()->toDateString(),
            ]));

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('requests.data', 2)
                ->etc()
            );

        $ids = collect($response->viewData('page')['props']['requests']['data'])->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$byName->id, $byEmail->id], $ids);
        $this->assertNotContains($excluded->id, $ids);
    }

    public function test_marks_rows_needing_manual_review_in_resource_payload(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $today = Carbon::now($restaurant->timezone)->startOfDay()->addDay();

        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'desired_at' => $today,
            'needs_manual_review' => true,
            'guest_name' => 'Needs Review',
        ]);

        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'desired_at' => $today,
            'needs_manual_review' => false,
            'guest_name' => 'All Clear',
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $rows = collect($response->viewData('page')['props']['requests']['data']);

        $review = $rows->firstWhere('guest_name', 'Needs Review');
        $clear = $rows->firstWhere('guest_name', 'All Clear');

        $this->assertNotNull($review, 'Reservation flagged for review is missing from the dashboard payload.');
        $this->assertNotNull($clear, 'Clean reservation is missing from the dashboard payload.');
        $this->assertTrue($review['needs_manual_review']);
        $this->assertFalse($clear['needs_manual_review']);
    }

    public function test_paginates_results_with_25_per_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $tomorrow = Carbon::now($restaurant->timezone)->startOfDay()->addDay();

        ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->count(30)
            ->create(['desired_at' => $tomorrow]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('requests.data', 25)
                ->where('requests.meta.per_page', 25)
                ->where('requests.meta.total', 30)
                ->where('requests.meta.last_page', 2)
                ->etc()
            );

        $this->actingAs($user)
            ->get('/dashboard?page=2')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('requests.data', 5)
                ->where('requests.meta.current_page', 2)
                ->etc()
            );
    }

    public function test_selected_request_is_null_without_query_param(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();
        ReservationRequest::factory()->forRestaurant($restaurant)->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('selectedRequest', null)
            );
    }

    public function test_selected_request_carries_detail_resource_shape_for_email_source(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();
        $reservation = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create([
                'source' => ReservationSource::Email,
                'guest_name' => 'Anna Probe',
                'message' => 'Allergie: Nüsse',
                'raw_payload' => [
                    'body' => "Hallo,\nbitte für 4 Personen am Freitag.",
                    'sender_email' => 'guest@example.test',
                    'sender_name' => 'Anna Probe',
                    'message_id' => '<abc@example.test>',
                ],
            ]);

        $this->actingAs($user)
            ->get("/dashboard?selected={$reservation->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('selectedRequest.id', $reservation->id)
                ->where('selectedRequest.guest_name', 'Anna Probe')
                ->where('selectedRequest.message', 'Allergie: Nüsse')
                ->where('selectedRequest.has_raw_email', true)
                ->where('selectedRequest.raw_email_body', "Hallo,\nbitte für 4 Personen am Freitag.")
                ->where('selectedRequest.raw_payload.sender_email', 'guest@example.test')
            );
    }

    public function test_selected_request_for_web_form_does_not_expose_raw_email_body(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();
        $reservation = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create([
                'source' => ReservationSource::WebForm,
                'raw_payload' => ['guest_name' => 'Anna Probe', 'party_size' => 4],
            ]);

        $this->actingAs($user)
            ->get("/dashboard?selected={$reservation->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedRequest.has_raw_email', false)
                ->where('selectedRequest.raw_email_body', null)
                ->where('selectedRequest.raw_payload.guest_name', 'Anna Probe')
            );
    }

    public function test_selected_request_is_null_for_foreign_tenant(): void
    {
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();
        $userA = User::factory()->forRestaurant($restaurantA)->create();
        $foreignReservation = ReservationRequest::factory()
            ->forRestaurant($restaurantB)
            ->create(['guest_name' => 'Foreign Guest']);

        $this->actingAs($userA)
            ->get("/dashboard?selected={$foreignReservation->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('selectedRequest', null)
            )
            ->assertDontSee('Foreign Guest');
    }

    public function test_selected_request_is_null_for_unknown_id(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $this->actingAs($user)
            ->get('/dashboard?selected=999999')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('selectedRequest', null)
            );
    }

    public function test_selected_param_alone_does_not_clear_default_filters(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();
        $today = Carbon::now($restaurant->timezone)->startOfDay()->toDateString();

        $reservation = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create();

        $this->actingAs($user)
            ->get("/dashboard?selected={$reservation->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.status', [
                    ReservationStatus::New->value,
                    ReservationStatus::InReview->value,
                ])
                ->where('filters.from', $today)
                ->etc()
            );
    }

    // No HTTP-level partial-reload test: Inertia's `only` filtering is
    // framework behaviour. The application-level guarantee is that
    // `selectedRequest` is wired as a closure, which Inertia evaluates
    // lazily — covered indirectly by the `?selected=` tests above.

    public function test_invalid_selected_param_is_rejected_by_validation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $this->actingAs($user)
            ->get('/dashboard?selected=not-a-number')
            ->assertSessionHasErrors('selected');
    }
}

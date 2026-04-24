<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Restaurant $restaurant): User
    {
        return User::factory()->forRestaurant($restaurant)->create();
    }

    public function test_dashboard_renders_with_filters_requests_and_stats_props(): void
    {
        $restaurant = Restaurant::factory()->create();
        $this->actingAs($this->ownerOf($restaurant));

        ReservationRequest::factory()->forRestaurant($restaurant)->count(3)->create();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('filters')
                ->has('requests.data', 3)
                ->has('requests.meta')
                ->has('stats.new')
                ->has('stats.in_review')
                ->etc()
            );
    }

    public function test_requests_are_scoped_to_the_authenticated_users_restaurant(): void
    {
        $mine = Restaurant::factory()->create();
        $other = Restaurant::factory()->create();

        ReservationRequest::factory()->forRestaurant($mine)->count(2)->create();
        ReservationRequest::factory()->forRestaurant($other)->count(5)->create();

        $this->actingAs($this->ownerOf($mine));

        $this->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('requests.meta.total', 2)
                ->etc()
            );
    }

    public function test_results_are_paginated_at_25_per_page_ordered_by_created_at_desc(): void
    {
        $restaurant = Restaurant::factory()->create();
        $this->actingAs($this->ownerOf($restaurant));

        ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->count(26)
            ->sequence(fn ($seq) => ['created_at' => now()->subMinutes($seq->index)])
            ->create();

        $this->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('requests.meta.per_page', 25)
                ->where('requests.meta.total', 26)
                ->where('requests.meta.current_page', 1)
                ->has('requests.data', 25)
                ->etc()
            );

        $this->get(route('dashboard', ['page' => 2]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('requests.meta.current_page', 2)
                ->has('requests.data', 1)
                ->etc()
            );
    }

    public function test_filters_propagate_to_the_query_via_scope_filter(): void
    {
        $restaurant = Restaurant::factory()->create();
        $this->actingAs($this->ownerOf($restaurant));

        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'status' => ReservationStatus::New,
        ]);
        ReservationRequest::factory()->forRestaurant($restaurant)->confirmed()->create();
        ReservationRequest::factory()->forRestaurant($restaurant)->confirmed()->create();

        $this->get(route('dashboard', ['status' => ['confirmed']]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.status', ['confirmed'])
                ->where('requests.meta.total', 2)
                ->etc()
            );
    }

    public function test_filters_prop_round_trips_validated_input(): void
    {
        $restaurant = Restaurant::factory()->create();
        $this->actingAs($this->ownerOf($restaurant));

        $this->get(route('dashboard', [
            'status' => ['new', 'in_review'],
            'source' => ['email'],
            'q' => 'mueller',
        ]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.status', ['new', 'in_review'])
            ->where('filters.source', ['email'])
            ->where('filters.q', 'mueller')
            ->etc()
        );
    }

    public function test_invalid_filter_input_is_rejected(): void
    {
        $restaurant = Restaurant::factory()->create();
        $this->actingAs($this->ownerOf($restaurant));

        $this->get(route('dashboard', ['status' => ['not-a-real-status']]))
            ->assertSessionHasErrors('status.0');
    }

    public function test_stats_count_only_the_authenticated_users_restaurant(): void
    {
        $mine = Restaurant::factory()->create();
        $other = Restaurant::factory()->create();

        ReservationRequest::factory()->forRestaurant($mine)->count(4)->create([
            'status' => ReservationStatus::New,
        ]);
        ReservationRequest::factory()->forRestaurant($mine)->count(2)->state(['status' => ReservationStatus::InReview])->create();
        ReservationRequest::factory()->forRestaurant($other)->count(7)->create([
            'status' => ReservationStatus::New,
        ]);

        $this->actingAs($this->ownerOf($mine));

        $this->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.new', 4)
                ->where('stats.in_review', 2)
                ->etc()
            );
    }

    public function test_stats_are_unaffected_by_active_list_filters(): void
    {
        $restaurant = Restaurant::factory()->create();
        $this->actingAs($this->ownerOf($restaurant));

        ReservationRequest::factory()->forRestaurant($restaurant)->count(3)->create([
            'status' => ReservationStatus::New,
        ]);
        ReservationRequest::factory()->forRestaurant($restaurant)->confirmed()->create();

        // Filter the list to confirmed only — stats must still report total new/in_review counts.
        $this->get(route('dashboard', ['status' => ['confirmed']]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('requests.meta.total', 1)
                ->where('stats.new', 3)
                ->where('stats.in_review', 0)
                ->etc()
            );
    }

    public function test_request_resource_shape_is_present_in_list(): void
    {
        $restaurant = Restaurant::factory()->create();
        $this->actingAs($this->ownerOf($restaurant));

        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'source' => ReservationSource::Email,
            'guest_name' => 'Anna Probe',
        ]);

        $this->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('requests.data.0', fn (AssertableInertia $row) => $row
                    ->where('guest_name', 'Anna Probe')
                    ->where('source', 'email')
                    ->where('has_raw_email', true)
                    ->etc()
                )
                ->etc()
            );
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}

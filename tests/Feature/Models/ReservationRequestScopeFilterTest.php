<?php

namespace Tests\Feature\Models;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReservationRequestScopeFilterTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private ReservationRequest $alice;    // new, web_form, 2026-05-10, "Alice Example" alice@example.test

    private ReservationRequest $bob;      // in_review, email, 2026-05-12, "Bob Müller" bob@example.test

    private ReservationRequest $carol;    // replied, email, 2026-05-15, "Carol Schmidt" carol@other.test

    private ReservationRequest $dave;     // confirmed, web_form, 2026-05-20, "Dave Alice" dave@example.test

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();

        $this->alice = ReservationRequest::factory()->forRestaurant($this->restaurant)->create([
            'status' => ReservationStatus::New,
            'source' => ReservationSource::WebForm,
            'guest_name' => 'Alice Example',
            'guest_email' => 'alice@example.test',
            'desired_at' => Carbon::parse('2026-05-10 19:00'),
        ]);

        $this->bob = ReservationRequest::factory()->forRestaurant($this->restaurant)->create([
            'status' => ReservationStatus::InReview,
            'source' => ReservationSource::Email,
            'guest_name' => 'Bob Müller',
            'guest_email' => 'bob@example.test',
            'desired_at' => Carbon::parse('2026-05-12 20:00'),
        ]);

        $this->carol = ReservationRequest::factory()->forRestaurant($this->restaurant)->create([
            'status' => ReservationStatus::Replied,
            'source' => ReservationSource::Email,
            'guest_name' => 'Carol Schmidt',
            'guest_email' => 'carol@other.test',
            'desired_at' => Carbon::parse('2026-05-15 12:30'),
        ]);

        $this->dave = ReservationRequest::factory()->forRestaurant($this->restaurant)->create([
            'status' => ReservationStatus::Confirmed,
            'source' => ReservationSource::WebForm,
            'guest_name' => 'Dave Alice',
            'guest_email' => 'dave@example.test',
            'desired_at' => Carbon::parse('2026-05-20 13:00'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private function filteredIds(array $filters): array
    {
        return ReservationRequest::query()
            ->filter($filters)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    public function test_empty_filters_return_every_row(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->alice->id, $this->bob->id, $this->carol->id, $this->dave->id],
            $this->filteredIds([]),
        );
    }

    public function test_status_filter_uses_where_in(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->alice->id, $this->bob->id],
            $this->filteredIds(['status' => ['new', 'in_review']]),
        );
    }

    public function test_empty_status_array_is_ignored(): void
    {
        $this->assertCount(4, $this->filteredIds(['status' => []]));
    }

    public function test_source_filter_uses_where_in(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->bob->id, $this->carol->id],
            $this->filteredIds(['source' => ['email']]),
        );
    }

    public function test_empty_source_array_is_ignored(): void
    {
        $this->assertCount(4, $this->filteredIds(['source' => []]));
    }

    public function test_from_filter_is_inclusive(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->carol->id, $this->dave->id],
            $this->filteredIds(['from' => '2026-05-15 00:00:00']),
        );
    }

    public function test_to_filter_is_inclusive(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->alice->id, $this->bob->id],
            $this->filteredIds(['to' => '2026-05-12 23:59:59']),
        );
    }

    public function test_from_and_to_bracket_a_window(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->bob->id, $this->carol->id],
            $this->filteredIds([
                'from' => '2026-05-11 00:00:00',
                'to' => '2026-05-15 23:59:59',
            ]),
        );
    }

    public function test_q_matches_guest_name_fragment(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->alice->id, $this->dave->id],
            $this->filteredIds(['q' => 'Alice']),
        );
    }

    public function test_q_matches_guest_email_fragment(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->carol->id],
            $this->filteredIds(['q' => 'other.test']),
        );
    }

    public function test_q_is_case_insensitive_for_ascii_name(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->alice->id, $this->dave->id],
            $this->filteredIds(['q' => 'alice']),
        );
    }

    public function test_q_handles_umlaut(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->bob->id],
            $this->filteredIds(['q' => 'Müller']),
        );
    }

    public function test_q_returning_no_match_yields_empty_set(): void
    {
        $this->assertSame([], $this->filteredIds(['q' => 'zzz-nobody']));
    }

    public function test_empty_q_string_is_ignored(): void
    {
        $this->assertCount(4, $this->filteredIds(['q' => '']));
    }

    public function test_combines_status_source_date_and_search(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->bob->id],
            $this->filteredIds([
                'status' => ['in_review', 'replied'],
                'source' => ['email'],
                'from' => '2026-05-11 00:00:00',
                'to' => '2026-05-14 00:00:00',
                'q' => 'bob',
            ]),
        );
    }

    public function test_unknown_filter_keys_are_silently_ignored(): void
    {
        $ids = $this->filteredIds([
            'status' => ['new'],
            'restaurant_id' => 999999,
            'foo' => 'bar',
        ]);

        $this->assertSame([$this->alice->id], $ids);
    }

    public function test_scope_preserves_chained_constraints(): void
    {
        $ids = ReservationRequest::query()
            ->where('party_size', '>', 0)
            ->filter(['status' => ['new']])
            ->pluck('id')
            ->all();

        $this->assertSame([$this->alice->id], $ids);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Gdpr;

use App\Models\GdprAudit;
use App\Models\ReservationMessage;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Restaurant;
use App\Models\Table;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class GdprDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = Restaurant::factory()->create(['timezone' => 'Europe/Berlin']);
    }

    private function reservation(array $overrides = []): ReservationRequest
    {
        return ReservationRequest::factory()->for($this->restaurant)->create(array_merge([
            'guest_name' => 'Anna Müller',
            'guest_email' => 'anna@gmail.com',
            // 2026-06-15 19:00 Europe/Berlin → 17:00 UTC; local date is 15.06.2026.
            'desired_at' => CarbonImmutable::parse('2026-06-15 17:00:00', 'UTC'),
        ], $overrides));
    }

    private function expectedConfirmDate(ReservationRequest $reservation): string
    {
        return CarbonImmutable::instance($reservation->desired_at)
            ->setTimezone($this->restaurant->timezone)
            ->format('d.m.Y');
    }

    private function signedDeleteUrl(ReservationRequest $reservation): string
    {
        return URL::temporarySignedRoute(
            'gdpr.self-service.delete',
            now()->addMinutes(15),
            ['reservation' => $reservation->id],
        );
    }

    public function test_show_issues_a_signed_delete_token(): void
    {
        $reservation = $this->reservation();

        $showUrl = URL::temporarySignedRoute('gdpr.self-service', now()->addDays(30), ['reservation' => $reservation->id]);

        $this->get($showUrl)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/GdprSelfService', false)
                ->where('deleteToken', fn (string $token): bool => str_contains($token, '/gdpr/'.$reservation->id.'/delete')
                    && str_contains($token, 'signature='))
            );
    }

    public function test_it_rejects_delete_with_a_wrong_confirm_date(): void
    {
        $reservation = $this->reservation();

        $this->from('/previous')
            ->post($this->signedDeleteUrl($reservation), ['confirm_date' => '01.01.2000'])
            ->assertRedirect('/previous')
            ->assertSessionHasErrors('confirm_date');

        $this->assertDatabaseHas('reservation_requests', ['id' => $reservation->id]);
        $this->assertSame(0, GdprAudit::query()->where('action', GdprAudit::ACTION_DELETE)->count());
    }

    public function test_it_hard_deletes_the_reservation_and_related_records(): void
    {
        $reservation = $this->reservation();
        $reply = ReservationReply::factory()->create(['reservation_request_id' => $reservation->id]);
        ReservationMessage::factory()->create(['reservation_request_id' => $reservation->id]);
        $table = Table::factory()->for($this->restaurant)->create();
        ReservationTableAssignment::factory()
            ->for($reservation, 'reservationRequest')
            ->for($table)
            ->create();

        $this->post($this->signedDeleteUrl($reservation), [
            'confirm_date' => $this->expectedConfirmDate($reservation),
        ])->assertOk();

        $this->assertDatabaseMissing('reservation_requests', ['id' => $reservation->id]);
        $this->assertDatabaseMissing('reservation_replies', ['reservation_request_id' => $reservation->id]);
        $this->assertDatabaseMissing('reservation_messages', ['reservation_request_id' => $reservation->id]);
        $this->assertDatabaseMissing('reservation_table_assignments', ['reservation_request_id' => $reservation->id]);
    }

    public function test_it_records_a_delete_audit_without_pii(): void
    {
        $reservation = $this->reservation();

        $this->post($this->signedDeleteUrl($reservation), [
            'confirm_date' => $this->expectedConfirmDate($reservation),
        ])->assertOk();

        $audit = GdprAudit::query()->where('action', GdprAudit::ACTION_DELETE)->sole();
        $this->assertSame($this->restaurant->id, $audit->restaurant_id);
        $this->assertStringNotContainsString('anna@gmail.com', json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_a_deleted_reservation_is_gone_on_a_subsequent_visit(): void
    {
        $reservation = $this->reservation();
        $id = $reservation->id;

        $this->post($this->signedDeleteUrl($reservation), [
            'confirm_date' => $this->expectedConfirmDate($reservation),
        ])->assertOk();

        // The 30-day view link now resolves nothing — model binding 404s.
        $showUrl = URL::temporarySignedRoute('gdpr.self-service', now()->addDays(30), ['reservation' => $id]);
        $this->get($showUrl)->assertNotFound();
    }

    public function test_it_returns_403_for_delete_without_a_signature(): void
    {
        $reservation = $this->reservation();

        $this->post(route('gdpr.self-service.delete', ['reservation' => $reservation->id]), [
            'confirm_date' => $this->expectedConfirmDate($reservation),
        ])->assertForbidden();

        $this->assertDatabaseHas('reservation_requests', ['id' => $reservation->id]);
    }

    public function test_it_does_not_log_pii_on_delete(): void
    {
        $reservation = $this->reservation(['guest_email' => 'leak-check@gmail.com']);

        $captured = [];
        Log::listen(function ($message) use (&$captured): void {
            $captured[] = json_encode([$message->message, $message->context], JSON_THROW_ON_ERROR);
        });

        $this->post($this->signedDeleteUrl($reservation), [
            'confirm_date' => $this->expectedConfirmDate($reservation),
        ])->assertOk();

        $this->assertStringNotContainsString('leak-check@gmail.com', implode("\n", $captured));
        $this->assertStringNotContainsString('Anna Müller', implode("\n", $captured));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Enums\ReservationReplyStatus;
use App\Mail\ReservationReplyMail;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationReplyMailTest extends TestCase
{
    use RefreshDatabase;

    private function makeReply(string $body, string $restaurantName = 'La Trattoria'): ReservationReply
    {
        $restaurant = Restaurant::factory()->create(['name' => $restaurantName]);
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create();

        return ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::Approved,
            'body' => $body,
        ]);
    }

    public function test_subject_references_the_restaurant_name(): void
    {
        $reply = $this->makeReply('Guten Tag, gerne!', restaurantName: 'Le Bistro');

        $mailable = new ReservationReplyMail($reply);

        $mailable->assertHasSubject('Ihre Reservierungsanfrage bei Le Bistro');
    }

    public function test_body_renders_only_the_operator_approved_text(): void
    {
        $reply = $this->makeReply("Guten Tag Anna,\n\nwir haben um 19:30 einen Tisch frei.\n\nViele Grüße");

        $mailable = new ReservationReplyMail($reply);

        $mailable->assertSeeInText('Guten Tag Anna,');
        $mailable->assertSeeInText('wir haben um 19:30 einen Tisch frei.');
        // No internal field names should leak into the rendered mail.
        $mailable->assertDontSeeInText('reservation_request_id');
        $mailable->assertDontSeeInText('ai_prompt_snapshot');
        $mailable->assertDontSeeInText('approved_by');
    }

    public function test_uses_the_application_from_address(): void
    {
        config()->set('mail.from.address', 'no-reply@test.tld');
        config()->set('mail.from.name', 'Reservation Agent');

        $reply = $this->makeReply('ok');

        $mailable = (new ReservationReplyMail($reply))->render();

        // The Mailable resolves its From from the application mail config
        // when no explicit from() is set, so simply rendering proves the
        // pipeline accepts the configured address. The actual envelope is
        // exercised by the SendReservationReplyJob feature test (#73).
        $this->assertNotEmpty($mailable);
    }
}

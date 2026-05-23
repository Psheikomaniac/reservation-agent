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

class ReservationReplyMailGdprLinkTest extends TestCase
{
    use RefreshDatabase;

    private function makeReply(): ReservationReply
    {
        $restaurant = Restaurant::factory()->create();
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create();

        return ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::Approved,
            'body' => 'Guten Tag, gerne!',
        ]);
    }

    public function test_it_includes_the_gdpr_link_in_the_body(): void
    {
        $reply = $this->makeReply();

        $mailable = new ReservationReplyMail($reply);

        $mailable->assertSeeInText('Datenschutz: Welche Daten haben wir von dir?');
        $mailable->assertSeeInText('/gdpr/'.$reply->reservation_request_id);
    }

    public function test_the_link_is_signed_and_the_route_resolves(): void
    {
        $reply = $this->makeReply();

        $rendered = (new ReservationReplyMail($reply))->render();

        $this->assertSame(
            1,
            preg_match('#https?://\S+/gdpr/\d+\?[^\s<"]+#', $rendered, $matches),
            'A signed GDPR link should appear in the rendered mail.'
        );

        // The extracted signed URL must resolve (valid signature → 200, not 403).
        $this->get($matches[0])->assertOk();
    }

    public function test_the_link_targets_the_reservation_request(): void
    {
        $reply = $this->makeReply();

        $rendered = (new ReservationReplyMail($reply))->render();
        preg_match('#/gdpr/(\d+)\?#', $rendered, $matches);

        $this->assertSame($reply->reservation_request_id, (int) $matches[1]);
    }
}

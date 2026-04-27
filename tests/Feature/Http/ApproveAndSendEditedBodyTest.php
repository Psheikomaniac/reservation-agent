<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Enums\ReservationReplyStatus;
use App\Mail\ReservationReplyMail;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The whole human-in-the-loop guarantee depends on the operator-edited
 * body actually reaching the guest — not the original AI draft (PRD-005
 * / issue #86). The approve flow already overwrites `body` before
 * dispatch (#72); this test runs the SendReservationReplyJob inline (sync
 * queue) with `Mail::fake()` and asserts the rendered text matches the
 * edited body.
 *
 * Also pins the AC that `ai_prompt_snapshot` is NOT touched by the edit:
 * the snapshot retains the exact context fed to OpenAI for reproducibility,
 * even when the operator rewrites the body.
 */
class ApproveAndSendEditedBodyTest extends TestCase
{
    use RefreshDatabase;

    private const string ORIGINAL_DRAFT = 'Original AI draft for the guest.';

    private const string EDITED_BODY = 'Operator-überarbeiteter Text – Tisch am Fenster reserviert.';

    private const array SNAPSHOT = [
        'restaurant' => ['name' => 'Le Bistro', 'tonality' => 'casual'],
        'request' => ['guest_name' => 'Anna', 'party_size' => 2, 'desired_at' => '2026-05-13 19:30', 'message' => null],
        'availability' => [
            'is_open_at_desired_time' => true,
            'seats_free_at_desired' => 12,
            'alternative_slots' => [],
            'closed_reason' => null,
        ],
    ];

    public function test_edited_body_reaches_the_guest_and_snapshot_is_unchanged(): void
    {
        Mail::fake();

        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'guest_email' => 'anna@example.com',
        ]);
        $reply = ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::Draft,
            'body' => self::ORIGINAL_DRAFT,
            'ai_prompt_snapshot' => self::SNAPSHOT,
        ]);

        $this->actingAs($user)
            ->post(route('reservation-replies.approve', $reply), [
                'body' => self::EDITED_BODY,
            ])
            ->assertRedirect();

        // Sync queue runs the SendReservationReplyJob inline; the Mailable
        // captured by Mail::fake should carry the EDITED body.
        Mail::assertSent(ReservationReplyMail::class, function (ReservationReplyMail $mail): bool {
            $rendered = $mail->render();

            return str_contains($rendered, self::EDITED_BODY)
                && ! str_contains($rendered, self::ORIGINAL_DRAFT)
                && $mail->hasTo('anna@example.com');
        });

        $reply->refresh();
        $this->assertSame(self::EDITED_BODY, $reply->body);
        // The snapshot must remain byte-identical to what we stored before
        // the operator's edit — otherwise reproducibility / debugging is
        // broken.
        $this->assertSame(self::SNAPSHOT, $reply->ai_prompt_snapshot);
    }

    public function test_no_body_payload_sends_the_original_draft_verbatim(): void
    {
        Mail::fake();

        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'guest_email' => 'guest@example.com',
        ]);
        $reply = ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::Draft,
            'body' => self::ORIGINAL_DRAFT,
        ]);

        $this->actingAs($user)
            ->post(route('reservation-replies.approve', $reply))
            ->assertRedirect();

        Mail::assertSent(ReservationReplyMail::class, function (ReservationReplyMail $mail): bool {
            return str_contains($mail->render(), self::ORIGINAL_DRAFT);
        });
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\AutoSend;

use App\Enums\MessageDirection;
use App\Enums\ReservationReplyStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\SendMode;
use App\Jobs\ScheduledAutoSendJob;
use App\Mail\ReservationReplyMail;
use App\Models\AutoSendAudit;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\AI\AutoSendDecider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendModeFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_mail_after_cancel_window_in_auto_mode(): void
    {
        Mail::fake();

        $reply = $this->scheduledReplyOnAutoRestaurant();

        (new ScheduledAutoSendJob($reply->id))->handle(app(AutoSendDecider::class));

        Mail::assertSent(ReservationReplyMail::class);
        $reply->refresh();
        $this->assertSame(ReservationReplyStatus::Sent, $reply->status);
        $this->assertNotNull($reply->sent_at);

        // Outbound message row must exist so the dashboard timeline is consistent.
        $this->assertDatabaseHas('reservation_messages', [
            'reservation_request_id' => $reply->reservation_request_id,
            'direction' => MessageDirection::Out->value,
        ]);

        // Successful send writes no audit row in this job (audits live on
        // cancellation paths and on the dispatch decision in #218).
        $this->assertSame(0, AutoSendAudit::count());
    }

    public function test_it_silently_returns_when_status_changed_during_cancel_window(): void
    {
        Mail::fake();

        // Owner clicked "Approve" during the 60s window → status went
        // to Approved before the scheduled job ran.
        $reply = $this->scheduledReplyOnAutoRestaurant();
        $reply->forceFill(['status' => ReservationReplyStatus::Approved])->save();

        (new ScheduledAutoSendJob($reply->id))->handle(app(AutoSendDecider::class));

        Mail::assertNothingSent();
        $reply->refresh();
        $this->assertSame(ReservationReplyStatus::Approved, $reply->status);
        $this->assertSame(0, AutoSendAudit::count());
    }

    public function test_it_silently_returns_when_reply_no_longer_exists(): void
    {
        Mail::fake();

        (new ScheduledAutoSendJob(999_999))->handle(app(AutoSendDecider::class));

        Mail::assertNothingSent();
        $this->assertSame(0, AutoSendAudit::count());
    }

    public function test_it_cancels_when_killswitch_flipped_mode_to_manual_during_window(): void
    {
        Mail::fake();

        $reply = $this->scheduledReplyOnAutoRestaurant();

        // Killswitch fires while we wait — restaurant flips to manual.
        $reply->reservationRequest->restaurant
            ->forceFill(['send_mode' => SendMode::Manual])
            ->save();

        (new ScheduledAutoSendJob($reply->id))->handle(app(AutoSendDecider::class));

        Mail::assertNothingSent();
        $reply->refresh();
        $this->assertSame(ReservationReplyStatus::CancelledAuto, $reply->status);

        $this->assertDatabaseHas('auto_send_audits', [
            'reservation_reply_id' => $reply->id,
            'decision' => AutoSendAudit::DECISION_CANCELLED_AUTO,
            'reason' => AutoSendAudit::REASON_KILLSWITCH_DURING_WINDOW,
        ]);
    }

    public function test_it_re_evaluates_hard_gates_in_scheduled_job_before_sending(): void
    {
        Mail::fake();

        $reply = $this->scheduledReplyOnAutoRestaurant();

        // While we waited, party size grew over the limit → short_notice
        // gate is harder to engineer deterministically without time travel,
        // so we use party_size which is also a hard gate.
        $reply->reservationRequest
            ->forceFill(['party_size' => 99])
            ->save();
        $reply->reservationRequest->restaurant
            ->forceFill(['auto_send_party_size_max' => 10])
            ->save();

        (new ScheduledAutoSendJob($reply->id))->handle(app(AutoSendDecider::class));

        Mail::assertNothingSent();
        $reply->refresh();
        $this->assertSame(ReservationReplyStatus::Draft, $reply->status);

        $this->assertDatabaseHas('auto_send_audits', [
            'reservation_reply_id' => $reply->id,
            'decision' => AutoSendAudit::DECISION_CANCELLED_AUTO,
            'reason' => AutoSendAudit::REASON_HARD_GATE_LATE_PREFIX.AutoSendDecider::REASON_PARTY_SIZE_OVER_LIMIT,
        ]);
    }

    /**
     * Build a fresh reply already in `scheduled_auto_send` on an
     * Auto-mode restaurant, with a returning guest so the hard-gate
     * cascade lets it through cleanly.
     */
    private function scheduledReplyOnAutoRestaurant(): ReservationReply
    {
        $restaurant = Restaurant::factory()->create();
        $restaurant->forceFill(['send_mode' => SendMode::Auto])->save();

        // Prior confirmed reservation under the same email so the
        // first_time_guest gate doesn't trip.
        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'guest_email' => 'regular@example.com',
            'status' => ReservationStatus::Confirmed,
        ]);

        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'guest_email' => 'regular@example.com',
            'source' => ReservationSource::WebForm,
            'desired_at' => now()->addDays(3),
            'party_size' => 4,
            'status' => ReservationStatus::New,
            'needs_manual_review' => false,
        ]);

        return ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::ScheduledAutoSend,
            'body' => 'Vielen Dank für Ihre Anfrage – wir bestätigen Ihren Tisch gerne.',
        ]);
    }
}

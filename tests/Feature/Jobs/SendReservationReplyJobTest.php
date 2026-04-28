<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\ReservationReplyStatus;
use App\Enums\ReservationStatus;
use App\Jobs\SendReservationReplyJob;
use App\Mail\ReservationReplyMail;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class SendReservationReplyJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeReply(array $replyOverrides = [], array $requestOverrides = []): ReservationReply
    {
        $restaurant = Restaurant::factory()->create();
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'status' => ReservationStatus::InReview,
            'guest_email' => 'guest@example.com',
            ...$requestOverrides,
        ]);

        return ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::Approved,
            'body' => 'Guten Tag, gerne!',
            ...$replyOverrides,
        ]);
    }

    public function test_it_sends_the_mail_and_transitions_status_on_success(): void
    {
        Mail::fake();

        $reply = $this->makeReply();

        (new SendReservationReplyJob($reply->id))->handle();

        Mail::assertSent(ReservationReplyMail::class, function (ReservationReplyMail $mail) use ($reply): bool {
            return $mail->reply->is($reply)
                && $mail->hasTo('guest@example.com');
        });

        $reply->refresh();
        $this->assertSame(ReservationReplyStatus::Sent, $reply->status);
        $this->assertNotNull($reply->sent_at);
        $this->assertNull($reply->error_message);

        $this->assertSame(ReservationStatus::Replied, $reply->reservationRequest->fresh()->status);
    }

    public function test_handle_records_error_message_but_keeps_status_approved_for_retry(): void
    {
        Mail::fake();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP server unreachable'));

        $reply = $this->makeReply();

        try {
            (new SendReservationReplyJob($reply->id))->handle();
            $this->fail('Expected the job to rethrow so Laravel retries.');
        } catch (RuntimeException) {
            // expected — Laravel reschedules per the backoff array.
        }

        $reply->refresh();
        // Status must stay Approved so subsequent retries are NOT skipped
        // by the early-return Sent/Failed guard. Only failed() flips Failed.
        $this->assertSame(ReservationReplyStatus::Approved, $reply->status);
        $this->assertSame('SMTP server unreachable', $reply->error_message);
        // Request status must NOT advance to Replied on failure.
        $this->assertSame(ReservationStatus::InReview, $reply->reservationRequest->fresh()->status);
    }

    public function test_handle_does_not_skip_a_retry_after_a_previous_attempt_threw(): void
    {
        Mail::fake();
        $invocations = 0;
        Mail::shouldReceive('to')->andReturnUsing(function () use (&$invocations) {
            $invocations++;
            throw new RuntimeException('SMTP timeout');
        });

        $reply = $this->makeReply();
        $job = new SendReservationReplyJob($reply->id);

        try {
            $job->handle();
        } catch (RuntimeException) {
            // first attempt — Laravel would reschedule per backoff[].
        }
        try {
            $job->handle();
        } catch (RuntimeException) {
            // second attempt — must reach Mail::to again, not be skipped.
        }

        $this->assertSame(2, $invocations, 'Second attempt was skipped — retry policy is broken.');
    }

    public function test_it_skips_already_sent_or_failed_replies(): void
    {
        Mail::fake();

        $sent = $this->makeReply(['status' => ReservationReplyStatus::Sent, 'sent_at' => now()]);
        (new SendReservationReplyJob($sent->id))->handle();

        $failed = $this->makeReply(['status' => ReservationReplyStatus::Failed, 'error_message' => 'old']);
        (new SendReservationReplyJob($failed->id))->handle();

        Mail::assertNothingSent();
    }

    public function test_it_marks_failed_when_guest_email_is_missing(): void
    {
        Mail::fake();

        $reply = $this->makeReply([], ['guest_email' => null]);

        (new SendReservationReplyJob($reply->id))->handle();

        Mail::assertNothingSent();
        $reply->refresh();
        $this->assertSame(ReservationReplyStatus::Failed, $reply->status);
        $this->assertSame('Guest email is missing.', $reply->error_message);
    }

    public function test_failed_callback_marks_reply_failed_with_trimmed_message(): void
    {
        $reply = $this->makeReply();

        (new SendReservationReplyJob($reply->id))->failed(new RuntimeException('  retries exhausted  '));

        $reply->refresh();
        $this->assertSame(ReservationReplyStatus::Failed, $reply->status);
        $this->assertSame('retries exhausted', $reply->error_message);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationReplyStatus;
use App\Enums\ReservationStatus;
use App\Events\ReservationRequestReceived;
use App\Exceptions\AI\OpenAiAuthenticationException;
use App\Jobs\SendReservationReplyJob;
use App\Mail\ReservationReplyMail;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\AI\Contracts\ReplyGenerator;
use App\Services\AI\OpenAiReplyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

/**
 * The end-to-end flow test from PRD-005's "Tests" section / issue #79.
 * One file, one test per documented case, exercising the chain
 *
 *   ReservationRequestReceived
 *     → GenerateReservationReplyListener
 *     → GenerateReservationReplyJob
 *     → ReservationContextBuilder + OpenAiReplyGenerator
 *     → ReservationReply (status: draft, ai_prompt_snapshot: ...)
 *     → operator approval (POST /reservation-replies/{reply}/approve)
 *     → SendReservationReplyJob
 *     → ReservationReplyMail
 *
 * The companion per-component tests cover edges in depth; this file is the
 * one that proves the wires are connected.
 */
class ReservationReplyFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(): ReservationRequest
    {
        $restaurant = Restaurant::factory()->create([
            'timezone' => 'Europe/Berlin',
        ]);

        return ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'guest_email' => 'guest@example.com',
            'desired_at' => Carbon::create(2026, 5, 13, 17, 0, 0, 'UTC'),
            'party_size' => 4,
        ]);
    }

    private function fakeOpenAi(string $body): void
    {
        $fake = OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => $body], 'finish_reason' => 'stop'],
                ],
            ]),
        ]);

        // OpenAI::fake() only swaps the `openai` facade alias. The
        // generator resolves \OpenAI\Contracts\ClientContract via DI, which
        // is a separate singleton — also bind the fake there so the chain
        // through the queued job uses it instead of the real client.
        $this->app->instance(ClientContract::class, $fake);
    }

    public function test_it_creates_a_draft_reply_when_a_new_reservation_request_is_received(): void
    {
        $this->fakeOpenAi('Guten Tag, gerne!');
        $request = $this->makeRequest();

        ReservationRequestReceived::dispatch($request);

        /** @var ReservationReply $reply */
        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();
        $this->assertSame(ReservationReplyStatus::Draft, $reply->status);
        $this->assertSame('Guten Tag, gerne!', $reply->body);
    }

    public function test_it_stores_the_context_snapshot(): void
    {
        $this->fakeOpenAi('ok');
        $request = $this->makeRequest();

        ReservationRequestReceived::dispatch($request);

        /** @var ReservationReply $reply */
        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();
        $this->assertNotNull($reply->ai_prompt_snapshot);
        $this->assertSame($request->guest_name, $reply->ai_prompt_snapshot['request']['guest_name']);
        $this->assertSame(4, $reply->ai_prompt_snapshot['request']['party_size']);
    }

    public function test_it_sends_an_approved_reply_via_mail(): void
    {
        Mail::fake();
        $this->fakeOpenAi('Guten Tag, wir freuen uns!');

        $request = $this->makeRequest();
        ReservationRequestReceived::dispatch($request);

        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();
        $user = User::factory()->create(['restaurant_id' => $request->restaurant_id]);

        $this->actingAs($user)
            ->post(route('reservation-replies.approve', $reply))
            ->assertRedirect();

        Mail::assertSent(ReservationReplyMail::class, fn (ReservationReplyMail $mail): bool => $mail->hasTo('guest@example.com'));
        $this->assertSame(ReservationReplyStatus::Sent, $reply->fresh()->status);
        $this->assertSame(ReservationStatus::Replied, $request->fresh()->status);
    }

    public function test_it_uses_the_edited_body_when_operator_modified_the_draft(): void
    {
        Mail::fake();
        $this->fakeOpenAi('Original AI draft.');

        $request = $this->makeRequest();
        ReservationRequestReceived::dispatch($request);

        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();
        $user = User::factory()->create(['restaurant_id' => $request->restaurant_id]);

        $this->actingAs($user)->post(route('reservation-replies.approve', $reply), [
            'body' => 'Vom Gastronom überarbeiteter Text.',
        ]);

        Mail::assertSent(ReservationReplyMail::class, function (ReservationReplyMail $mail): bool {
            return str_contains($mail->render(), 'Vom Gastronom überarbeiteter Text.')
                && ! str_contains($mail->render(), 'Original AI draft.');
        });
    }

    public function test_it_marks_reply_as_failed_when_mail_sending_throws(): void
    {
        Mail::fake();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP unreachable'));
        $this->fakeOpenAi('ok');

        $request = $this->makeRequest();
        ReservationRequestReceived::dispatch($request);
        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();
        $user = User::factory()->create(['restaurant_id' => $request->restaurant_id]);

        // The send job rethrows on failure (so Laravel retries). Catch it
        // here because we're running on the sync queue inline with the POST.
        try {
            $this->actingAs($user)->post(route('reservation-replies.approve', $reply));
        } catch (\RuntimeException) {
            // expected — last attempt would call failed() in production.
        }

        // After all sync-queue attempts, the failed() callback flips Failed.
        // For a deterministic assertion here, drive failed() directly.
        (new SendReservationReplyJob($reply->id))->failed(new \RuntimeException('SMTP unreachable'));

        $reply->refresh();
        $this->assertSame(ReservationReplyStatus::Failed, $reply->status);
        $this->assertSame('SMTP unreachable', $reply->error_message);
    }

    public function test_it_forbids_approval_of_replies_from_other_restaurants(): void
    {
        $this->fakeOpenAi('ok');
        $request = $this->makeRequest();
        ReservationRequestReceived::dispatch($request);
        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();

        $intruder = User::factory()->create([
            'restaurant_id' => Restaurant::factory()->create()->id,
        ]);

        // Cross-tenant probes are filtered by the global RestaurantScope
        // before the policy gate fires, so the response is 404 — either
        // 403 or 404 keeps the reply private; 404 is the project's
        // tenant-isolation contract.
        $this->actingAs($intruder)
            ->post(route('reservation-replies.approve', $reply))
            ->assertNotFound();

        $this->assertSame(ReservationReplyStatus::Draft, $reply->fresh()->status);
    }

    public function test_it_uses_the_fallback_text_when_openai_fails(): void
    {
        // Simulate the job-layer fallback by binding a generator that
        // throws — this is the path the job actively guards against.
        // (The OpenAI client itself catches generic errors and returns
        // the fallback in-place, in which case the job sees a normal
        // string and there's nothing to flag — that branch is exercised
        // by the per-component generator tests.)
        $this->app->bind(ReplyGenerator::class, fn () => new class implements ReplyGenerator
        {
            public function generate(array $context): string
            {
                throw new OpenAiAuthenticationException;
            }
        });

        $request = $this->makeRequest();
        ReservationRequestReceived::dispatch($request);

        /** @var ReservationReply $reply */
        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();
        $this->assertSame(ReservationReplyStatus::Draft, $reply->status);
        $this->assertSame(OpenAiReplyGenerator::FALLBACK_TEXT, $reply->body);
        $this->assertTrue($request->fresh()->needs_manual_review);
    }
}

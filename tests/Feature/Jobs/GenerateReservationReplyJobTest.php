<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\ReservationReplyStatus;
use App\Events\ReservationRequestReceived;
use App\Jobs\GenerateReservationReplyJob;
use App\Listeners\GenerateReservationReplyListener;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\AI\Contracts\ReplyGenerator;
use App\Services\AI\OpenAiReplyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class GenerateReservationReplyJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(): ReservationRequest
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'Europe/Berlin']);

        return ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'desired_at' => Carbon::create(2026, 5, 13, 17, 0, 0, 'UTC'),
            'party_size' => 4,
        ]);
    }

    /**
     * Bind a fake ReplyGenerator that returns the given body (or throws).
     */
    private function bindGenerator(string|RuntimeException $bodyOrError): void
    {
        $this->app->bind(ReplyGenerator::class, fn () => new class($bodyOrError) implements ReplyGenerator
        {
            public function __construct(private readonly string|RuntimeException $bodyOrError) {}

            public function generate(array $context): string
            {
                if ($this->bodyOrError instanceof RuntimeException) {
                    throw $this->bodyOrError;
                }

                return $this->bodyOrError;
            }
        });
    }

    public function test_listener_dispatches_the_job_when_event_is_received(): void
    {
        Queue::fake();

        $request = $this->makeRequest();

        (new GenerateReservationReplyListener)->handle(new ReservationRequestReceived($request));

        Queue::assertPushed(GenerateReservationReplyJob::class, fn (GenerateReservationReplyJob $job): bool => $job->reservationRequestId === $request->id);
    }

    public function test_event_dispatch_routes_through_the_listener(): void
    {
        Queue::fake();

        $request = $this->makeRequest();

        ReservationRequestReceived::dispatch($request);

        Queue::assertPushed(GenerateReservationReplyJob::class);
    }

    public function test_job_persists_a_draft_reply_with_the_context_snapshot(): void
    {
        $request = $this->makeRequest();
        $this->bindGenerator('Guten Tag, gerne!');

        (new GenerateReservationReplyJob($request->id))->handle();

        /** @var ReservationReply $reply */
        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();

        $this->assertSame(ReservationReplyStatus::Draft, $reply->status);
        $this->assertSame('Guten Tag, gerne!', $reply->body);
        $this->assertIsArray($reply->ai_prompt_snapshot);
        $this->assertSame($request->guest_name, $reply->ai_prompt_snapshot['request']['guest_name']);
        $this->assertFalse($request->fresh()->needs_manual_review);
    }

    public function test_throwable_during_generation_flags_manual_review_and_stores_fallback_draft(): void
    {
        $request = $this->makeRequest();
        $this->bindGenerator(new RuntimeException('boom'));

        (new GenerateReservationReplyJob($request->id))->handle();

        $this->assertTrue($request->fresh()->needs_manual_review);

        /** @var ReservationReply $reply */
        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();
        $this->assertSame(ReservationReplyStatus::Draft, $reply->status);
        $this->assertSame(OpenAiReplyGenerator::FALLBACK_TEXT, $reply->body);
    }

    public function test_job_is_a_no_op_for_a_missing_request(): void
    {
        $this->bindGenerator('should-not-be-called');

        (new GenerateReservationReplyJob(999_999))->handle();

        $this->assertSame(0, ReservationReply::withoutGlobalScopes()->count());
    }

    public function test_job_falls_back_when_openai_key_is_missing(): void
    {
        // No generator bound and no API key → app(ReplyGenerator::class) throws.
        // The job must still leave a usable draft and flag manual review.
        config()->set('openai.api_key', null);
        $request = $this->makeRequest();

        (new GenerateReservationReplyJob($request->id))->handle();

        $this->assertTrue($request->fresh()->needs_manual_review);

        /** @var ReservationReply $reply */
        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();
        $this->assertSame(OpenAiReplyGenerator::FALLBACK_TEXT, $reply->body);
    }
}

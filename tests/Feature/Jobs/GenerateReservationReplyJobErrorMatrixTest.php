<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\ReservationReplyStatus;
use App\Events\OpenAiAuthenticationFailed;
use App\Exceptions\AI\OpenAiAuthenticationException;
use App\Exceptions\AI\OpenAiRateLimitException;
use App\Jobs\GenerateReservationReplyJob;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\AI\Contracts\ReplyGenerator;
use App\Services\AI\OpenAiReplyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * Verifies the full PRD-005 OpenAI error matrix at the job layer:
 *   - HTTP 401 → admin event dispatched, fallback stored, no retry
 *   - HTTP 429 → release once with 60s delay, then fallback on second pass
 *   - HTTP 5xx / timeout / unknown → immediate fallback, no retry
 *   - Every fallback path leaves needs_manual_review = true
 *
 * Tests use a controllable test-double for `attempts()` and `release()` so
 * the retry behaviour is exercised without booting a real queue worker.
 */
class GenerateReservationReplyJobErrorMatrixTest extends TestCase
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

    private function bindGenerator(\Throwable $error): void
    {
        $this->app->bind(ReplyGenerator::class, fn () => new class($error) implements ReplyGenerator
        {
            public function __construct(private readonly \Throwable $error) {}

            public function generate(array $context): string
            {
                throw $this->error;
            }
        });
    }

    /**
     * Build a job whose `attempts()` and `release()` we can drive from the test.
     */
    private function makeJob(int $reservationRequestId, int $attempts = 1): GenerateReservationReplyJob
    {
        return new class($reservationRequestId, $attempts) extends GenerateReservationReplyJob
        {
            public int $releasedWithDelay = -1;

            public function __construct(int $id, public int $stubbedAttempts)
            {
                parent::__construct($id);
            }

            public function attempts(): int
            {
                return $this->stubbedAttempts;
            }

            public function release($delay = 0): mixed
            {
                $this->releasedWithDelay = (int) $delay;

                return null;
            }
        };
    }

    public function test_http_401_dispatches_admin_event_stores_fallback_and_does_not_release(): void
    {
        Event::fake([OpenAiAuthenticationFailed::class]);

        $request = $this->makeRequest();
        $this->bindGenerator(new OpenAiAuthenticationException);

        $job = $this->makeJob($request->id, attempts: 1);
        $job->handle();

        Event::assertDispatched(OpenAiAuthenticationFailed::class);
        $this->assertSame(-1, $job->releasedWithDelay, '401 must not release for retry.');

        $this->assertTrue($request->fresh()->needs_manual_review);
        /** @var ReservationReply $reply */
        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();
        $this->assertSame(ReservationReplyStatus::Draft, $reply->status);
        $this->assertSame(OpenAiReplyGenerator::FALLBACK_TEXT, $reply->body);
        $this->assertTrue($reply->is_fallback, '401 fallback must set is_fallback flag for the auto-send hard gate.');
    }

    public function test_http_429_first_attempt_releases_with_60_seconds_and_writes_no_draft(): void
    {
        $request = $this->makeRequest();
        $this->bindGenerator(new OpenAiRateLimitException);

        $job = $this->makeJob($request->id, attempts: 1);
        $job->handle();

        $this->assertSame(GenerateReservationReplyJob::RATE_LIMIT_RETRY_DELAY_SECONDS, $job->releasedWithDelay);
        $this->assertSame(0, ReservationReply::withoutGlobalScopes()->count());
        $this->assertFalse($request->fresh()->needs_manual_review);
    }

    public function test_http_429_second_attempt_stores_fallback_and_marks_manual_review(): void
    {
        $request = $this->makeRequest();
        $this->bindGenerator(new OpenAiRateLimitException);

        $job = $this->makeJob($request->id, attempts: 2);
        $job->handle();

        $this->assertSame(-1, $job->releasedWithDelay, 'Second 429 must not re-release.');
        $this->assertTrue($request->fresh()->needs_manual_review);

        /** @var ReservationReply $reply */
        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();
        $this->assertSame(OpenAiReplyGenerator::FALLBACK_TEXT, $reply->body);
        $this->assertTrue($reply->is_fallback, '429-after-retry fallback must set is_fallback flag.');
    }

    public function test_5xx_or_timeout_falls_back_immediately_with_no_retry(): void
    {
        Event::fake([OpenAiAuthenticationFailed::class]);

        $request = $this->makeRequest();
        $this->bindGenerator(new RuntimeException('upstream timeout'));

        $job = $this->makeJob($request->id, attempts: 1);
        $job->handle();

        Event::assertNotDispatched(OpenAiAuthenticationFailed::class);
        $this->assertSame(-1, $job->releasedWithDelay);
        $this->assertTrue($request->fresh()->needs_manual_review);

        /** @var ReservationReply $reply */
        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();
        $this->assertSame(OpenAiReplyGenerator::FALLBACK_TEXT, $reply->body);
        $this->assertTrue($reply->is_fallback, 'timeout / 5xx fallback must set is_fallback flag.');
    }

    public function test_happy_path_does_not_set_is_fallback_flag(): void
    {
        $request = $this->makeRequest();
        $this->app->bind(ReplyGenerator::class, fn () => new class implements ReplyGenerator
        {
            public function generate(array $context): string
            {
                return 'Generierter Antworttext.';
            }
        });

        $job = $this->makeJob($request->id, attempts: 1);
        $job->handle();

        /** @var ReservationReply $reply */
        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();
        $this->assertFalse($reply->is_fallback, 'Successful generation must leave is_fallback at false.');
    }
}

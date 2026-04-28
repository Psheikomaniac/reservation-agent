<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateReservationReplyJob;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\AI\Contracts\ReplyGenerator;
use App\Services\AI\ReservationContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Pins the PRD-005 / issue #85 AC that every persisted ReservationReply
 * carries the full Context-JSON in `ai_prompt_snapshot`. The snapshot is
 * the only audit trail the dashboard has for "what did the AI actually
 * see when it produced this draft?", so a missing or partial snapshot
 * would break post-incident debugging.
 *
 * Covered branches:
 *   - happy path: snapshot is byte-identical to what the builder
 *     produced (and what was fed to the generator),
 *   - generator-throws fallback: snapshot still carries the context
 *     that WOULD have been sent.
 *
 * Builder-throws is excluded by design: if the builder cannot produce a
 * context, there is by definition nothing to snapshot. The job still
 * stores a fallback draft (with snapshot = null) so the dashboard never
 * has nothing to show; that branch is exercised by the existing
 * `test_throwable_during_generation_flags_manual_review_and_stores_fallback_draft`.
 */
class GenerateReservationReplySnapshotTest extends TestCase
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

    public function test_happy_path_snapshot_is_byte_identical_to_builder_output(): void
    {
        $request = $this->makeRequest();
        $expected = (new ReservationContextBuilder)->build($request);

        $this->bindGenerator('Guten Tag, gerne!');

        (new GenerateReservationReplyJob($request->id))->handle();

        /** @var ReservationReply $reply */
        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();
        $this->assertNotNull($reply->ai_prompt_snapshot);
        $this->assertSame($expected, $reply->ai_prompt_snapshot);
    }

    public function test_snapshot_contains_every_top_level_block(): void
    {
        $request = $this->makeRequest();
        $this->bindGenerator('ok');

        (new GenerateReservationReplyJob($request->id))->handle();

        /** @var ReservationReply $reply */
        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();

        $this->assertArrayHasKey('restaurant', $reply->ai_prompt_snapshot);
        $this->assertArrayHasKey('request', $reply->ai_prompt_snapshot);
        $this->assertArrayHasKey('availability', $reply->ai_prompt_snapshot);

        // Every documented field must be present, even when null/empty.
        $this->assertArrayHasKey('is_open_at_desired_time', $reply->ai_prompt_snapshot['availability']);
        $this->assertArrayHasKey('seats_free_at_desired', $reply->ai_prompt_snapshot['availability']);
        $this->assertArrayHasKey('alternative_slots', $reply->ai_prompt_snapshot['availability']);
        $this->assertArrayHasKey('closed_reason', $reply->ai_prompt_snapshot['availability']);
    }

    public function test_generator_failure_still_persists_the_context_that_would_have_been_sent(): void
    {
        $request = $this->makeRequest();
        $expected = (new ReservationContextBuilder)->build($request);

        $this->bindGenerator(new RuntimeException('upstream timeout'));

        (new GenerateReservationReplyJob($request->id))->handle();

        /** @var ReservationReply $reply */
        $reply = ReservationReply::withoutGlobalScopes()->where('reservation_request_id', $request->id)->sole();
        $this->assertNotNull(
            $reply->ai_prompt_snapshot,
            'Fallback draft must still carry the context that WOULD have been sent.'
        );
        $this->assertSame($expected, $reply->ai_prompt_snapshot);
    }
}

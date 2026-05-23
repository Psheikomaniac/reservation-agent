<?php

declare(strict_types=1);

namespace Tests\Feature\AutoSend;

use App\Enums\ReservationReplyStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Events\ReservationRequestReceived;
use App\Jobs\GenerateReservationReplyJob;
use App\Mail\ReservationReplyMail;
use App\Models\ReservationMessage;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Services\AI\OpenAiReplyGenerator;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * End-to-end coverage of the PRD-014 web sync-confirm path on the public
 * submit endpoint (#355): a free-slot web reservation is confirmed inside
 * the request, with a synchronous AI reply, an auto table assignment and an
 * immediate mail — falling back to the unchanged V1 path on any failure.
 */
class WebSyncConfirmFlowTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen well before the desired slot so the lead-time gate passes;
        // UTC timezone keeps local form input equal to the stored UTC instant.
        Carbon::setTestNow('2026-05-23 12:00:00');

        $dinner = [['from' => '17:00', 'to' => '23:00']];
        $this->restaurant = Restaurant::factory()->create([
            'slug' => 'demo',
            'timezone' => 'UTC',
            'slot_buffer_minutes' => 90,
            'opening_hours' => [
                'mon' => $dinner, 'tue' => $dinner, 'wed' => $dinner, 'thu' => $dinner,
                'fri' => $dinner, 'sat' => $dinner, 'sun' => $dinner,
            ],
            'web_sync_confirm_enabled' => true,
            'auto_send_party_size_max' => 10,
            'auto_send_min_lead_time_minutes' => 90,
        ]);
        Table::factory()->for($this->restaurant)->create(['seats' => 8]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'guest_name' => 'Alice Example',
            'guest_email' => 'alice@gmail.com',
            'guest_phone' => '+49 30 1234567',
            'party_size' => 4,
            'desired_at' => '2026-06-15 19:00',
            'message' => 'Fensterplatz wäre toll.',
        ], $overrides);
    }

    /**
     * Bind the generator to the OpenAI fake without the production
     * sync-client factory, so `generateSync` uses the faked client instead
     * of building a real timeout-bounded one.
     *
     * @param  array<int, mixed>  $responses
     */
    private function fakeOpenAi(array $responses): void
    {
        $fake = OpenAI::fake($responses);

        $this->app->bind(OpenAiReplyGenerator::class, fn ($app): OpenAiReplyGenerator => new OpenAiReplyGenerator(
            $fake,
            $app->make(LoggerInterface::class),
        ));
    }

    private function replyResponse(string $body = 'Guten Tag Alice, Ihr Tisch ist bestätigt.'): CreateResponse
    {
        return CreateResponse::fake([
            'choices' => [
                ['index' => 0, 'message' => ['role' => 'assistant', 'content' => $body], 'finish_reason' => 'stop'],
            ],
        ]);
    }

    private function timeoutException(): TransporterException
    {
        return new TransporterException(
            new ConnectException(
                'cURL error 28: Operation timed out',
                new Request('POST', 'https://api.openai.com/v1/chat/completions'),
            ),
        );
    }

    public function test_sync_confirm_succeeds_and_sends_mail(): void
    {
        Event::fake([ReservationRequestReceived::class]);
        Mail::fake();
        $this->fakeOpenAi([$this->replyResponse()]);

        $this->withHeaders(['X-Inertia' => 'true'])
            ->post(route('public.reservations.store', $this->restaurant), $this->validPayload())
            ->assertOk();

        $reservation = ReservationRequest::withoutGlobalScopes()->sole();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(ReservationSource::WebForm, $reservation->source);

        $reply = ReservationReply::withoutGlobalScopes()->sole();
        $this->assertTrue($reply->sync_confirm);
        $this->assertSame(ReservationReplyStatus::Sent, $reply->status);
        $this->assertNotNull($reply->sent_at);

        Mail::assertSent(ReservationReplyMail::class);
        // The sync path handled everything — the async draft pipeline must not fire.
        Event::assertNotDispatched(ReservationRequestReceived::class);
    }

    public function test_sync_confirm_renders_the_confirmed_sync_page(): void
    {
        Mail::fake();
        $this->fakeOpenAi([$this->replyResponse()]);

        $this->post(route('public.reservations.store', $this->restaurant), $this->validPayload())
            // Second arg false: the Vue page lands in #357; skip the file check.
            ->assertInertia(fn ($page) => $page
                ->component('Public/ConfirmedSync', false)
                ->where('reservation.party_size', 4)
                ->where('reservation.date', '2026-06-15')
                ->where('reservation.time', '19:00')
                ->where('reservation.guest_email_masked', 'a…@gmail.com')
                ->where('restaurant.name', $this->restaurant->name)
            );
    }

    public function test_sync_confirm_creates_a_table_assignment(): void
    {
        Mail::fake();
        $this->fakeOpenAi([$this->replyResponse()]);

        $this->withHeaders(['X-Inertia' => 'true'])
            ->post(route('public.reservations.store', $this->restaurant), $this->validPayload())
            ->assertOk();

        $reservation = ReservationRequest::withoutGlobalScopes()->sole();
        $table = Table::query()->withoutGlobalScopes()->sole();

        $assignment = ReservationTableAssignment::withoutGlobalScopes()->sole();
        $this->assertSame($reservation->id, $assignment->reservation_request_id);
        $this->assertSame($table->id, $assignment->table_id);
        $this->assertNull($assignment->assigned_by_user_id);
    }

    public function test_sync_confirm_falls_back_on_openai_timeout(): void
    {
        Event::fake([ReservationRequestReceived::class]);
        Mail::fake();
        $this->fakeOpenAi([$this->timeoutException()]);

        $this->post(route('public.reservations.store', $this->restaurant), $this->validPayload())
            ->assertRedirect(route('public.reservations.thanks', $this->restaurant));

        $reservation = ReservationRequest::withoutGlobalScopes()->sole();
        $this->assertSame(ReservationStatus::New, $reservation->status);
        $this->assertSame(0, ReservationReply::withoutGlobalScopes()->count());
        $this->assertSame(0, ReservationTableAssignment::withoutGlobalScopes()->count());

        Mail::assertNothingSent();
        // V1 path must take over so the request still gets an async draft.
        Event::assertDispatched(ReservationRequestReceived::class);
    }

    public function test_sync_confirm_falls_back_on_smtp_failure(): void
    {
        Event::fake([ReservationRequestReceived::class]);
        $this->fakeOpenAi([$this->replyResponse()]);

        // Make the synchronous send throw; the transaction must roll back the
        // reply + assignment and the controller must fall through to V1.
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new RuntimeException('smtp down'));

        $this->post(route('public.reservations.store', $this->restaurant), $this->validPayload())
            ->assertRedirect(route('public.reservations.thanks', $this->restaurant));

        $reservation = ReservationRequest::withoutGlobalScopes()->sole();
        $this->assertSame(ReservationStatus::New, $reservation->status);
        $this->assertSame(0, ReservationReply::withoutGlobalScopes()->count());
        $this->assertSame(0, ReservationTableAssignment::withoutGlobalScopes()->count());
        // The outbound message is written before the send, so the rollback must
        // also drop it — no orphaned audit row for a mail that never went out.
        $this->assertSame(0, ReservationMessage::withoutGlobalScopes()->count());

        Event::assertDispatched(ReservationRequestReceived::class);
    }

    public function test_two_submits_for_the_same_slot_confirm_only_once(): void
    {
        Mail::fake();
        // Both submits may attempt a sync reply; queue two responses.
        $this->fakeOpenAi([$this->replyResponse(), $this->replyResponse()]);

        // First submit takes the only table for the slot.
        $this->withHeaders(['X-Inertia' => 'true'])
            ->post(route('public.reservations.store', $this->restaurant), $this->validPayload())
            ->assertOk();

        // Second submit for the same slot can no longer find a free table, so it
        // falls back to the V1 path (status new). (lockForUpdate is a no-op on
        // SQLite; the in-transaction re-check is what serialises real bookings.)
        $this->post(route('public.reservations.store', $this->restaurant), $this->validPayload())
            ->assertRedirect(route('public.reservations.thanks', $this->restaurant));

        $confirmed = ReservationRequest::withoutGlobalScopes()
            ->where('status', ReservationStatus::Confirmed)->count();
        $new = ReservationRequest::withoutGlobalScopes()
            ->where('status', ReservationStatus::New)->count();

        $this->assertSame(1, $confirmed);
        $this->assertSame(1, $new);
        Mail::assertSent(ReservationReplyMail::class, 1);
    }

    public function test_v1_path_when_sync_confirm_is_disabled_for_the_restaurant(): void
    {
        Event::fake([ReservationRequestReceived::class]);
        Mail::fake();
        $this->restaurant->update(['web_sync_confirm_enabled' => false]);

        $this->post(route('public.reservations.store', $this->restaurant), $this->validPayload())
            ->assertRedirect(route('public.reservations.thanks', $this->restaurant));

        $reservation = ReservationRequest::withoutGlobalScopes()->sole();
        $this->assertSame(ReservationStatus::New, $reservation->status);
        $this->assertSame(0, ReservationReply::withoutGlobalScopes()->count());
        Mail::assertNothingSent();
        Event::assertDispatched(ReservationRequestReceived::class);
    }

    public function test_email_source_request_is_never_sync_confirmed(): void
    {
        // An email-ingested request runs the async draft pipeline, never the
        // sync-confirm path: the resulting reply must not be flagged sync_confirm.
        $this->fakeOpenAi([$this->replyResponse()]);

        $request = ReservationRequest::factory()->for($this->restaurant)->create([
            'source' => ReservationSource::Email,
            'status' => ReservationStatus::New,
            'desired_at' => Carbon::parse('2026-06-15 19:00', 'UTC'),
            'party_size' => 4,
        ]);

        (new GenerateReservationReplyJob($request->id))->handle();

        $reply = ReservationReply::withoutGlobalScopes()->sole();
        $this->assertFalse((bool) $reply->sync_confirm);
        $this->assertSame(0, ReservationTableAssignment::withoutGlobalScopes()->count());
    }
}

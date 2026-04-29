<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MessageDirection;
use App\Events\ReservationRequestReceived;
use App\Models\FailedEmailImport;
use App\Models\ReservationMessage;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Email\Contracts\ImapMailbox;
use App\Services\Email\Contracts\ImapMailboxFactory;
use App\Services\Email\DTO\FetchedEmail;
use App\Services\Email\EmailReservationParser;
use App\Services\Email\ThreadResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FetchReservationEmailsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $restaurantId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function failed(Throwable $exception): void
    {
        $restaurant = Restaurant::find($this->restaurantId);

        Log::error('reservation.imap.fetch_failed', [
            'restaurant_id' => $this->restaurantId,
            'host' => $restaurant?->imap_host,
            'username' => $restaurant?->imap_username,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    public function handle(
        ImapMailboxFactory $factory,
        EmailReservationParser $parser,
        ThreadResolver $threadResolver,
    ): void {
        $restaurant = Restaurant::find($this->restaurantId);

        if ($restaurant === null || $restaurant->imap_host === null || $restaurant->imap_host === '') {
            return;
        }

        $mailbox = $factory->open($restaurant);

        foreach ($mailbox->fetchUnseen() as $email) {
            try {
                $this->processEmail($email, $mailbox, $parser, $threadResolver, $restaurant);
            } catch (Throwable $e) {
                $this->recordFailure($restaurant, $email, $e);
            }
        }
    }

    private function processEmail(
        FetchedEmail $email,
        ImapMailbox $mailbox,
        EmailReservationParser $parser,
        ThreadResolver $threadResolver,
        Restaurant $restaurant,
    ): void {
        if ($email->messageId !== '' && $this->alreadyImported($email->messageId)) {
            $mailbox->markSeen($email);

            return;
        }

        if ($threadParent = $threadResolver->resolveForIncoming($email, $restaurant->id)) {
            $this->appendAsThreadMessage($threadParent, $email);
            $mailbox->markSeen($email);

            return;
        }

        $parsed = $parser->parseParts(
            body: $email->body,
            senderEmail: $email->senderEmail,
            senderName: $email->senderName,
            messageId: $email->messageId,
        );

        $attributes = $parsed->toReservationRequestAttributes($restaurant->id);
        $attributes['raw_payload'] = [
            'body' => $email->body,
            'sender_email' => $email->senderEmail,
            'sender_name' => $email->senderName,
            'message_id' => $email->messageId,
        ];

        try {
            $request = ReservationRequest::create($attributes);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $mailbox->markSeen($email);

                return;
            }

            throw $e;
        }

        $mailbox->markSeen($email);

        ReservationRequestReceived::dispatch($request);
    }

    private function alreadyImported(string $messageId): bool
    {
        return ReservationRequest::where('email_message_id', $messageId)->exists()
            || ReservationMessage::where('message_id', $messageId)->exists();
    }

    private function appendAsThreadMessage(ReservationRequest $parent, FetchedEmail $email): void
    {
        try {
            ReservationMessage::create([
                'reservation_request_id' => $parent->id,
                'direction' => MessageDirection::In,
                'message_id' => $email->messageId,
                'in_reply_to' => $email->inReplyTo !== '' ? $email->inReplyTo : null,
                'references' => $email->references !== '' ? $email->references : null,
                'subject' => $email->subject,
                'from_address' => $email->senderEmail,
                'to_address' => $email->toAddress,
                'body_plain' => $email->body,
                'raw_headers' => $email->rawHeaders,
                'received_at' => now(),
            ]);
        } catch (QueryException $e) {
            // The unique constraint on message_id makes thread-message inserts
            // idempotent — a duplicate fetch is a no-op, not an error path.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;

        return $sqlState === '23000' || $driverCode === 1062 || $driverCode === 19;
    }

    private function recordFailure(Restaurant $restaurant, FetchedEmail $email, Throwable $e): void
    {
        FailedEmailImport::create([
            'restaurant_id' => $restaurant->id,
            'message_id' => $email->messageId,
            'raw_headers' => $email->rawHeaders,
            'raw_body' => $email->rawBody,
            'error' => $e->getMessage(),
        ]);
    }
}

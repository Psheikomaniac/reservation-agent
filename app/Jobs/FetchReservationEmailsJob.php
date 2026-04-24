<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\ReservationRequestReceived;
use App\Models\FailedEmailImport;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Email\Contracts\ImapMailbox;
use App\Services\Email\Contracts\ImapMailboxFactory;
use App\Services\Email\DTO\FetchedEmail;
use App\Services\Email\EmailReservationParser;
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

    public function handle(ImapMailboxFactory $factory, EmailReservationParser $parser): void
    {
        $restaurant = Restaurant::find($this->restaurantId);

        if ($restaurant === null || $restaurant->imap_host === null || $restaurant->imap_host === '') {
            return;
        }

        $mailbox = $factory->open($restaurant);

        foreach ($mailbox->fetchUnseen() as $email) {
            try {
                $this->processEmail($email, $mailbox, $parser, $restaurant);
            } catch (Throwable $e) {
                $this->recordFailure($restaurant, $email, $e);
            }
        }
    }

    private function processEmail(
        FetchedEmail $email,
        ImapMailbox $mailbox,
        EmailReservationParser $parser,
        Restaurant $restaurant,
    ): void {
        if ($email->messageId !== '' && $this->alreadyImported($email->messageId)) {
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
        return ReservationRequest::where('email_message_id', $messageId)->exists();
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

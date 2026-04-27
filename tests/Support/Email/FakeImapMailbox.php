<?php

declare(strict_types=1);

namespace Tests\Support\Email;

use App\Services\Email\Contracts\ImapMailbox;
use App\Services\Email\DTO\FetchedEmail;
use RuntimeException;

final class FakeImapMailbox implements ImapMailbox
{
    /** @var list<FetchedEmail> */
    public array $seen = [];

    public ?string $failOn = null;

    /**
     * @param  list<FetchedEmail>  $emails
     */
    public function __construct(private array $emails) {}

    public function fetchUnseen(): array
    {
        return $this->emails;
    }

    public function markSeen(FetchedEmail $email): void
    {
        if ($this->failOn !== null && $email->messageId === $this->failOn) {
            throw new RuntimeException('boom');
        }

        $this->seen[] = $email;
    }
}

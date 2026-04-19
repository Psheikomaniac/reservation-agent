<?php

declare(strict_types=1);

namespace Tests\Support\Email;

use App\Models\Restaurant;
use App\Services\Email\Contracts\ImapMailbox;
use App\Services\Email\Contracts\ImapMailboxFactory;
use App\Services\Email\DTO\FetchedEmail;

final class FakeImapMailboxFactory implements ImapMailboxFactory
{
    public bool $opened = false;

    public FakeImapMailbox $mailbox;

    /**
     * @param  list<FetchedEmail>  $emails
     */
    public function __construct(array $emails)
    {
        $this->mailbox = new FakeImapMailbox($emails);
    }

    public function open(Restaurant $restaurant): ImapMailbox
    {
        $this->opened = true;

        return $this->mailbox;
    }
}

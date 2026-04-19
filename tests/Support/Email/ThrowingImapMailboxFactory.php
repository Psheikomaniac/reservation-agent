<?php

declare(strict_types=1);

namespace Tests\Support\Email;

use App\Models\Restaurant;
use App\Services\Email\Contracts\ImapMailbox;
use App\Services\Email\Contracts\ImapMailboxFactory;
use Throwable;

final class ThrowingImapMailboxFactory implements ImapMailboxFactory
{
    public function __construct(private Throwable $exception) {}

    public function open(Restaurant $restaurant): ImapMailbox
    {
        throw $this->exception;
    }
}

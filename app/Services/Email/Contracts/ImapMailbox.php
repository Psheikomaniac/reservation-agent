<?php

declare(strict_types=1);

namespace App\Services\Email\Contracts;

use App\Services\Email\DTO\FetchedEmail;

interface ImapMailbox
{
    /**
     * @return list<FetchedEmail>
     */
    public function fetchUnseen(): array;

    public function markSeen(FetchedEmail $email): void;
}

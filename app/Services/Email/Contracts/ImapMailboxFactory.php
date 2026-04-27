<?php

declare(strict_types=1);

namespace App\Services\Email\Contracts;

use App\Models\Restaurant;

interface ImapMailboxFactory
{
    public function open(Restaurant $restaurant): ImapMailbox;
}

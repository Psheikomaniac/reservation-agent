<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\Restaurant;
use App\Services\Email\Contracts\ImapMailbox;
use App\Services\Email\Contracts\ImapMailboxFactory;
use RuntimeException;
use Webklex\PHPIMAP\ClientManager;

final class WebklexImapMailboxFactory implements ImapMailboxFactory
{
    public function __construct(private readonly ClientManager $manager) {}

    public function open(Restaurant $restaurant): ImapMailbox
    {
        $client = $this->manager->make([
            'host' => (string) $restaurant->imap_host,
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => (string) $restaurant->imap_username,
            'password' => (string) $restaurant->imap_password,
            'protocol' => 'imap',
        ]);

        $client->connect();

        $inbox = $client->getFolderByPath('INBOX');

        if ($inbox === null) {
            throw new RuntimeException('INBOX folder not available on IMAP server');
        }

        return new WebklexImapMailbox($inbox);
    }
}

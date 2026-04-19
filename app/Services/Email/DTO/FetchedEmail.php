<?php

declare(strict_types=1);

namespace App\Services\Email\DTO;

final readonly class FetchedEmail
{
    public function __construct(
        public string $messageId,
        public string $body,
        public string $senderEmail,
        public ?string $senderName,
        public string $rawHeaders,
        public string $rawBody,
    ) {}
}

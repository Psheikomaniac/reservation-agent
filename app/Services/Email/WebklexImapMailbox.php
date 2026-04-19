<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Services\Email\Contracts\ImapMailbox;
use App\Services\Email\DTO\FetchedEmail;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

final class WebklexImapMailbox implements ImapMailbox
{
    /** @var array<string, Message> */
    private array $seenCache = [];

    public function __construct(
        private readonly Folder $inbox,
        private readonly HtmlToPlainText $htmlToPlainText = new HtmlToPlainText,
    ) {}

    public function fetchUnseen(): array
    {
        $messages = $this->inbox->messages()->unseen()->get();

        $result = [];
        foreach ($messages as $message) {
            /** @var Message $message */
            $messageId = (string) $message->getMessageId();
            $body = $this->extractBody($message);
            $sender = $this->extractSender($message);

            $fetched = new FetchedEmail(
                messageId: $messageId,
                body: $body,
                senderEmail: $sender?->mail ?? '',
                senderName: ($sender !== null && $sender->personal !== '') ? $sender->personal : null,
                rawHeaders: (string) $message->getHeader()->raw,
                rawBody: $body,
            );

            $this->seenCache[$messageId] = $message;
            $result[] = $fetched;
        }

        return $result;
    }

    public function markSeen(FetchedEmail $email): void
    {
        $message = $this->seenCache[$email->messageId] ?? null;
        $message?->setFlag('Seen');
    }

    private function extractBody(Message $message): string
    {
        if ($message->hasTextBody()) {
            return $message->getTextBody();
        }

        if ($message->hasHTMLBody()) {
            return $this->htmlToPlainText->convert($message->getHTMLBody());
        }

        return '';
    }

    private function extractSender(Message $message): ?Address
    {
        try {
            $from = $message->getFrom();
        } catch (Throwable) {
            return null;
        }

        if ($from === null) {
            return null;
        }

        $first = is_array($from) ? ($from[0] ?? null) : (method_exists($from, 'first') ? $from->first() : null);

        return $first instanceof Address ? $first : null;
    }
}

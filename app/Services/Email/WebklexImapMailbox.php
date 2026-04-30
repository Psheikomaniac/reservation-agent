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

            $senderName = ($sender !== null && $sender->personal !== '')
                ? $this->decodeMimeWord($sender->personal)
                : null;

            $fetched = new FetchedEmail(
                messageId: $messageId,
                body: $body,
                senderEmail: $sender?->mail ?? '',
                senderName: $senderName,
                rawHeaders: (string) $message->getHeader()->raw,
                rawBody: $body,
                inReplyTo: trim((string) $message->getInReplyTo()),
                references: trim((string) $message->getReferences()),
                subject: trim((string) $message->getSubject()),
                toAddress: $this->extractFirstRecipient($message),
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

    private function decodeMimeWord(string $value): string
    {
        $result = preg_replace_callback(
            '/=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/',
            static function (array $match): string {
                $charset = strtoupper($match[1]);
                $encoding = strtoupper($match[2]);
                $content = $match[3];

                $decoded = $encoding === 'B'
                    ? base64_decode($content, true)
                    : quoted_printable_decode(str_replace('_', ' ', $content));

                if (! is_string($decoded)) {
                    return $match[0];
                }

                if ($charset === 'UTF-8' || $charset === 'UTF8') {
                    return $decoded;
                }

                $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);

                return is_string($converted) ? $converted : $decoded;
            },
            $value,
        );

        return $result ?? $value;
    }

    private function extractFirstRecipient(Message $message): string
    {
        try {
            $to = $message->getTo();
        } catch (Throwable) {
            return '';
        }

        if ($to === null) {
            return '';
        }

        $first = is_array($to) ? ($to[0] ?? null) : (method_exists($to, 'first') ? $to->first() : null);

        return $first instanceof Address ? (string) $first->mail : '';
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

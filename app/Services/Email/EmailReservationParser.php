<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Services\Email\DTO\ParsedReservation;
use Carbon\CarbonImmutable;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Message;

final class EmailReservationParser
{
    public function __construct(
        private readonly HtmlToPlainText $htmlToPlainText = new HtmlToPlainText,
    ) {}

    private const PARTY_SIZE_PATTERN = '/(?:für|for)?\s*(\d{1,2})\s*(Personen|Pers\.?|Gäste|People|Guests)/iu';

    private const TIME_PATTERN = '/\b(\d{1,2})[:.](\d{2})\s*(Uhr|h|am|pm)?\b/i';

    private const TIME_WITH_SUFFIX_ONLY_PATTERN = '/\b(\d{1,2})\s*(Uhr|h|am|pm)\b/i';

    private const ISO_DATE_PATTERN = '/\b(\d{4})-(\d{2})-(\d{2})\b/';

    private const GERMAN_DOTTED_DATE_PATTERN = '/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/';

    private const GERMAN_LONG_DATE_PATTERN = '/\b(\d{1,2})\.?\s+(Januar|Februar|März|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember)\s+(\d{4})\b/iu';

    private const ENGLISH_DATE_PATTERN = '/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})\b/i';

    private const EMAIL_PATTERN = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';

    private const NAME_FROM_BODY_PATTERN = '/Mein Name ist\s+([\p{Lu}][\p{L}\-\']*(?:\s+[\p{Lu}][\p{L}\-\']*){0,3})/u';

    private const GERMAN_MONTHS = [
        'januar' => 1, 'februar' => 2, 'märz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6,
        'juli' => 7, 'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12,
    ];

    private const ENGLISH_MONTHS = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
        'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    private const NO_REPLY_PATTERN = '/(?:no[\-_.]?reply|do[\-_.]?not[\-_.]?reply)@/i';

    public function parse(Message $message): ParsedReservation
    {
        $sender = $this->extractSenderAddress($message);

        $personal = ($sender !== null && $sender->personal !== '')
            ? $this->decodeMimeWord($sender->personal)
            : null;

        return $this->parseParts(
            body: $this->extractBody($message),
            senderEmail: $sender?->mail ?? '',
            senderName: $personal,
            messageId: (string) $message->getMessageId(),
        );
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

    public function parseParts(
        string $body,
        string $senderEmail,
        ?string $senderName,
        string $messageId,
    ): ParsedReservation {
        $partySize = $this->extractPartySize($body);

        [$date, $bodyWithoutDate] = $this->extractDate($body);
        $time = $this->extractTime($bodyWithoutDate);

        $desiredAt = ($date !== null && $time !== null)
            ? $date->setTime($time[0], $time[1])
            : null;

        $guestEmail = $this->resolveGuestEmail($senderEmail, $body);
        $guestName = $this->resolveGuestName($senderName, $body, $guestEmail);

        return new ParsedReservation(
            guestName: $guestName,
            guestEmail: $guestEmail,
            guestPhone: null,
            partySize: $partySize,
            desiredAt: $desiredAt,
            message: trim($body),
            confidence: $this->scoreConfidence($partySize, $date, $time),
            messageId: $messageId,
        );
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

    private function extractSenderAddress(Message $message): ?Address
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

    private function extractPartySize(string $body): ?int
    {
        if (preg_match(self::PARTY_SIZE_PATTERN, $body, $matches) !== 1) {
            return null;
        }

        $size = (int) $matches[1];

        return $size > 0 ? $size : null;
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: string}
     */
    private function extractDate(string $body): array
    {
        $patterns = [
            self::ISO_DATE_PATTERN => fn (array $m) => $this->safeCreateDate((int) $m[1], (int) $m[2], (int) $m[3]),
            self::GERMAN_DOTTED_DATE_PATTERN => fn (array $m) => $this->safeCreateDate((int) $m[3], (int) $m[2], (int) $m[1]),
            self::GERMAN_LONG_DATE_PATTERN => fn (array $m) => ($month = self::GERMAN_MONTHS[mb_strtolower($m[2])] ?? null)
                ? $this->safeCreateDate((int) $m[3], $month, (int) $m[1])
                : null,
            self::ENGLISH_DATE_PATTERN => fn (array $m) => ($month = self::ENGLISH_MONTHS[strtolower($m[1])] ?? null)
                ? $this->safeCreateDate((int) $m[3], $month, (int) $m[2])
                : null,
        ];

        foreach ($patterns as $pattern => $builder) {
            if (preg_match($pattern, $body, $matches, PREG_OFFSET_CAPTURE) === 1) {
                $date = $builder(array_map(fn ($entry) => $entry[0], $matches));
                if ($date === null) {
                    continue;
                }
                $offset = $matches[0][1];
                $length = strlen($matches[0][0]);
                $stripped = substr_replace($body, str_repeat(' ', $length), $offset, $length);

                return [$date, $stripped];
            }
        }

        return [null, $body];
    }

    private function safeCreateDate(int $year, int $month, int $day): ?CarbonImmutable
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0);
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function extractTime(string $body): ?array
    {
        if (preg_match(self::TIME_PATTERN, $body, $matches) === 1) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];
            $suffix = strtolower($matches[3] ?? '');
        } elseif (preg_match(self::TIME_WITH_SUFFIX_ONLY_PATTERN, $body, $matches) === 1) {
            $hour = (int) $matches[1];
            $minute = 0;
            $suffix = strtolower($matches[2]);
        } else {
            return null;
        }

        if ($suffix === 'pm' && $hour >= 1 && $hour <= 11) {
            $hour += 12;
        } elseif ($suffix === 'am' && $hour === 12) {
            $hour = 0;
        }

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return [$hour, $minute];
    }

    private function resolveGuestEmail(string $senderEmail, string $body): string
    {
        if ($senderEmail !== '' && ! $this->isNoReply($senderEmail)) {
            return $senderEmail;
        }

        if (preg_match_all(self::EMAIL_PATTERN, $body, $matches) > 0) {
            foreach ($matches[0] as $candidate) {
                if (! $this->isNoReply($candidate)) {
                    return $candidate;
                }
            }
        }

        return $senderEmail;
    }

    private function isNoReply(string $email): bool
    {
        return preg_match(self::NO_REPLY_PATTERN, $email) === 1;
    }

    private function resolveGuestName(?string $senderName, string $body, string $guestEmail): string
    {
        $trimmed = $senderName !== null ? trim($senderName) : '';

        if ($trimmed !== '') {
            return $trimmed;
        }

        if (preg_match(self::NAME_FROM_BODY_PATTERN, $body, $matches) === 1) {
            return trim($matches[1]);
        }

        if ($guestEmail !== '' && str_contains($guestEmail, '@')) {
            return strtok($guestEmail, '@') ?: '';
        }

        return '';
    }

    /**
     * @param  array{0: int, 1: int}|null  $time
     */
    private function scoreConfidence(?int $partySize, ?CarbonImmutable $date, ?array $time): float
    {
        $fields = ($partySize !== null ? 1 : 0)
            + ($date !== null ? 1 : 0)
            + ($time !== null ? 1 : 0);

        return match ($fields) {
            3 => 1.0,
            2 => 0.8,
            1 => 0.6,
            default => 0.2,
        };
    }
}

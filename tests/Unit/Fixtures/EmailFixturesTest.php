<?php

declare(strict_types=1);

namespace Tests\Unit\Fixtures;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Message;

/**
 * Smoke test for the `.eml` fixtures under tests/Fixtures/emails/.
 *
 * Ensures every checked-in fixture parses through webklex/php-imap without
 * error and that MIME-encoded headers (RFC 2047) decode as expected. Keeps
 * the fixture corpus trustworthy for the parser tests that consume it.
 */
class EmailFixturesTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__.'/../../Fixtures/emails';

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function fixtureProvider(): iterable
    {
        $files = [
            'sauber-deutsch.eml',
            'html-nur.eml',
            'kein-datum.eml',
            'englisch.eml',
            'umlaut-im-namen.eml',
            'no-reply-absender.eml',
        ];

        foreach ($files as $file) {
            yield $file => [$file];
        }
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function test_fixture_parses_into_a_message(string $file): void
    {
        $path = self::FIXTURE_DIR.'/'.$file;

        $this->assertFileExists($path, "Fixture {$file} is missing");

        $message = Message::fromFile($path);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertNotSame('', (string) $message->getMessageId());
    }

    public function test_umlaut_sender_header_is_exposed_as_rfc_2047_encoded_word(): void
    {
        $message = Message::fromFile(self::FIXTURE_DIR.'/umlaut-im-namen.eml');
        $from = $message->getFrom();
        $first = is_array($from) ? ($from[0] ?? null) : $from?->first();

        $this->assertNotNull($first);
        $this->assertSame('j@x.de', $first->mail);
        // webklex/php-imap does not auto-decode sender personal names (only
        // subject and attachment names). The fixture must therefore surface
        // the raw RFC 2047 encoded-word so the consumer (parser / mailbox)
        // can decide how to decode it — pinned here for issue #43 awareness.
        $this->assertSame('=?UTF-8?B?TcO8bGxlciwgSsO8cmdlbg==?=', $first->personal);
        $this->assertSame('Müller, Jürgen', mb_decode_mimeheader($first->personal));
    }

    public function test_no_reply_fixture_exposes_body_email_and_sender(): void
    {
        $message = Message::fromFile(self::FIXTURE_DIR.'/no-reply-absender.eml');
        $from = $message->getFrom();
        $first = is_array($from) ? ($from[0] ?? null) : $from?->first();

        $this->assertNotNull($first);
        $this->assertSame('noreply@portal.de', $first->mail);
        $this->assertStringContainsString('kunde@gmail.com', $message->getTextBody());
    }

    public function test_html_only_fixture_has_no_text_body(): void
    {
        $message = Message::fromFile(self::FIXTURE_DIR.'/html-nur.eml');

        $this->assertFalse($message->hasTextBody());
        $this->assertTrue($message->hasHTMLBody());
        $this->assertStringContainsString('für 4 Personen', $message->getHTMLBody());
    }

    public function test_plaintext_fixtures_preserve_umlauts_in_body(): void
    {
        $sauber = Message::fromFile(self::FIXTURE_DIR.'/sauber-deutsch.eml');

        $this->assertStringContainsString('hätte', $sauber->getTextBody());
        $this->assertStringContainsString('Müller', $sauber->getTextBody());
    }
}

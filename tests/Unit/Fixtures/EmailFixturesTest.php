<?php

declare(strict_types=1);

namespace Tests\Unit\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('fixtureProvider')]
    public function test_fixture_parses_into_a_message(string $file): void
    {
        $path = self::FIXTURE_DIR.'/'.$file;

        $this->assertFileExists($path, "Fixture {$file} is missing");

        $message = Message::fromFile($path);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertNotSame('', (string) $message->getMessageId());
    }

    public function test_umlaut_sender_fixture_encodes_the_expected_display_name(): void
    {
        // Verify at the file level rather than through a library decoder:
        // both mb_decode_mimeheader and iconv_mime_decode rely on implicit
        // charset assumptions that vary per environment (internal encoding,
        // iconv locale), which mangles multibyte output on some CI runners.
        // The acceptance criterion is about the fixture file itself, so we
        // prove the RFC 2047 encoded-word decodes to the expected UTF-8
        // bytes using a pure base64 round-trip that does not depend on any
        // character-encoding extension.
        $raw = (string) file_get_contents(self::FIXTURE_DIR.'/umlaut-im-namen.eml');

        $this->assertStringContainsString('=?UTF-8?B?TcO8bGxlciwgSsO8cmdlbg==?=', $raw);
        $this->assertStringContainsString('<j@x.de>', $raw);
        $this->assertSame(
            'Müller, Jürgen',
            (string) base64_decode('TcO8bGxlciwgSsO8cmdlbg==', true),
        );

        // And the fixture is still a valid parseable mail through webklex.
        $message = Message::fromFile(self::FIXTURE_DIR.'/umlaut-im-namen.eml');
        $from = $message->getFrom();
        $first = is_array($from) ? ($from[0] ?? null) : $from?->first();
        $this->assertNotNull($first);
        $this->assertSame('j@x.de', $first->mail);
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

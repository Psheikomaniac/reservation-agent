<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Services\Email\EmailReservationParser;
use App\Services\Email\HtmlToPlainText;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

class EmailReservationParserFixtureTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__.'/../../../Fixtures/emails';

    private EmailReservationParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new EmailReservationParser(new HtmlToPlainText);
    }

    public function test_sauber_deutsch_yields_full_extraction_with_perfect_confidence(): void
    {
        $result = $this->parser->parse($this->loadFixture('sauber-deutsch.eml'));

        $this->assertSame('Anna Müller', $result->guestName);
        $this->assertSame('anna.mueller@example.com', $result->guestEmail);
        $this->assertSame(4, $result->partySize);
        $this->assertNotNull($result->desiredAt);
        $this->assertSame('2026-05-12 19:30:00', $result->desiredAt->format('Y-m-d H:i:s'));
        $this->assertSame(1.0, $result->confidence);
        $this->assertFalse(
            $result->toReservationRequestAttributes(restaurantId: 1)['needs_manual_review'],
            'A confidence-1.0 result must not flag for manual review.',
        );
        $this->assertSame('sauber-deutsch-001@example.com', $result->messageId);
    }

    public function test_html_nur_is_converted_and_matches_the_plaintext_baseline(): void
    {
        $result = $this->parser->parse($this->loadFixture('html-nur.eml'));

        $this->assertSame('Anna Müller', $result->guestName);
        $this->assertSame('anna.mueller@example.com', $result->guestEmail);
        $this->assertSame(4, $result->partySize);
        $this->assertNotNull($result->desiredAt);
        $this->assertSame('2026-05-12 19:30:00', $result->desiredAt->format('Y-m-d H:i:s'));
        $this->assertSame(1.0, $result->confidence);
    }

    public function test_kein_datum_has_no_desired_at_and_low_confidence(): void
    {
        $result = $this->parser->parse($this->loadFixture('kein-datum.eml'));

        $this->assertNull($result->desiredAt);
        $this->assertLessThan(0.5, $result->confidence);
        $this->assertTrue(
            $result->toReservationRequestAttributes(restaurantId: 1)['needs_manual_review'],
            'A result without date/time must be flagged for manual review.',
        );
    }

    public function test_englisch_extracts_date_time_and_party_size(): void
    {
        $result = $this->parser->parse($this->loadFixture('englisch.eml'));

        $this->assertNotNull($result->desiredAt);
        $this->assertSame('2026-05-01 19:00:00', $result->desiredAt->format('Y-m-d H:i:s'));
        $this->assertSame(6, $result->partySize);
        $this->assertSame('john.doe@example.com', $result->guestEmail);
    }

    public function test_umlaut_im_namen_survives_rfc_2047_encoded_word_decoding(): void
    {
        $result = $this->parser->parse($this->loadFixture('umlaut-im-namen.eml'));

        $this->assertSame('Müller, Jürgen', $result->guestName);
        $this->assertSame('j@x.de', $result->guestEmail);
        $this->assertNotNull($result->desiredAt);
        $this->assertSame('2026-03-15 20:00:00', $result->desiredAt->format('Y-m-d H:i:s'));
        $this->assertSame(2, $result->partySize);
    }

    public function test_no_reply_absender_resolves_guest_email_from_body(): void
    {
        $result = $this->parser->parse($this->loadFixture('no-reply-absender.eml'));

        $this->assertSame('kunde@gmail.com', $result->guestEmail);
        $this->assertSame(2, $result->partySize);
        $this->assertNotNull($result->desiredAt);
        $this->assertSame('2026-05-01 18:00:00', $result->desiredAt->format('Y-m-d H:i:s'));
    }

    private function loadFixture(string $filename): Message
    {
        $path = self::FIXTURE_DIR.'/'.$filename;

        $this->assertFileExists($path, "Missing fixture: {$filename}");

        return Message::fromFile($path);
    }
}

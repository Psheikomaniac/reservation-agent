<?php

namespace Tests\Unit\Services\Email;

use App\Services\Email\EmailReservationParser;
use Tests\TestCase;

class EmailReservationParserTest extends TestCase
{
    private EmailReservationParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new EmailReservationParser;
    }

    public function test_it_extracts_all_fields_from_a_clean_german_request(): void
    {
        $body = 'Hallo, ich hätte gerne einen Tisch für 4 Personen am 12.05.2026 um 19:30 Uhr. Gruß, Anna Müller';

        $result = $this->parser->parseParts(
            body: $body,
            senderEmail: 'anna@example.com',
            senderName: 'Anna Müller',
            messageId: '<abc@example.com>',
        );

        $this->assertSame('Anna Müller', $result->guestName);
        $this->assertSame('anna@example.com', $result->guestEmail);
        $this->assertSame(4, $result->partySize);
        $this->assertNotNull($result->desiredAt);
        $this->assertSame('2026-05-12 19:30:00', $result->desiredAt->format('Y-m-d H:i:s'));
        $this->assertSame(1.0, $result->confidence);
        $this->assertSame('<abc@example.com>', $result->messageId);
    }

    public function test_it_parses_iso_dates(): void
    {
        $result = $this->parser->parseParts(
            body: 'Table on 2026-05-01 at 19:00 for 2 people.',
            senderEmail: 'x@example.com',
            senderName: 'X',
            messageId: '<1@example.com>',
        );

        $this->assertNotNull($result->desiredAt);
        $this->assertSame('2026-05-01 19:00:00', $result->desiredAt->format('Y-m-d H:i:s'));
    }

    public function test_it_parses_german_long_dates_with_umlauts(): void
    {
        $result = $this->parser->parseParts(
            body: 'Gerne am 15. März 2026 um 20:00 Uhr für 3 Personen.',
            senderEmail: 'x@example.com',
            senderName: 'X',
            messageId: '<1@example.com>',
        );

        $this->assertNotNull($result->desiredAt);
        $this->assertSame('2026-03-15 20:00:00', $result->desiredAt->format('Y-m-d H:i:s'));
    }

    public function test_it_parses_english_dates_with_ordinal_suffixes_and_suffix_only_times(): void
    {
        $result = $this->parser->parseParts(
            body: 'Table for 6 guests on May 1st, 2026 at 7pm.',
            senderEmail: 'x@example.com',
            senderName: 'X',
            messageId: '<1@example.com>',
        );

        $this->assertNotNull($result->desiredAt);
        $this->assertSame('2026-05-01 19:00:00', $result->desiredAt->format('Y-m-d H:i:s'));
        $this->assertSame(6, $result->partySize);
    }

    public function test_it_converts_12pm_and_12am_correctly(): void
    {
        $noon = $this->parser->parseParts(
            body: 'Lunch for 2 on 2026-05-01 at 12:00pm',
            senderEmail: 'x@example.com',
            senderName: 'X',
            messageId: '<1@example.com>',
        );
        $this->assertSame(12, $noon->desiredAt?->hour);

        $midnight = $this->parser->parseParts(
            body: 'Event on 2026-05-01 at 12:30am',
            senderEmail: 'x@example.com',
            senderName: 'X',
            messageId: '<2@example.com>',
        );
        $this->assertSame(0, $midnight->desiredAt?->hour);
        $this->assertSame(30, $midnight->desiredAt?->minute);
    }

    public function test_it_rejects_invalid_calendar_dates(): void
    {
        $result = $this->parser->parseParts(
            body: 'Table on 31.02.2026 at 19:00',
            senderEmail: 'x@example.com',
            senderName: 'X',
            messageId: '<1@example.com>',
        );

        $this->assertNull($result->desiredAt);
    }

    public function test_confidence_scales_with_extracted_fields(): void
    {
        $freetext = $this->parser->parseParts(
            body: 'Haben Sie am Wochenende noch was frei?',
            senderEmail: 'x@example.com',
            senderName: 'X',
            messageId: '<1@example.com>',
        );
        $this->assertSame(0.2, $freetext->confidence);

        $oneField = $this->parser->parseParts(
            body: 'Für 3 Personen, Tag und Zeit offen',
            senderEmail: 'x@example.com',
            senderName: 'X',
            messageId: '<2@example.com>',
        );
        $this->assertSame(0.6, $oneField->confidence);

        $twoFields = $this->parser->parseParts(
            body: 'Tisch am 01.05.2026 um 19:00, Personenzahl offen',
            senderEmail: 'x@example.com',
            senderName: 'X',
            messageId: '<3@example.com>',
        );
        $this->assertSame(0.8, $twoFields->confidence);
    }

    public function test_desired_at_is_null_when_time_is_missing(): void
    {
        $result = $this->parser->parseParts(
            body: 'Gerne am 01.05.2026 für 4 Personen',
            senderEmail: 'x@example.com',
            senderName: 'X',
            messageId: '<1@example.com>',
        );

        $this->assertNull($result->desiredAt);
        $this->assertSame(0.8, $result->confidence);
    }

    public function test_it_prefers_body_email_when_sender_is_no_reply(): void
    {
        $result = $this->parser->parseParts(
            body: "Neue Reservierung. Kontakt: kunde@gmail.com\nTisch für 2 am 01.05.2026 um 18:00",
            senderEmail: 'noreply@portal.de',
            senderName: 'Portal',
            messageId: '<1@example.com>',
        );

        $this->assertSame('kunde@gmail.com', $result->guestEmail);
    }

    public function test_it_recognises_various_no_reply_patterns(): void
    {
        foreach (['no-reply@x.de', 'donotreply@x.de', 'do-not-reply@x.de', 'no.reply@x.de'] as $noReply) {
            $result = $this->parser->parseParts(
                body: 'Gast: gast@example.com',
                senderEmail: $noReply,
                senderName: 'Portal',
                messageId: '<1@example.com>',
            );
            $this->assertSame('gast@example.com', $result->guestEmail, "Failed for sender: {$noReply}");
        }
    }

    public function test_it_keeps_sender_email_when_body_only_has_no_reply_candidates(): void
    {
        $result = $this->parser->parseParts(
            body: 'Siehe noreply@system.de',
            senderEmail: 'noreply@portal.de',
            senderName: null,
            messageId: '<1@example.com>',
        );

        $this->assertSame('noreply@portal.de', $result->guestEmail);
    }

    public function test_it_falls_back_to_body_name_when_sender_display_name_is_missing(): void
    {
        $result = $this->parser->parseParts(
            body: 'Mein Name ist Jürgen Müller und ich hätte gerne einen Tisch.',
            senderEmail: 'j@example.com',
            senderName: null,
            messageId: '<1@example.com>',
        );

        $this->assertSame('Jürgen Müller', $result->guestName);
    }

    public function test_it_falls_back_to_email_local_part_when_no_name_available(): void
    {
        $result = $this->parser->parseParts(
            body: 'Haben Sie frei?',
            senderEmail: 'john.doe@example.com',
            senderName: null,
            messageId: '<1@example.com>',
        );

        $this->assertSame('john.doe', $result->guestName);
    }

    public function test_it_uses_sender_display_name_verbatim_even_with_umlauts_and_comma(): void
    {
        $result = $this->parser->parseParts(
            body: 'Haben Sie frei?',
            senderEmail: 'j@example.com',
            senderName: 'Müller, Jürgen',
            messageId: '<1@example.com>',
        );

        $this->assertSame('Müller, Jürgen', $result->guestName);
    }

    public function test_it_extracts_party_size_with_various_german_and_english_suffixes(): void
    {
        $cases = [
            'Tisch für 4 Personen' => 4,
            '5 Gäste' => 5,
            'Table for 6 guests' => 6,
            '3 Pers.' => 3,
        ];

        foreach ($cases as $body => $expected) {
            $result = $this->parser->parseParts(
                body: $body,
                senderEmail: 'x@example.com',
                senderName: 'X',
                messageId: '<id@example.com>',
            );
            $this->assertSame($expected, $result->partySize, "Failed for: {$body}");
        }
    }

    public function test_it_leaves_phone_null_for_v1(): void
    {
        $result = $this->parser->parseParts(
            body: 'Tisch für 2 am 01.05.2026 um 19:00, Tel: 030 1234567',
            senderEmail: 'x@example.com',
            senderName: 'X',
            messageId: '<1@example.com>',
        );

        $this->assertNull($result->guestPhone);
    }
}

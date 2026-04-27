<?php

namespace Tests\Unit\Services\Email\DTO;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Services\Email\DTO\ParsedReservation;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ParsedReservationTest extends TestCase
{
    public function test_it_exposes_all_fields_verbatim(): void
    {
        $desiredAt = CarbonImmutable::parse('2026-05-01 19:30:00');

        $dto = new ParsedReservation(
            guestName: 'Anna Müller',
            guestEmail: 'anna@example.com',
            guestPhone: '+49 30 1234567',
            partySize: 4,
            desiredAt: $desiredAt,
            message: 'Tisch für 4 am 01.05.',
            confidence: 1.0,
            messageId: '<abc@example.com>',
        );

        $this->assertSame('Anna Müller', $dto->guestName);
        $this->assertSame('anna@example.com', $dto->guestEmail);
        $this->assertSame('+49 30 1234567', $dto->guestPhone);
        $this->assertSame(4, $dto->partySize);
        $this->assertSame($desiredAt, $dto->desiredAt);
        $this->assertSame('Tisch für 4 am 01.05.', $dto->message);
        $this->assertSame(1.0, $dto->confidence);
        $this->assertSame('<abc@example.com>', $dto->messageId);
    }

    public function test_it_maps_to_reservation_request_attributes_for_a_confident_parse(): void
    {
        $desiredAt = CarbonImmutable::parse('2026-05-01 19:30:00');

        $dto = new ParsedReservation(
            guestName: 'Anna Müller',
            guestEmail: 'anna@example.com',
            guestPhone: '+49 30 1234567',
            partySize: 4,
            desiredAt: $desiredAt,
            message: 'Tisch für 4 am 01.05.',
            confidence: 1.0,
            messageId: '<abc@example.com>',
        );

        $attributes = $dto->toReservationRequestAttributes(restaurantId: 42);

        $this->assertSame([
            'restaurant_id' => 42,
            'source' => ReservationSource::Email,
            'status' => ReservationStatus::New,
            'guest_name' => 'Anna Müller',
            'guest_email' => 'anna@example.com',
            'guest_phone' => '+49 30 1234567',
            'party_size' => 4,
            'desired_at' => $desiredAt,
            'message' => 'Tisch für 4 am 01.05.',
            'needs_manual_review' => false,
            'email_message_id' => '<abc@example.com>',
        ], $attributes);
    }

    public function test_it_flags_needs_manual_review_when_confidence_is_below_one(): void
    {
        $dto = new ParsedReservation(
            guestName: 'Unbekannt',
            guestEmail: 'noreply@portal.de',
            guestPhone: null,
            partySize: null,
            desiredAt: null,
            message: 'Haben Sie am Wochenende noch was frei?',
            confidence: 0.3,
            messageId: '<fuzzy@example.com>',
        );

        $attributes = $dto->toReservationRequestAttributes(restaurantId: 1);

        $this->assertTrue($attributes['needs_manual_review']);
        $this->assertNull($attributes['party_size']);
        $this->assertNull($attributes['desired_at']);
        $this->assertNull($attributes['guest_phone']);
    }

    public function test_it_does_not_flag_needs_manual_review_at_exact_confidence_of_one(): void
    {
        $dto = new ParsedReservation(
            guestName: 'Clean Parse',
            guestEmail: 'clean@example.com',
            guestPhone: null,
            partySize: 2,
            desiredAt: CarbonImmutable::parse('2026-05-02 20:00:00'),
            message: 'Tisch für 2 am 02.05. um 20:00',
            confidence: 1.0,
            messageId: '<clean@example.com>',
        );

        $this->assertFalse($dto->toReservationRequestAttributes(restaurantId: 1)['needs_manual_review']);
    }
}

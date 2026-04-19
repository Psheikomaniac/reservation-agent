<?php

declare(strict_types=1);

namespace App\Services\Email\DTO;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;

final readonly class ParsedReservation
{
    public function __construct(
        public string $guestName,
        public string $guestEmail,
        public ?string $guestPhone,
        public ?int $partySize,
        public ?CarbonImmutable $desiredAt,
        public string $message,
        public float $confidence,
        public string $messageId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toReservationRequestAttributes(int $restaurantId): array
    {
        return [
            'restaurant_id' => $restaurantId,
            'source' => ReservationSource::Email,
            'status' => ReservationStatus::New,
            'guest_name' => $this->guestName,
            'guest_email' => $this->guestEmail,
            'guest_phone' => $this->guestPhone,
            'party_size' => $this->partySize,
            'desired_at' => $this->desiredAt,
            'message' => $this->message,
            'needs_manual_review' => $this->confidence < 1.0,
            'email_message_id' => $this->messageId,
        ];
    }
}

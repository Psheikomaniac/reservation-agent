<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ReservationSource;
use App\Models\ReservationRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dashboard list contract for a reservation request.
 *
 * Field set is locked to the shape defined in PRD-004 §"API Resource".
 * The raw email body is intentionally not part of this contract — it is
 * only returned by the policy-protected detail endpoint. Only `has_raw_email`
 * signals the existence of a body.
 *
 * @property-read ReservationRequest $resource
 */
final class ReservationRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'status' => $this->resource->status->value,
            'source' => $this->resource->source->value,
            'guest_name' => $this->resource->guest_name,
            'guest_email' => $this->resource->guest_email,
            'guest_phone' => $this->resource->guest_phone,
            'party_size' => $this->resource->party_size,
            'desired_at' => $this->resource->desired_at?->toIso8601String(),
            'needs_manual_review' => $this->resource->needs_manual_review,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'has_raw_email' => $this->resource->source === ReservationSource::Email,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ReservationSource;
use App\Models\ReservationRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detail-drawer contract for a reservation request.
 *
 * Carries everything from {@see ReservationRequestResource} plus the
 * full message text, the structured `raw_payload`, and — when the
 * source is email — the decrypted raw email body convenience field.
 *
 * Only this resource is allowed to expose `raw_payload` /
 * `raw_email_body`. Endpoint that uses it must enforce the
 * `ReservationRequestPolicy@view` gate.
 *
 * @property-read ReservationRequest $resource
 */
final class ReservationRequestDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $list = (new ReservationRequestResource($this->resource))->toArray($request);

        $rawPayload = $this->resource->raw_payload;
        $isEmail = $this->resource->source === ReservationSource::Email;

        return [
            ...$list,
            'message' => $this->resource->message,
            'raw_payload' => $rawPayload,
            'raw_email_body' => $isEmail && is_array($rawPayload)
                ? ($rawPayload['body'] ?? null)
                : null,
        ];
    }
}

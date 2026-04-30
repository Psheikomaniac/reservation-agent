<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReservationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Drawer-history projection of a ReservationMessage. Exposes only the
 * fields the UI needs and explicitly omits `raw_headers` and the
 * threading-internal columns (`in_reply_to`, `references`) — those stay
 * server-side and are reserved for the resolver / audit.
 *
 * @property-read ReservationMessage $resource
 */
final class ReservationMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'direction' => $this->resource->direction->value,
            'subject' => $this->resource->subject,
            'from_address' => $this->resource->from_address,
            'to_address' => $this->resource->to_address,
            'body_plain' => $this->resource->body_plain,
            'sent_at' => $this->resource->sent_at?->toIso8601String(),
            'received_at' => $this->resource->received_at?->toIso8601String(),
            'approved_by' => $this->resource->outboundReply?->approver?->name,
        ];
    }
}

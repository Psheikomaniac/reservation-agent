<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON contract for a table (PRD-011).
 *
 * Field names follow the actual schema columns (`label`, `seats`, `active`)
 * rather than the generic names used in the issue text, keeping the contract
 * aligned with the model and the Tables.vue master-data / occupancy tabs.
 *
 * @property-read Table $resource
 */
final class TableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'label' => $this->resource->label,
            'seats' => $this->resource->seats,
            'room_tag' => $this->resource->room_tag,
            'sort_order' => $this->resource->sort_order,
            'active' => $this->resource->active,
            'combinable_with' => $this->resource->combinable_with ?? [],
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}

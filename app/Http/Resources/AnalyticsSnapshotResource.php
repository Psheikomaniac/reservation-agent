<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Analytics\AnalyticsSnapshot;
use App\Services\Analytics\TrendBucket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Maps an `AnalyticsSnapshot` DTO 1:1 to the JSON shape the Inertia
 * dashboard consumes. The resource is the seam where field names get
 * camelCased for the frontend; the underlying DTO and the Vue page
 * agree on this shape.
 *
 * @property AnalyticsSnapshot $resource
 */
class AnalyticsSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->resource;

        return [
            'range' => $snapshot->range->value,
            'bucketSize' => $snapshot->range->bucketSize(),
            'totals' => $snapshot->totals,
            'sources' => $snapshot->sources,
            'statusBreakdown' => $snapshot->statusBreakdown,
            'responseTime' => [
                'medianMinutes' => $snapshot->responseTime->medianMinutes,
                'p90Minutes' => $snapshot->responseTime->p90Minutes,
                'sampleSize' => $snapshot->responseTime->sampleSize,
            ],
            'editRate' => $snapshot->editRate,
            'sendModeStats' => $snapshot->sendModeStats === null ? null : [
                'manual' => $snapshot->sendModeStats->manual,
                'shadow' => $snapshot->sendModeStats->shadow,
                'auto' => $snapshot->sendModeStats->auto,
                'shadowComparedSampleSize' => $snapshot->sendModeStats->shadowComparedSampleSize,
                'takeoverRate' => $snapshot->sendModeStats->takeoverRate,
            ],
            'trends' => array_map(
                fn (TrendBucket $bucket) => [
                    'label' => $bucket->label,
                    'bucketStart' => $bucket->bucketStart,
                    'count' => $bucket->count,
                ],
                $snapshot->trends,
            ),
        ];
    }
}

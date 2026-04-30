<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsIndexRequest;
use App\Http\Resources\AnalyticsSnapshotResource;
use App\Services\Analytics\AnalyticsAggregator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders the PRD-008 analytics dashboard. The controller owns
 * the tenant resolution (`auth()->user()->restaurant`) and the
 * range validation (via {@see AnalyticsIndexRequest}); the actual
 * aggregation lives in {@see AnalyticsAggregator} so this layer
 * stays a thin Inertia adapter.
 */
final class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsAggregator $aggregator,
    ) {}

    public function index(AnalyticsIndexRequest $request): Response
    {
        $restaurant = $request->user()?->restaurant;

        if ($restaurant === null) {
            // A user without a restaurant cannot meaningfully view
            // analytics — surface a 404 rather than a confusing
            // empty dashboard. Onboarding/seat-management is out of
            // V1.0 scope.
            throw new NotFoundHttpException;
        }

        $range = $request->range();
        $snapshot = $this->aggregator->aggregate($restaurant, $range);

        return Inertia::render('Analytics', [
            // Resolve to a plain array so the Inertia prop is a flat
            // map and not wrapped in JsonResource's `data` envelope —
            // the Vue page consumes the snapshot directly.
            'snapshot' => (new AnalyticsSnapshotResource($snapshot))->resolve($request),
        ]);
    }
}

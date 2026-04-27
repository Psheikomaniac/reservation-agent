<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Requests\DashboardFilterRequest;
use App\Http\Resources\ReservationRequestDetailResource;
use App\Http\Resources\ReservationRequestResource;
use App\Models\ReservationRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function index(DashboardFilterRequest $request): Response
    {
        $validated = $request->validated();
        $selectedId = isset($validated['selected']) ? (int) $validated['selected'] : null;

        $filterQuery = Arr::except($request->query(), ['selected']);
        $filters = $filterQuery === []
            ? $this->defaultFilters($request)
            : Arr::except($validated, ['selected']);

        $requests = ReservationRequest::query()
            ->filter($filters)
            ->with(['latestReply'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Dashboard', [
            'filters' => $filters,
            'requests' => ReservationRequestResource::collection($requests),
            'stats' => [
                'new' => ReservationRequest::query()
                    ->where('status', ReservationStatus::New)
                    ->count(),
                'in_review' => ReservationRequest::query()
                    ->where('status', ReservationStatus::InReview)
                    ->count(),
            ],
            'selectedRequest' => fn () => $this->resolveSelected($selectedId, $request),
        ]);
    }

    private function resolveSelected(?int $id, DashboardFilterRequest $request): ?array
    {
        if ($id === null) {
            return null;
        }

        $reservation = ReservationRequest::query()
            ->withoutGlobalScopes()
            ->with(['latestReply'])
            ->find($id);

        if ($reservation === null || ! Gate::forUser($request->user())->allows('view', $reservation)) {
            return null;
        }

        return (new ReservationRequestDetailResource($reservation))->toArray($request);
    }

    /**
     * Defaults applied only when no filter query parameters are present.
     * The `selected` parameter is ignored here so opening the drawer via
     * deep link does not blow away the default filters.
     *
     * @return array<string, mixed>
     */
    private function defaultFilters(DashboardFilterRequest $request): array
    {
        $timezone = $request->user()?->restaurant?->timezone ?? config('app.timezone');

        return [
            'status' => [
                ReservationStatus::New->value,
                ReservationStatus::InReview->value,
            ],
            'from' => Carbon::now($timezone)->startOfDay()->toDateString(),
        ];
    }
}

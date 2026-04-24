<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Requests\DashboardFilterRequest;
use App\Http\Resources\ReservationRequestResource;
use App\Models\ReservationRequest;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function index(DashboardFilterRequest $request): Response
    {
        $filters = $request->query() === []
            ? $this->defaultFilters($request)
            : $request->validated();

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
        ]);
    }

    /**
     * Defaults applied only when the query string is fully empty
     * (first visit, no user-supplied filter). Any querystring at all —
     * including filter clears that send `?status[]=` — bypasses these.
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

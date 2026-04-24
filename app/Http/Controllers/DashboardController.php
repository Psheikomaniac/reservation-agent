<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Requests\DashboardFilterRequest;
use App\Http\Resources\ReservationRequestResource;
use App\Models\ReservationRequest;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function index(DashboardFilterRequest $request): Response
    {
        $filters = $request->validated();

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
}

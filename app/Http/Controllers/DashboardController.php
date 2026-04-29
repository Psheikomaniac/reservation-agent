<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Http\Requests\DashboardFilterRequest;
use App\Http\Resources\ReservationMessageResource;
use App\Http\Resources\ReservationRequestDetailResource;
use App\Http\Resources\ReservationRequestResource;
use App\Models\ReservationRequest;
use App\Support\OpenAiKeyHealth;
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
            'threadMessages' => fn () => $this->resolveThreadMessages($selectedId, $request),
            // Owner-only banner. The flag is global (V1.0 has one OpenAI key
            // app-wide); dismissing-by-user is intentionally NOT supported
            // (issue #76) so a stale alert can't outlive a still-broken key.
            'openaiKeyRejectedAt' => $request->user()?->role === UserRole::Owner
                ? OpenAiKeyHealth::rejectedAt()
                : null,
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
     * Lazy prop for the drawer's History tab. The frontend triggers a
     * partial reload (`router.reload({ only: ['threadMessages'] })`) on
     * tab activation, so this only runs when the operator opens History
     * on the currently-selected reservation.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function resolveThreadMessages(?int $id, DashboardFilterRequest $request): ?array
    {
        if ($id === null) {
            return null;
        }

        $reservation = ReservationRequest::query()
            ->withoutGlobalScopes()
            ->find($id);

        if ($reservation === null || ! Gate::forUser($request->user())->allows('view', $reservation)) {
            return null;
        }

        $messages = $reservation->messages()
            ->with(['outboundReply.approver:id,name'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return ReservationMessageResource::collection($messages)->resolve($request);
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

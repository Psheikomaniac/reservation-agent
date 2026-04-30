<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ReservationMessageResource;
use App\Models\ReservationRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class ReservationMessagesController extends Controller
{
    public function index(int $reservation): AnonymousResourceCollection
    {
        // Bypass the RestaurantScope so a cross-tenant id surfaces a 403
        // (policy denial) rather than a 404 — the AC distinguishes "not
        // yours" from "doesn't exist" for this endpoint.
        $request = ReservationRequest::withoutGlobalScopes()->findOrFail($reservation);

        Gate::authorize('view', $request);

        $messages = $request->messages()
            ->with(['outboundReply.approver:id,name'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return ReservationMessageResource::collection($messages);
    }
}

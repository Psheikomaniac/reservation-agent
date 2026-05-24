<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\AcceptInvitationRequest;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tokenised invitation acceptance. The owner (or staff) follows the link from
 * `restaurant:provision` / a team invite, sets a name + password, and is
 * logged in. After login we send them to the dashboard; the onboarding gate
 * (PRD-016 §Gating) is responsible for routing a not-yet-live owner on to the
 * setup wizard, so the acceptance flow stays decoupled from the wizard route.
 */
final class AcceptInvitationController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = Invitation::findByToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            return Inertia::render('Onboarding/InvitationError', [
                'reason' => $this->reason($invitation),
            ]);
        }

        return Inertia::render('Onboarding/AcceptInvitation', [
            'email' => $invitation->email,
            'token' => $token,
            'restaurantName' => $invitation->restaurant->name,
        ]);
    }

    public function store(AcceptInvitationRequest $request, string $token): RedirectResponse
    {
        $invitation = Invitation::findByToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            // Re-render the error page via the GET route (idempotent on replay).
            return redirect()->route('onboarding.accept', ['token' => $token]);
        }

        $validated = $request->validated();

        $user = DB::transaction(function () use ($invitation, $validated): User {
            // Owner invitations already have a provisioned (password-less) user;
            // staff invitations create the user on acceptance. firstOrNew covers
            // both, and the role always comes from the invitation.
            $user = User::firstOrNew([
                'restaurant_id' => $invitation->restaurant_id,
                'email' => $invitation->email,
            ]);

            $user->forceFill([
                'name' => $validated['name'],
                'password' => Hash::make($validated['password']),
                'role' => $invitation->role,
                'email_verified_at' => now(),
            ])->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        Auth::login($user);

        return to_route('dashboard');
    }

    private function reason(?Invitation $invitation): string
    {
        return match (true) {
            $invitation === null => 'invalid',
            $invitation->isAccepted() => 'accepted',
            default => 'expired',
        };
    }
}

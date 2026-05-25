<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ImapSettingsRequest;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\SecretMask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner-only per-restaurant IMAP receive config (PRD-016 Phase 1b). The
 * columns already exist; this surfaces them in the UI. The password is never
 * returned to the browser — only a masked tail.
 */
final class ImapSettingsController extends Controller
{
    public function edit(): Response
    {
        $restaurant = $this->ownedRestaurant();

        return Inertia::render('settings/Imap', [
            'imap' => [
                'host' => $restaurant->imap_host,
                'username' => $restaurant->imap_username,
                'password_masked' => SecretMask::tail4($restaurant->imap_password),
            ],
        ]);
    }

    public function update(ImapSettingsRequest $request): RedirectResponse
    {
        $restaurant = $this->ownedRestaurant();
        $validated = $request->validated();

        $payload = collect($validated)->except('imap_password')->all();

        // Empty password leaves the stored one untouched.
        if (($validated['imap_password'] ?? '') !== '') {
            $payload['imap_password'] = $validated['imap_password'];
        }

        $restaurant->update($payload);

        return back()->with('success', 'IMAP-Einstellungen gespeichert.');
    }

    private function ownedRestaurant(): Restaurant
    {
        /** @var User $user */
        $user = Auth::user();
        $restaurant = $user->restaurant;

        if ($restaurant === null) {
            abort(403);
        }

        Gate::authorize('manageIntegrations', $restaurant);

        return $restaurant;
    }
}

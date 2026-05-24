<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SmtpSettingsRequest;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\SecretMask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner-only per-restaurant SMTP send config (PRD-016 Phase 1b). The password
 * is never returned to the browser — only a masked tail.
 */
final class SmtpSettingsController extends Controller
{
    public function edit(): Response
    {
        $restaurant = $this->ownedRestaurant();

        return Inertia::render('settings/Smtp', [
            'smtp' => [
                'host' => $restaurant->smtp_host,
                'port' => $restaurant->smtp_port,
                'username' => $restaurant->smtp_username,
                'from_address' => $restaurant->smtp_from_address,
                'from_name' => $restaurant->smtp_from_name,
                'password_masked' => SecretMask::tail4($restaurant->smtp_password),
            ],
        ]);
    }

    public function update(SmtpSettingsRequest $request): RedirectResponse
    {
        $restaurant = $this->ownedRestaurant();
        $validated = $request->validated();

        $payload = collect($validated)->except('smtp_password')->all();

        // Empty password leaves the stored one untouched.
        if (($validated['smtp_password'] ?? '') !== '') {
            $payload['smtp_password'] = $validated['smtp_password'];
        }

        $restaurant->update($payload);

        return back()->with('success', 'SMTP-Einstellungen gespeichert.');
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

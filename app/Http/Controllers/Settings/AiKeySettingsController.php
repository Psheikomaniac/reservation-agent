<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AiKeySettingsRequest;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\SecretMask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner-only per-restaurant OpenAI BYOK key (PRD-016 Phase 1b). The key is
 * never returned to the browser — only a masked tail and a "configured" flag.
 */
final class AiKeySettingsController extends Controller
{
    public function edit(): Response
    {
        $restaurant = $this->ownedRestaurant();

        return Inertia::render('settings/AiKey', [
            'configured' => $restaurant->openai_api_key !== null,
            'masked' => SecretMask::tail4($restaurant->openai_api_key),
        ]);
    }

    public function update(AiKeySettingsRequest $request): RedirectResponse
    {
        $restaurant = $this->ownedRestaurant();
        $key = $request->validated()['openai_api_key'] ?? null;

        // Empty submission leaves the stored key untouched.
        if (is_string($key) && $key !== '') {
            $restaurant->update(['openai_api_key' => $key]);
        }

        return back()->with('success', 'OpenAI-Schlüssel gespeichert.');
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

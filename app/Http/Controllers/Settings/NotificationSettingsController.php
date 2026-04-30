<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNotificationSettingsRequest;
use App\Services\Notifications\NotificationSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD-010 § Datenmodell + Permission-Flow.
 *
 * Lets the authenticated user view and update their own
 * notification preferences. Cross-user edits are structurally
 * impossible — the controller never reads a target id off the
 * request body; it always operates on `$request->user()`.
 */
final class NotificationSettingsController extends Controller
{
    public function edit(): Response
    {
        $user = auth()->user();

        return Inertia::render('settings/Notifications', [
            'settings' => $user?->notification_settings ?? NotificationSettings::default(),
        ]);
    }

    public function update(UpdateNotificationSettingsRequest $request): RedirectResponse
    {
        $user = $request->user();
        if ($user === null) {
            // Should be unreachable because the route is auth-gated;
            // the explicit guard makes the type narrowing
            // unambiguous for static analysis.
            abort(403);
        }

        // Merge over the defaults so future PRD-010 keys land
        // gracefully without rewriting this controller every time
        // a sibling issue extends the settings shape.
        $user->forceFill([
            'notification_settings' => NotificationSettings::merge(
                $request->validated()
            ),
        ])->save();

        return back()->with('status', 'notification-settings-updated');
    }
}

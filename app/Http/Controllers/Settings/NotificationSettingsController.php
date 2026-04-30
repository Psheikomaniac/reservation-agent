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

        // Merge the validated form fields over the *existing*
        // stored value (read raw to bypass the accessor's
        // default-merge), then layer NotificationSettings::merge
        // for the default-floor on top. Without the existing-value
        // step, a future sibling issue that adds a new key (e.g.
        // an `email_digest` flag) would silently lose its value
        // every time the operator saves the 6-field UI — the form
        // payload wouldn't carry the new key, and merge-over-
        // defaults would drop the stored value.
        $existing = (array) json_decode(
            (string) ($user->getRawOriginal('notification_settings') ?? '{}'),
            true,
        );

        $user->forceFill([
            'notification_settings' => NotificationSettings::merge(
                [...$existing, ...$request->validated()],
            ),
        ])->save();

        return back()->with('status', 'notification-settings-updated');
    }
}

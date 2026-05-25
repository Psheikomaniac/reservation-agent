<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Enums\Tonality;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\InviteStaffRequest;
use App\Http\Requests\Onboarding\OpeningHoursRequest;
use App\Http\Requests\Onboarding\RestaurantInfoRequest;
use App\Http\Requests\Onboarding\TonalityRequest;
use App\Http\Requests\TableRequest;
use App\Models\Invitation;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use App\Support\OnboardingProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Server-driven onboarding wizard. The owner edits the freshly provisioned
 * restaurant step by step; the restaurant flips "live" the first moment the
 * Pflicht-Kern (master data + opening hours + >=1 table) is complete.
 */
final class OnboardingWizardController extends Controller
{
    public function show(): Response
    {
        $restaurant = $this->ownedRestaurant();

        return Inertia::render('Onboarding/Wizard', [
            'restaurant' => [
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'timezone' => $restaurant->timezone,
                'tonality' => $restaurant->tonality->value,
                'opening_hours' => $restaurant->opening_hours,
            ],
            'tables' => $restaurant->tables()
                ->where('active', true)
                ->orderBy('sort_order')
                ->get(['id', 'label', 'seats', 'room_tag']),
            'tonalities' => array_map(fn (Tonality $t): string => $t->value, Tonality::cases()),
            'progress' => $this->progressPayload($restaurant),
        ]);
    }

    public function updateRestaurant(RestaurantInfoRequest $request): RedirectResponse
    {
        $restaurant = $this->ownedRestaurant();
        $restaurant->update($request->validated());

        return $this->backToWizard($restaurant);
    }

    public function updateHours(OpeningHoursRequest $request): RedirectResponse
    {
        $restaurant = $this->ownedRestaurant();
        $restaurant->update(['opening_hours' => $request->validated()['opening_hours']]);

        return $this->backToWizard($restaurant);
    }

    public function storeTable(TableRequest $request): RedirectResponse
    {
        $restaurant = $this->ownedRestaurant();

        Table::create([
            ...$request->validated(),
            'restaurant_id' => $restaurant->id,
        ]);

        // Keep capacity in step with the table layout so availability works the
        // moment the restaurant goes live (the provisioner seeds capacity = 0).
        $restaurant->update([
            'capacity' => (int) $restaurant->tables()->where('active', true)->sum('seats'),
        ]);

        return $this->backToWizard($restaurant);
    }

    public function updateTonality(TonalityRequest $request): RedirectResponse
    {
        $restaurant = $this->ownedRestaurant();
        $restaurant->update(['tonality' => Tonality::from($request->validated()['tonality'])]);

        return $this->backToWizard($restaurant);
    }

    public function storeStaffInvite(InviteStaffRequest $request): RedirectResponse
    {
        $restaurant = $this->ownedRestaurant();
        Gate::authorize('create', Invitation::class);

        Invitation::create([
            'restaurant_id' => $restaurant->id,
            'email' => $request->validated()['email'],
            'role' => UserRole::Staff,
            'token' => Invitation::hashToken(Invitation::generateToken()),
            'expires_at' => now()->addDays(7),
        ]);

        return $this->backToWizard($restaurant);
    }

    /**
     * The acting owner's restaurant, or 403 for staff / restaurant-less users.
     */
    private function ownedRestaurant(): Restaurant
    {
        /** @var User $user */
        $user = Auth::user();
        $restaurant = $user->restaurant;

        if ($restaurant === null) {
            abort(403);
        }

        Gate::authorize('manage', $restaurant); // RestaurantPolicy::manage = owner-only

        return $restaurant;
    }

    /**
     * @return array{coreComplete: bool, nextCoreStep: string|null, steps: array<string, bool>}
     */
    private function progressPayload(Restaurant $restaurant): array
    {
        $progress = OnboardingProgress::for($restaurant);

        $steps = [];
        foreach ([...OnboardingProgress::CORE_STEPS, ...OnboardingProgress::OPTIONAL_STEPS] as $step) {
            $steps[$step] = $progress->stepComplete($step);
        }

        return [
            'coreComplete' => $progress->isCoreComplete(),
            'nextCoreStep' => $progress->nextCoreStep(),
            'steps' => $steps,
        ];
    }

    /**
     * Persist "live" the first time the Pflicht-Kern is complete, then redirect.
     */
    private function backToWizard(Restaurant $restaurant): RedirectResponse
    {
        $restaurant->refresh();

        if ($restaurant->onboarding_completed_at === null
            && OnboardingProgress::for($restaurant)->isCoreComplete()) {
            $restaurant->update(['onboarding_completed_at' => now()]);
        }

        return to_route('onboarding.wizard');
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Restaurant;

/**
 * Derives onboarding step completion purely from persisted data, so progress
 * survives logout/login and is never stored as a separate "current step".
 */
final readonly class OnboardingProgress
{
    /** Required ("Pflicht-Kern") steps, in wizard order. */
    public const CORE_STEPS = ['restaurant', 'hours', 'tables'];

    /** Optional, skippable steps. */
    public const OPTIONAL_STEPS = ['tonality', 'team'];

    private function __construct(private Restaurant $restaurant) {}

    public static function for(Restaurant $restaurant): self
    {
        return new self($restaurant);
    }

    public function stepComplete(string $step): bool
    {
        return match ($step) {
            'restaurant' => $this->restaurant->name !== ''
                && $this->restaurant->slug !== ''
                && $this->restaurant->timezone !== '',
            'hours' => $this->restaurant->opening_hours !== [],
            'tables' => $this->restaurant->tables()->where('active', true)->exists(),
            'tonality' => true, // enum column always carries a value
            'team' => $this->restaurant->invitations()->where('role', UserRole::Staff)->exists(),
            default => false,
        };
    }

    public function isCoreComplete(): bool
    {
        foreach (self::CORE_STEPS as $step) {
            if (! $this->stepComplete($step)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The first incomplete core step, or null when the core is done.
     */
    public function nextCoreStep(): ?string
    {
        foreach (self::CORE_STEPS as $step) {
            if (! $this->stepComplete($step)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Optional steps the owner has not yet addressed (for dashboard reminders).
     *
     * @return list<string>
     */
    public function pendingOptionalSteps(): array
    {
        return array_values(array_filter(
            self::OPTIONAL_STEPS,
            fn (string $step): bool => ! $this->stepComplete($step),
        ));
    }
}

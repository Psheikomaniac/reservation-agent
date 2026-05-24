# Tenant Onboarding (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a restaurant provisionable and owner-configurable end-to-end — from a CLI `restaurant:provision` command through a signed invitation acceptance to a multi-step onboarding wizard that flips the restaurant "live" — and establish the central design-token foundation (direction C) applied to the wizard.

**Architecture:** Laravel decides deterministically, the wizard is server-driven Inertia (one FormRequest per step, progress derived from persisted data). Provisioning lives in a reusable `RestaurantProvisioner` service (the later Super-Admin UI calls the same service). Invitations use a hashed random token (anti-enumeration); acceptance mirrors the Breeze register flow (`Hash::make` + `Auth::login`). Onboarding completeness is gated by a middleware on the web group. Design tokens are CSS custom properties in the existing `@layer base` pattern (Tailwind v3.4, **not** v4 `@theme`).

**Tech Stack:** PHP 8.2 / Laravel 12, Inertia v2 + Vue 3.5 + TypeScript, Tailwind v3.4 + Reka UI/shadcn, PHPUnit (plain, `RefreshDatabase`), Vitest + `@vue/test-utils`, Laravel Pint, ESLint, Prettier, Ziggy.

---

## Design decisions resolved (review these — they were under-specified in PRD-016)

1. **Owner display name.** `restaurant:provision --name=` is the *restaurant* name. The owner `User.name` (NOT NULL) is seeded with a placeholder derived from the email local-part; the acceptance form collects the real name alongside the password. (Avoids adding an `--owner-name` flag the PRD didn't define.)
2. **Invitation secret.** Token-based, not Laravel-signed. A 64-char `Str::random` token goes in the link; only its `hash('sha256', …)` is stored in `invitations.token`. The plaintext is shown once in the command output and never persisted. Matches PRD "Token gehasht gespeichert (Anti-Enumeration)".
3. **`capacity` default.** Provisioner seeds `capacity = 0` (NOT NULL, no DB default). The wizard's tables step recomputes `capacity = Σ active table seats` on every table mutation so availability works the moment the restaurant goes live.
4. **Gating coupling.** The wizard POSTs to its own `onboarding.*` routes that *reuse* the existing FormRequests (`TableRequest`) and Vue components. The gating middleware therefore only needs to exempt `onboarding.*` + `logout`, never the settings routes.
5. **"Live" definition.** `onboarding_completed_at` is set the first moment the Pflicht-Kern is satisfied: restaurant master data present (always true post-provision) **and** `opening_hours` non-empty (i.e. not the seeded `[]`) **and** ≥1 active table. Computed by `OnboardingProgress`.
6. **Staff of a not-yet-live restaurant** are redirected to a read-only `onboarding.pending` placeholder (never the wizard).

---

## File Structure

**Created — backend**
- `database/migrations/2026_05_24_000001_make_users_password_nullable.php` — password nullable for invited-but-not-accepted users.
- `database/migrations/2026_05_24_000002_add_onboarding_completed_at_to_restaurants.php`
- `database/migrations/2026_05_24_000003_create_invitations_table.php`
- `app/Models/Invitation.php` — hashed-token invitation (owner + staff).
- `database/factories/InvitationFactory.php`
- `app/Services/RestaurantProvisioner.php` — provision(name, slug, email, timezone): Invitation.
- `app/Console/Commands/ProvisionRestaurantCommand.php` — `restaurant:provision`.
- `app/Http/Controllers/Onboarding/AcceptInvitationController.php` — show/store password-set + login.
- `app/Http/Controllers/Onboarding/OnboardingWizardController.php` — wizard show + per-step store.
- `app/Http/Requests/Onboarding/AcceptInvitationRequest.php`
- `app/Http/Requests/Onboarding/RestaurantInfoRequest.php`
- `app/Http/Requests/Onboarding/OpeningHoursRequest.php`
- `app/Http/Requests/Onboarding/TonalityRequest.php`
- `app/Http/Requests/Onboarding/InviteStaffRequest.php`
- `app/Support/OnboardingProgress.php` — step-completion value object.
- `app/Http/Middleware/EnsureOnboardingComplete.php`
- `app/Policies/InvitationPolicy.php` — owner-only staff invites.

**Created — frontend**
- `resources/js/pages/Onboarding/AcceptInvitation.vue`
- `resources/js/pages/Onboarding/InvitationError.vue`
- `resources/js/pages/Onboarding/Pending.vue`
- `resources/js/pages/Onboarding/Wizard.vue` — shell + step switch.
- `resources/js/pages/Onboarding/steps/StepRestaurantInfo.vue`
- `resources/js/pages/Onboarding/steps/StepOpeningHours.vue`
- `resources/js/pages/Onboarding/steps/StepTables.vue`
- `resources/js/pages/Onboarding/steps/StepTonality.vue`
- `resources/js/pages/Onboarding/steps/StepTeam.vue`
- `resources/js/layouts/OnboardingLayout.vue` — minimal dark-topbar (C-look) shell.
- `resources/js/lib/reservationStatus.ts` — central status colour/label map.
- `resources/js/lib/reservationStatus.test.ts`
- `resources/js/pages/Onboarding/steps/StepOpeningHours.test.ts`

**Modified**
- `app/Models/Restaurant.php` — `onboarding_completed_at` fillable + cast; `users()` + `invitations()` relations; `isLive()`.
- `app/Models/User.php` — `invitations()` relation (sent staff invites) optional; nothing else.
- `database/factories/RestaurantFactory.php` — `onboarded()` / `pendingOnboarding()` states.
- `database/factories/UserFactory.php` — `owner()` state.
- `app/Providers/AuthServiceProvider.php` — register `Invitation => InvitationPolicy`.
- `app/Http/Middleware/HandleInertiaRequests.php` — share `restaurant.onboarding_completed_at` + onboarding reminders.
- `app/Http/Controllers/DashboardController.php` — pass `onboardingReminders` prop.
- `routes/web.php` — onboarding routes (guest accept + auth wizard + pending).
- `bootstrap/app.php` — append `EnsureOnboardingComplete` to web group.
- `resources/css/app.css` — status-colour + topbar tokens (direction C).
- `tailwind.config.js` — map new tokens.
- `resources/js/types/index.ts` — `Restaurant.onboarding_completed_at`, onboarding types.

---

# Epic A — Data model & migrations

### Task A1: Make `users.password` nullable

**Files:**
- Create: `database/migrations/2026_05_24_000001_make_users_password_nullable.php`
- Test: `tests/Feature/Onboarding/UserPasswordNullableTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserPasswordNullableTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_be_persisted_without_a_password(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->assertNull($user->fresh()->password);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UserPasswordNullableTest`
Expected: FAIL — `NOT NULL constraint failed: users.password`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=UserPasswordNullableTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_05_24_000001_make_users_password_nullable.php tests/Feature/Onboarding/UserPasswordNullableTest.php
git commit -m "Make users.password nullable for pending invitations"
```

---

### Task A2: Add `restaurants.onboarding_completed_at`

**Files:**
- Create: `database/migrations/2026_05_24_000002_add_onboarding_completed_at_to_restaurants.php`
- Modify: `app/Models/Restaurant.php`
- Test: `tests/Feature/Onboarding/RestaurantOnboardingColumnTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RestaurantOnboardingColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_completed_at_is_castable_and_defaults_to_null(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->assertNull($restaurant->onboarding_completed_at);
        $this->assertFalse($restaurant->isLive());

        $restaurant->update(['onboarding_completed_at' => now()]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $restaurant->fresh()->onboarding_completed_at);
        $this->assertTrue($restaurant->fresh()->isLive());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RestaurantOnboardingColumnTest`
Expected: FAIL — unknown column / `isLive()` not defined.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->timestamp('onboarding_completed_at')->nullable()->after('tonality');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};
```

- [ ] **Step 4: Wire up the model**

In `app/Models/Restaurant.php`, add `'onboarding_completed_at'` to `$fillable`, add the cast, and add the helper + relations. Add to the `$fillable` array:

```php
'onboarding_completed_at',
```

Add to the `casts()` return array:

```php
'onboarding_completed_at' => 'datetime',
```

Add these methods to the class body (after the existing relations):

```php
/**
 * @return \Illuminate\Database\Eloquent\Relations\HasMany<User, $this>
 */
public function users(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(User::class);
}

/**
 * @return \Illuminate\Database\Eloquent\Relations\HasMany<Invitation, $this>
 */
public function invitations(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(Invitation::class);
}

/**
 * A restaurant is "live" once the onboarding Pflicht-Kern is complete.
 */
public function isLive(): bool
{
    return $this->onboarding_completed_at !== null;
}
```

Add the imports at the top if missing: `use App\Models\User;` is the same namespace (no import needed); add `use App\Models\Invitation;` is same namespace too — **no import lines needed** (both live in `App\Models`). Ensure `HasMany` is referenced fully-qualified as above to avoid touching the existing import block.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=RestaurantOnboardingColumnTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_24_000002_add_onboarding_completed_at_to_restaurants.php app/Models/Restaurant.php tests/Feature/Onboarding/RestaurantOnboardingColumnTest.php
git commit -m "Add restaurants.onboarding_completed_at with isLive helper"
```

---

### Task A3: `invitations` table + Invitation model + factory

**Files:**
- Create: `database/migrations/2026_05_24_000003_create_invitations_table.php`
- Create: `app/Models/Invitation.php`
- Create: `database/factories/InvitationFactory.php`
- Test: `tests/Feature/Onboarding/InvitationModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InvitationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_hashes_a_plaintext_token_and_finds_by_it(): void
    {
        $restaurant = Restaurant::factory()->create();
        $plain = Invitation::generateToken();

        $invitation = Invitation::create([
            'restaurant_id' => $restaurant->id,
            'email' => 'owner@example.test',
            'role' => UserRole::Owner,
            'token' => Invitation::hashToken($plain),
            'expires_at' => now()->addDays(7),
        ]);

        $this->assertNotSame($plain, $invitation->token);
        $this->assertTrue(Invitation::findByToken($plain)?->is($invitation));
        $this->assertNull(Invitation::findByToken('wrong-token'));
        $this->assertSame(UserRole::Owner, $invitation->role);
        $this->assertFalse($invitation->isExpired());
        $this->assertFalse($invitation->isAccepted());
    }

    public function test_expired_and_accepted_states(): void
    {
        $restaurant = Restaurant::factory()->create();

        $expired = Invitation::factory()->for($restaurant)->create(['expires_at' => now()->subDay()]);
        $accepted = Invitation::factory()->for($restaurant)->create(['accepted_at' => now()]);

        $this->assertTrue($expired->isExpired());
        $this->assertTrue($accepted->isAccepted());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=InvitationModelTest`
Expected: FAIL — `Class "App\Models\Invitation" not found`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('email');
            $table->string('role')->default('staff');
            $table->string('token')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Scopes\RestaurantScope;
use Illuminate\Support\Str;

/**
 * A tokenised invitation to join a restaurant as owner or staff.
 *
 * The plaintext token is shown exactly once (at provision/invite time) and
 * never stored — only its SHA-256 hash lives in the `token` column, so a
 * leaked database row cannot be replayed against the acceptance route.
 */
#[ScopedBy(RestaurantScope::class)]
final class Invitation extends Model
{
    /** @use HasFactory<\Database\Factories\InvitationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'restaurant_id',
        'email',
        'role',
        'token',
        'expires_at',
        'accepted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Look up a pending invitation by its plaintext token. Runs without the
     * tenant scope because acceptance happens before the guest is logged in.
     */
    public static function findByToken(string $plain): ?self
    {
        return self::query()
            ->withoutGlobalScopes()
            ->where('token', self::hashToken($plain))
            ->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isExpired() && ! $this->isAccepted();
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => UserRole::Staff,
            'token' => Invitation::hashToken(Invitation::generateToken()),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => ['role' => UserRole::Owner]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['accepted_at' => now()]);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=InvitationModelTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_05_24_000003_create_invitations_table.php app/Models/Invitation.php database/factories/InvitationFactory.php tests/Feature/Onboarding/InvitationModelTest.php
git commit -m "Add Invitation model with hashed token lookup"
```

---

### Task A4: Factory states for owner users & onboarded restaurants

**Files:**
- Modify: `database/factories/UserFactory.php`
- Modify: `database/factories/RestaurantFactory.php`
- Test: `tests/Feature/Onboarding/OnboardingFactoryStatesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OnboardingFactoryStatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_state_sets_role_and_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->assertSame(UserRole::Owner, $owner->role);
        $this->assertSame($restaurant->id, $owner->restaurant_id);
    }

    public function test_restaurant_onboarded_and_pending_states(): void
    {
        $this->assertNotNull(Restaurant::factory()->onboarded()->create()->onboarding_completed_at);
        $this->assertNull(Restaurant::factory()->pendingOnboarding()->create()->onboarding_completed_at);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OnboardingFactoryStatesTest`
Expected: FAIL — `owner()` / `onboarded()` not defined.

- [ ] **Step 3: Add the states**

In `database/factories/UserFactory.php` add (after `forRestaurant`):

```php
public function owner(): static
{
    return $this->state(fn () => ['role' => UserRole::Owner]);
}
```

In `database/factories/RestaurantFactory.php` add (after `definition`):

```php
public function onboarded(): static
{
    return $this->state(fn () => ['onboarding_completed_at' => now()]);
}

public function pendingOnboarding(): static
{
    return $this->state(fn () => [
        'onboarding_completed_at' => null,
        'opening_hours' => [],
    ]);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OnboardingFactoryStatesTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/factories/UserFactory.php database/factories/RestaurantFactory.php tests/Feature/Onboarding/OnboardingFactoryStatesTest.php
git commit -m "Add owner and onboarding factory states"
```

---

# Epic B — Provisioning (service + command)

### Task B1: `RestaurantProvisioner` service

**Files:**
- Create: `app/Services/RestaurantProvisioner.php`
- Test: `tests/Feature/Onboarding/RestaurantProvisionerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\Tonality;
use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\RestaurantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class RestaurantProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private function provisioner(): RestaurantProvisioner
    {
        return $this->app->make(RestaurantProvisioner::class);
    }

    public function test_it_creates_restaurant_owner_and_invitation_with_safe_defaults(): void
    {
        $result = $this->provisioner()->provision(
            name: 'Trattoria Bella',
            slug: 'trattoria-bella',
            email: 'chef@bella.test',
            timezone: 'Europe/Berlin',
        );

        $restaurant = Restaurant::query()->where('slug', 'trattoria-bella')->sole();
        $this->assertSame(0, $restaurant->capacity);
        $this->assertSame([], $restaurant->opening_hours);
        $this->assertSame(Tonality::Formal, $restaurant->tonality);
        $this->assertNull($restaurant->onboarding_completed_at);

        $owner = User::query()->where('email', 'chef@bella.test')->sole();
        $this->assertSame(UserRole::Owner, $owner->role);
        $this->assertSame($restaurant->id, $owner->restaurant_id);
        $this->assertNull($owner->password);

        $this->assertInstanceOf(Invitation::class, $result->invitation);
        $this->assertSame(UserRole::Owner, $result->invitation->role);
        $this->assertNotEmpty($result->plainToken);
        $this->assertTrue(Invitation::findByToken($result->plainToken)?->is($result->invitation));
    }

    public function test_it_rejects_a_duplicate_slug(): void
    {
        Restaurant::factory()->create(['slug' => 'taken']);

        $this->expectException(ValidationException::class);

        $this->provisioner()->provision('X', 'taken', 'x@example.test', 'Europe/Berlin');
    }

    public function test_it_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dupe@example.test']);

        $this->expectException(ValidationException::class);

        $this->provisioner()->provision('X', 'fresh-slug', 'dupe@example.test', 'Europe/Berlin');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RestaurantProvisionerTest`
Expected: FAIL — `Class "App\Services\RestaurantProvisioner" not found`.

- [ ] **Step 3: Write the service (and its result DTO)**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Tonality;
use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Carries the freshly created invitation together with its one-time
 * plaintext token, which only exists in memory at provision time.
 */
final readonly class ProvisionResult
{
    public function __construct(
        public Restaurant $restaurant,
        public User $owner,
        public Invitation $invitation,
        public string $plainToken,
    ) {}
}

/**
 * Creates a restaurant, its owner (password-less until the invitation is
 * accepted) and an owner invitation in one transaction. Reused by the
 * `restaurant:provision` command today and the Super-Admin UI later.
 */
final class RestaurantProvisioner
{
    private const INVITE_TTL_DAYS = 7;

    public function provision(string $name, string $slug, string $email, string $timezone = 'Europe/Berlin'): ProvisionResult
    {
        $this->validate($slug, $email, $timezone);

        return DB::transaction(function () use ($name, $slug, $email, $timezone): ProvisionResult {
            $restaurant = Restaurant::create([
                'name' => $name,
                'slug' => $slug,
                'timezone' => $timezone,
                // NOT NULL columns without DB defaults — seeded with safe
                // placeholders the wizard overwrites (see PRD-016 §Provisionierung).
                'capacity' => 0,
                'opening_hours' => [],
                'tonality' => Tonality::Formal,
            ]);

            $owner = User::create([
                'name' => $this->placeholderName($email),
                'email' => $email,
                'password' => null,
                'restaurant_id' => $restaurant->id,
                'role' => UserRole::Owner,
            ]);

            $plainToken = Invitation::generateToken();

            $invitation = Invitation::create([
                'restaurant_id' => $restaurant->id,
                'email' => $email,
                'role' => UserRole::Owner,
                'token' => Invitation::hashToken($plainToken),
                'expires_at' => now()->addDays(self::INVITE_TTL_DAYS),
            ]);

            return new ProvisionResult($restaurant, $owner, $invitation, $plainToken);
        });
    }

    private function validate(string $slug, string $email, string $timezone): void
    {
        Validator::make(
            ['slug' => $slug, 'email' => $email, 'timezone' => $timezone],
            [
                'slug' => ['required', 'string', 'max:255', 'unique:restaurants,slug'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'timezone' => ['required', 'timezone'],
            ],
        )->validate();
    }

    private function placeholderName(string $email): string
    {
        return Str::of($email)->before('@')->headline()->value();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=RestaurantProvisionerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RestaurantProvisioner.php tests/Feature/Onboarding/RestaurantProvisionerTest.php
git commit -m "Add RestaurantProvisioner service with safe NOT-NULL defaults"
```

---

### Task B2: `restaurant:provision` command

**Files:**
- Create: `app/Console/Commands/ProvisionRestaurantCommand.php`
- Test: `tests/Feature/Onboarding/ProvisionRestaurantCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProvisionRestaurantCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_provisions_and_prints_the_acceptance_link(): void
    {
        $this->artisan('restaurant:provision', [
            '--name' => 'Trattoria Bella',
            '--slug' => 'trattoria-bella',
            '--email' => 'chef@bella.test',
        ])
            ->expectsOutputToContain('/onboarding/accept/')
            ->assertSuccessful();

        $this->assertDatabaseHas('restaurants', ['slug' => 'trattoria-bella']);
        $this->assertDatabaseHas('users', ['email' => 'chef@bella.test', 'role' => 'owner']);
    }

    public function test_it_fails_on_a_duplicate_slug(): void
    {
        Restaurant::factory()->create(['slug' => 'taken']);

        $this->artisan('restaurant:provision', [
            '--name' => 'X',
            '--slug' => 'taken',
            '--email' => 'new@bella.test',
        ])
            ->expectsOutputToContain('slug')
            ->assertFailed();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProvisionRestaurantCommandTest`
Expected: FAIL — command `restaurant:provision` not defined.

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RestaurantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class ProvisionRestaurantCommand extends Command
{
    protected $signature = 'restaurant:provision
        {--name= : Restaurant display name}
        {--slug= : Public URL slug (unique)}
        {--email= : Owner email address (unique)}
        {--timezone=Europe/Berlin : IANA timezone}';

    protected $description = 'Create a restaurant, its owner and an owner invitation, and print the acceptance link.';

    public function handle(RestaurantProvisioner $provisioner): int
    {
        $name = (string) $this->option('name');
        $slug = (string) $this->option('slug');
        $email = (string) $this->option('email');
        $timezone = (string) $this->option('timezone');

        if ($name === '' || $slug === '' || $email === '') {
            $this->error('--name, --slug and --email are required.');

            return self::FAILURE;
        }

        try {
            $result = $provisioner->provision($name, $slug, $email, $timezone);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $link = route('onboarding.accept', ['token' => $result->plainToken]);

        $this->info(sprintf('Provisioned "%s" (#%d).', $result->restaurant->name, $result->restaurant->id));
        $this->line('Owner: '.$result->owner->email);
        $this->newLine();
        $this->line('Acceptance link (valid 7 days, shown once):');
        $this->line($link);

        return self::SUCCESS;
    }
}
```

> Note: `route('onboarding.accept', …)` requires the route from Task C4. Implement Epic C's route before running, or temporarily assert only DB state. The TDD order below adds the route in C4; if executing strictly task-by-task, move B2's run/commit to after C4, or stub the route name now.

- [ ] **Step 4: Run test to verify it passes** (after C4's route exists)

Run: `php artisan test --filter=ProvisionRestaurantCommandTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/ProvisionRestaurantCommand.php tests/Feature/Onboarding/ProvisionRestaurantCommandTest.php
git commit -m "Add restaurant:provision command"
```

---

# Epic C — Invitation acceptance

### Task C1: Acceptance FormRequest

**Files:**
- Create: `app/Http/Requests/Onboarding/AcceptInvitationRequest.php`
- Test: covered via C3 feature test (no standalone unit test — rules are exercised end-to-end).

- [ ] **Step 1: Write the FormRequest**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validates the invitation-acceptance form. Authorization is implicit: the
 * route is guest-only and the token is validated in the controller before
 * this request's rules ever persist anything.
 */
class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Requests/Onboarding/AcceptInvitationRequest.php
git commit -m "Add AcceptInvitationRequest"
```

---

### Task C2: Acceptance controller `show` + routes scaffold

**Files:**
- Create: `app/Http/Controllers/Onboarding/AcceptInvitationController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Onboarding/AcceptInvitationShowTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Invitation;
use App\Models\Restaurant;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AcceptInvitationShowTest extends TestCase
{
    use RefreshDatabase;

    private function inviteWithToken(array $state = []): array
    {
        $restaurant = Restaurant::factory()->create();
        $plain = Invitation::generateToken();
        $invitation = Invitation::factory()->for($restaurant)->owner()->create([
            'email' => 'owner@example.test',
            'token' => Invitation::hashToken($plain),
            ...$state,
        ]);

        return [$plain, $invitation];
    }

    public function test_a_valid_token_renders_the_acceptance_form(): void
    {
        [$plain] = $this->inviteWithToken();

        $this->get(route('onboarding.accept', ['token' => $plain]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Onboarding/AcceptInvitation')
                ->where('email', 'owner@example.test')
                ->where('token', $plain)
            );
    }

    public function test_an_unknown_token_renders_the_error_page(): void
    {
        $this->get(route('onboarding.accept', ['token' => 'nope']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Onboarding/InvitationError'));
    }

    public function test_an_expired_token_renders_the_error_page(): void
    {
        [$plain] = $this->inviteWithToken(['expires_at' => now()->subDay()]);

        $this->get(route('onboarding.accept', ['token' => $plain]))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Onboarding/InvitationError'));
    }

    public function test_an_accepted_token_renders_the_error_page(): void
    {
        [$plain] = $this->inviteWithToken(['accepted_at' => now()]);

        $this->get(route('onboarding.accept', ['token' => $plain]))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Onboarding/InvitationError'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AcceptInvitationShowTest`
Expected: FAIL — route/controller missing.

- [ ] **Step 3: Write the controller `show` (store added in C3)**

```php
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

    private function reason(?Invitation $invitation): string
    {
        return match (true) {
            $invitation === null => 'invalid',
            $invitation->isAccepted() => 'accepted',
            default => 'expired',
        };
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, add (top-level, guest-accessible — no `auth` middleware). Add the controller import to the existing `use` block, then:

```php
Route::get('onboarding/accept/{token}', [AcceptInvitationController::class, 'show'])
    ->middleware('guest')
    ->name('onboarding.accept');

Route::post('onboarding/accept/{token}', [AcceptInvitationController::class, 'store'])
    ->middleware('guest')
    ->name('onboarding.accept.store');
```

- [ ] **Step 5: Create placeholder Vue pages so Inertia can render**

Create minimal `resources/js/pages/Onboarding/AcceptInvitation.vue` and `resources/js/pages/Onboarding/InvitationError.vue` (full versions in Task C5). Minimal stubs to make the feature test pass:

```vue
<!-- resources/js/pages/Onboarding/InvitationError.vue -->
<script setup lang="ts">
defineProps<{ reason: string }>();
</script>
<template>
    <div>{{ reason }}</div>
</template>
```

```vue
<!-- resources/js/pages/Onboarding/AcceptInvitation.vue -->
<script setup lang="ts">
defineProps<{ email: string; token: string; restaurantName: string }>();
</script>
<template>
    <div>{{ email }}</div>
</template>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=AcceptInvitationShowTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Onboarding/AcceptInvitationController.php routes/web.php resources/js/pages/Onboarding/AcceptInvitation.vue resources/js/pages/Onboarding/InvitationError.vue tests/Feature/Onboarding/AcceptInvitationShowTest.php
git commit -m "Render invitation acceptance form and error page"
```

---

### Task C3: Acceptance controller `store` (set password + login)

**Files:**
- Modify: `app/Http/Controllers/Onboarding/AcceptInvitationController.php`
- Test: `tests/Feature/Onboarding/AcceptInvitationStoreTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Invitation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AcceptInvitationStoreTest extends TestCase
{
    use RefreshDatabase;

    private function pendingOwner(): array
    {
        $restaurant = Restaurant::factory()->pendingOnboarding()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create([
            'email' => 'owner@example.test',
            'password' => null,
        ]);
        $plain = Invitation::generateToken();
        Invitation::factory()->for($restaurant)->owner()->create([
            'email' => 'owner@example.test',
            'token' => Invitation::hashToken($plain),
        ]);

        return [$plain, $owner];
    }

    public function test_accepting_sets_name_password_logs_in_and_redirects_to_wizard(): void
    {
        [$plain, $owner] = $this->pendingOwner();

        $response = $this->post(route('onboarding.accept.store', ['token' => $plain]), [
            'name' => 'Mara Chef',
            'password' => 'sup3r-secret-pw',
            'password_confirmation' => 'sup3r-secret-pw',
        ]);

        $response->assertRedirect(route('onboarding.wizard'));
        $this->assertAuthenticatedAs($owner->fresh());

        $owner->refresh();
        $this->assertSame('Mara Chef', $owner->name);
        $this->assertTrue(Hash::check('sup3r-secret-pw', $owner->password));
        $this->assertNotNull($owner->email_verified_at);
        $this->assertNotNull(Invitation::findByToken($plain)?->accepted_at ?? Invitation::query()->withoutGlobalScopes()->first()->accepted_at);
    }

    public function test_a_used_token_cannot_be_replayed(): void
    {
        [$plain] = $this->pendingOwner();

        $this->post(route('onboarding.accept.store', ['token' => $plain]), [
            'name' => 'Mara Chef',
            'password' => 'sup3r-secret-pw',
            'password_confirmation' => 'sup3r-secret-pw',
        ])->assertRedirect(route('onboarding.wizard'));

        $this->post('/logout');

        $this->post(route('onboarding.accept.store', ['token' => $plain]), [
            'name' => 'Intruder',
            'password' => 'another-pw-123',
            'password_confirmation' => 'another-pw-123',
        ])->assertInertia(fn ($page) => $page->component('Onboarding/InvitationError'));
    }

    public function test_validation_errors_are_returned(): void
    {
        [$plain] = $this->pendingOwner();

        $this->from(route('onboarding.accept', ['token' => $plain]))
            ->post(route('onboarding.accept.store', ['token' => $plain]), [
                'name' => '',
                'password' => 'short',
                'password_confirmation' => 'mismatch',
            ])
            ->assertSessionHasErrors(['name', 'password']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AcceptInvitationStoreTest`
Expected: FAIL — `store` not defined / used-token path missing.

- [ ] **Step 3: Add `store` to the controller**

Add to `AcceptInvitationController`:

```php
public function store(AcceptInvitationRequest $request, string $token): RedirectResponse
{
    $invitation = Invitation::findByToken($token);

    if ($invitation === null || ! $invitation->isPending()) {
        // Render the error page on a replayed/expired token even on POST.
        return back()->setStatusCode(303)->setTargetUrl(route('onboarding.accept', ['token' => $token]));
    }

    $validated = $request->validated();

    $user = DB::transaction(function () use ($invitation, $validated): User {
        $user = User::query()
            ->withoutGlobalScopes()
            ->where('restaurant_id', $invitation->restaurant_id)
            ->where('email', $invitation->email)
            ->firstOrFail();

        $user->forceFill([
            'name' => $validated['name'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
        ])->save();

        $invitation->forceFill(['accepted_at' => now()])->save();

        return $user;
    });

    Auth::login($user);

    return to_route('onboarding.wizard');
}
```

> The replay-after-logout case: `findByToken` still finds the invitation but `isPending()` is false (accepted_at set). Returning to the GET route re-renders `Onboarding/InvitationError`. Confirm the test's `assertInertia` is satisfied because the redirect target GETs the error page; if the test asserts the component directly on the POST response, change it to `->assertRedirect(route('onboarding.accept', ['token' => $plain]))` and assert the component on a follow-up GET. Adjust the test to follow redirects: replace the second POST assertion with:
>
> ```php
> $this->followingRedirects()
>     ->post(route('onboarding.accept.store', ['token' => $plain]), [...])
>     ->assertInertia(fn ($page) => $page->component('Onboarding/InvitationError'));
> ```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AcceptInvitationStoreTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Onboarding/AcceptInvitationController.php tests/Feature/Onboarding/AcceptInvitationStoreTest.php
git commit -m "Accept invitation: set password, verify email, log in"
```

---

### Task C4: Confirm provision command link + run B2

- [ ] **Step 1:** Now that `onboarding.accept` exists, run the deferred Task B2 test.

Run: `php artisan test --filter=ProvisionRestaurantCommandTest`
Expected: PASS.

- [ ] **Step 2: Commit** (if B2 was left uncommitted)

```bash
git add -A && git commit -m "Wire restaurant:provision link to onboarding.accept route" || true
```

---

### Task C5: Full acceptance Vue pages (real UI)

**Files:**
- Modify: `resources/js/pages/Onboarding/AcceptInvitation.vue`
- Modify: `resources/js/pages/Onboarding/InvitationError.vue`
- Test: rendered by C2/C3 feature tests (component names already asserted).

- [ ] **Step 1: Write the acceptance page**

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';

const props = defineProps<{ email: string; token: string; restaurantName: string }>();

const form = useForm({
    name: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('onboarding.accept.store', { token: props.token }), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <OnboardingLayout :title="`Willkommen bei ${restaurantName}`">
        <Head title="Einladung annehmen" />
        <form class="grid gap-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="email">E-Mail</Label>
                <Input id="email" :model-value="email" type="email" disabled />
            </div>
            <div class="grid gap-2">
                <Label for="name">Ihr Name</Label>
                <Input id="name" v-model="form.name" required autofocus autocomplete="name" />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="password">Passwort</Label>
                <Input id="password" v-model="form.password" type="password" required autocomplete="new-password" />
                <InputError :message="form.errors.password" />
            </div>
            <div class="grid gap-2">
                <Label for="password_confirmation">Passwort bestätigen</Label>
                <Input id="password_confirmation" v-model="form.password_confirmation" type="password" required autocomplete="new-password" />
            </div>
            <Button :disabled="form.processing" type="submit">Konto aktivieren</Button>
        </form>
    </OnboardingLayout>
</template>
```

- [ ] **Step 2: Write the error page**

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';

const props = defineProps<{ reason: string }>();

const MESSAGE: Record<string, string> = {
    invalid: 'Dieser Einladungs-Link ist ungültig.',
    expired: 'Dieser Einladungs-Link ist abgelaufen. Bitte fordern Sie einen neuen an.',
    accepted: 'Diese Einladung wurde bereits angenommen. Bitte melden Sie sich an.',
};
</script>

<template>
    <OnboardingLayout title="Einladung">
        <Head title="Einladung" />
        <p class="text-muted-foreground">{{ MESSAGE[reason] ?? MESSAGE.invalid }}</p>
    </OnboardingLayout>
</template>
```

> `OnboardingLayout` is created in Task E3. If executing strictly in order, temporarily import `AppLayout` here and swap to `OnboardingLayout` in E3.

- [ ] **Step 3: Verify build + lint**

Run: `npm run build && npm run lint:check && npm run format:check`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/Onboarding/AcceptInvitation.vue resources/js/pages/Onboarding/InvitationError.vue
git commit -m "Build invitation acceptance and error pages"
```

---

# Epic D — Wizard + gating

### Task D1: `OnboardingProgress` value object

**Files:**
- Create: `app/Support/OnboardingProgress.php`
- Test: `tests/Feature/Onboarding/OnboardingProgressTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Restaurant;
use App\Models\Table;
use App\Support\OnboardingProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OnboardingProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_is_incomplete_without_hours_and_tables(): void
    {
        $restaurant = Restaurant::factory()->pendingOnboarding()->create();
        $progress = OnboardingProgress::for($restaurant);

        $this->assertFalse($progress->isCoreComplete());
        $this->assertFalse($progress->stepComplete('hours'));
        $this->assertFalse($progress->stepComplete('tables'));
        $this->assertTrue($progress->stepComplete('restaurant')); // master data exists post-provision
    }

    public function test_core_is_complete_with_hours_and_at_least_one_active_table(): void
    {
        $restaurant = Restaurant::factory()->create([
            'opening_hours' => ['mon' => [['from' => '18:00', 'to' => '22:00']]],
        ]);
        Table::factory()->for($restaurant)->create(['active' => true]);

        $progress = OnboardingProgress::for($restaurant);

        $this->assertTrue($progress->stepComplete('hours'));
        $this->assertTrue($progress->stepComplete('tables'));
        $this->assertTrue($progress->isCoreComplete());
    }

    public function test_optional_steps_are_tracked(): void
    {
        $restaurant = Restaurant::factory()->create(['tonality' => \App\Enums\Tonality::Casual]);

        $progress = OnboardingProgress::for($restaurant);
        // Tonality always has a value (enum), so "tonality" optional step is
        // considered addressed; team is complete once any staff invite exists.
        $this->assertTrue($progress->stepComplete('tonality'));
        $this->assertFalse($progress->stepComplete('team'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OnboardingProgressTest`
Expected: FAIL — class missing.

- [ ] **Step 3: Write the value object**

```php
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
            'tonality' => true, // enum column always has a value
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OnboardingProgressTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/OnboardingProgress.php tests/Feature/Onboarding/OnboardingProgressTest.php
git commit -m "Add OnboardingProgress derived from persisted data"
```

---

### Task D2: Step FormRequests

**Files:**
- Create: `app/Http/Requests/Onboarding/RestaurantInfoRequest.php`
- Create: `app/Http/Requests/Onboarding/OpeningHoursRequest.php`
- Create: `app/Http/Requests/Onboarding/TonalityRequest.php`
- Create: `app/Http/Requests/Onboarding/InviteStaffRequest.php`
- Test: `tests/Feature/Onboarding/OnboardingFormRequestsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Http\Requests\Onboarding\OpeningHoursRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class OnboardingFormRequestsTest extends TestCase
{
    public function test_opening_hours_accepts_valid_schedule(): void
    {
        $validator = Validator::make(
            ['opening_hours' => ['mon' => [['from' => '11:30', 'to' => '14:30']], 'tue' => []]],
            (new OpeningHoursRequest)->rules(),
        );

        $this->assertFalse($validator->fails());
    }

    public function test_opening_hours_rejects_bad_time_and_unknown_day(): void
    {
        $validator = Validator::make(
            ['opening_hours' => ['mon' => [['from' => '25:00', 'to' => '14:30']]]],
            (new OpeningHoursRequest)->rules(),
        );
        $this->assertTrue($validator->fails());

        $validator2 = Validator::make(
            ['opening_hours' => ['funday' => [['from' => '10:00', 'to' => '12:00']]]],
            (new OpeningHoursRequest)->rules(),
        );
        $this->assertTrue($validator2->fails());
    }

    public function test_opening_hours_requires_at_least_one_open_block(): void
    {
        $validator = Validator::make(
            ['opening_hours' => ['mon' => [], 'tue' => []]],
            (new OpeningHoursRequest)->rules(),
        );
        // All-empty schedule is a "Ruhetag every day" — not a live restaurant.
        $this->assertTrue($validator->fails());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OnboardingFormRequestsTest`
Expected: FAIL — request classes missing.

- [ ] **Step 3: Write `OpeningHoursRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the opening-hours schedule against the PRD-001 shape:
 *   ['mon' => [['from' => 'HH:MM', 'to' => 'HH:MM'], ...], 'tue' => [], ...]
 * Day keys are the seven lowercase three-letter abbreviations. At least one
 * day must carry one open block, otherwise the restaurant is closed forever.
 */
class OpeningHoursRequest extends FormRequest
{
    private const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
    private const TIME = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, ValidationRule|string|Closure>>
     */
    public function rules(): array
    {
        $dayKeys = implode(',', self::DAYS);

        return [
            'opening_hours' => ['required', 'array', $this->atLeastOneOpenBlock()],
            'opening_hours.*' => ['array'],
            'opening_hours.*.*.from' => ['required_with:opening_hours.*.*', 'string', self::TIME],
            'opening_hours.*.*.to' => ['required_with:opening_hours.*.*', 'string', self::TIME],
            // Reject unknown day keys.
            'opening_hours' => ['required', 'array:'.$dayKeys, $this->atLeastOneOpenBlock()],
        ];
    }

    private function atLeastOneOpenBlock(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                $fail('Öffnungszeiten fehlen.');

                return;
            }

            foreach ($value as $blocks) {
                if (is_array($blocks) && $blocks !== []) {
                    return;
                }
            }

            $fail('Mindestens ein Tag muss eine Öffnungszeit haben.');
        };
    }
}
```

> Note: the duplicate `'opening_hours'` key in `rules()` is intentional-looking but PHP keeps only the last entry. Collapse to a single `'opening_hours'` rule with `'array:'.$dayKeys` and the closure (remove the first `'opening_hours'` line). Final array must have exactly one `opening_hours` key:
>
> ```php
> return [
>     'opening_hours' => ['required', 'array:'.$dayKeys, $this->atLeastOneOpenBlock()],
>     'opening_hours.*' => ['array'],
>     'opening_hours.*.*.from' => ['required', 'string', self::TIME],
>     'opening_hours.*.*.to' => ['required', 'string', self::TIME],
> ];
> ```

- [ ] **Step 4: Write `RestaurantInfoRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Models\Restaurant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RestaurantInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        $restaurantId = $this->user()?->restaurant_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique(Restaurant::class, 'slug')->ignore($restaurantId),
            ],
            'timezone' => ['required', 'timezone'],
        ];
    }
}
```

- [ ] **Step 5: Write `TonalityRequest` and `InviteStaffRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Enums\Tonality;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TonalityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'tonality' => ['required', Rule::enum(Tonality::class)],
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=OnboardingFormRequestsTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Onboarding/ tests/Feature/Onboarding/OnboardingFormRequestsTest.php
git commit -m "Add onboarding step FormRequests"
```

---

### Task D3: `InvitationPolicy` (owner-only staff invites)

**Files:**
- Create: `app/Policies/InvitationPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php`
- Test: `tests/Feature/Onboarding/InvitationPolicyTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class InvitationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_may_invite_staff_but_staff_may_not(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();
        $staff = User::factory()->forRestaurant($restaurant)->create();

        $this->assertTrue(Gate::forUser($owner)->allows('create', \App\Models\Invitation::class));
        $this->assertFalse(Gate::forUser($staff)->allows('create', \App\Models\Invitation::class));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=InvitationPolicyTest`
Expected: FAIL — policy unregistered.

- [ ] **Step 3: Write the policy**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

final class InvitationPolicy
{
    public function create(User $user): bool
    {
        return $user->restaurant_id !== null && $user->role === UserRole::Owner;
    }
}
```

- [ ] **Step 4: Register it**

In `app/Providers/AuthServiceProvider.php`, add to the `$policies` array:

```php
Invitation::class => InvitationPolicy::class,
```

Add the imports: `use App\Models\Invitation;` and `use App\Policies\InvitationPolicy;`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=InvitationPolicyTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Policies/InvitationPolicy.php app/Providers/AuthServiceProvider.php tests/Feature/Onboarding/InvitationPolicyTest.php
git commit -m "Add InvitationPolicy (owner-only staff invites)"
```

---

### Task D4: Wizard controller — `show`

**Files:**
- Create: `app/Http/Controllers/Onboarding/OnboardingWizardController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Onboarding/OnboardingWizardShowTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Restaurant;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OnboardingWizardShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_the_wizard_with_progress_and_restaurant_data(): void
    {
        $restaurant = Restaurant::factory()->pendingOnboarding()->create(['name' => 'Bella']);
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)
            ->get(route('onboarding.wizard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Onboarding/Wizard')
                ->where('restaurant.name', 'Bella')
                ->where('progress.nextCoreStep', 'hours')
                ->where('progress.coreComplete', false)
                ->has('progress.steps')
            );
    }

    public function test_staff_cannot_open_the_wizard(): void
    {
        $restaurant = Restaurant::factory()->pendingOnboarding()->create();
        $staff = User::factory()->forRestaurant($restaurant)->create();

        $this->actingAs($staff)->get(route('onboarding.wizard'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OnboardingWizardShowTest`
Expected: FAIL — route/controller missing.

- [ ] **Step 3: Write the controller `show`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Enums\Tonality;
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
            'tables' => $restaurant->tables()->where('active', true)
                ->orderBy('sort_order')->get(['id', 'label', 'seats', 'room_tag']),
            'tonalities' => array_map(fn (Tonality $t) => $t->value, Tonality::cases()),
            'progress' => $this->progressPayload($restaurant),
        ]);
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

        Gate::authorize('manage', $restaurant); // owner-only (RestaurantPolicy::manage)

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
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, inside the `auth`/`verified` area, add:

```php
Route::get('onboarding', [OnboardingWizardController::class, 'show'])
    ->middleware(['auth', 'verified'])
    ->name('onboarding.wizard');
```

Add the controller import to the `use` block.

- [ ] **Step 5: Create a minimal `Onboarding/Wizard.vue` stub** (full version Task D8)

```vue
<script setup lang="ts">
defineProps<{ restaurant: Record<string, unknown>; progress: Record<string, unknown>; tables: unknown[]; tonalities: string[] }>();
</script>
<template>
    <div>{{ restaurant.name }}</div>
</template>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=OnboardingWizardShowTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Onboarding/OnboardingWizardController.php routes/web.php resources/js/pages/Onboarding/Wizard.vue tests/Feature/Onboarding/OnboardingWizardShowTest.php
git commit -m "Render onboarding wizard with derived progress"
```

---

### Task D5: Wizard store actions (restaurant info, hours, tables, tonality, team)

**Files:**
- Modify: `app/Http/Controllers/Onboarding/OnboardingWizardController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Onboarding/OnboardingWizardStoreTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\Tonality;
use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OnboardingWizardStoreTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        $restaurant = Restaurant::factory()->pendingOnboarding()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        return [$owner, $restaurant];
    }

    public function test_owner_updates_restaurant_info(): void
    {
        [$owner, $restaurant] = $this->owner();

        $this->actingAs($owner)
            ->patch(route('onboarding.restaurant.update'), [
                'name' => 'Neue Bella',
                'slug' => 'neue-bella',
                'timezone' => 'Europe/Vienna',
            ])
            ->assertRedirect(route('onboarding.wizard'));

        $restaurant->refresh();
        $this->assertSame('Neue Bella', $restaurant->name);
        $this->assertSame('neue-bella', $restaurant->slug);
        $this->assertSame('Europe/Vienna', $restaurant->timezone);
    }

    public function test_owner_saves_opening_hours(): void
    {
        [$owner, $restaurant] = $this->owner();

        $this->actingAs($owner)
            ->patch(route('onboarding.hours.update'), [
                'opening_hours' => ['mon' => [['from' => '18:00', 'to' => '22:00']], 'tue' => []],
            ])
            ->assertRedirect(route('onboarding.wizard'));

        $this->assertSame(
            ['mon' => [['from' => '18:00', 'to' => '22:00']], 'tue' => []],
            $restaurant->fresh()->opening_hours,
        );
    }

    public function test_adding_a_table_recomputes_capacity(): void
    {
        [$owner, $restaurant] = $this->owner();

        $this->actingAs($owner)
            ->post(route('onboarding.tables.store'), [
                'label' => 'Tisch 1',
                'seats' => 4,
            ])
            ->assertRedirect(route('onboarding.wizard'));

        $this->actingAs($owner)
            ->post(route('onboarding.tables.store'), ['label' => 'Tisch 2', 'seats' => 2]);

        $restaurant->refresh();
        $this->assertSame(2, $restaurant->tables()->count());
        $this->assertSame(6, $restaurant->capacity);
    }

    public function test_completing_the_core_marks_the_restaurant_live(): void
    {
        [$owner, $restaurant] = $this->owner();

        $this->actingAs($owner)->patch(route('onboarding.hours.update'), [
            'opening_hours' => ['mon' => [['from' => '18:00', 'to' => '22:00']]],
        ]);
        $this->assertNull($restaurant->fresh()->onboarding_completed_at);

        $this->actingAs($owner)->post(route('onboarding.tables.store'), ['label' => 'T1', 'seats' => 4]);

        $this->assertNotNull($restaurant->fresh()->onboarding_completed_at);
    }

    public function test_owner_sets_tonality(): void
    {
        [$owner, $restaurant] = $this->owner();

        $this->actingAs($owner)->patch(route('onboarding.tonality.update'), [
            'tonality' => Tonality::Casual->value,
        ])->assertRedirect(route('onboarding.wizard'));

        $this->assertSame(Tonality::Casual, $restaurant->fresh()->tonality);
    }

    public function test_owner_invites_staff_and_staff_cannot(): void
    {
        [$owner, $restaurant] = $this->owner();

        $this->actingAs($owner)->post(route('onboarding.team.store'), [
            'email' => 'server@bella.test',
        ])->assertRedirect(route('onboarding.wizard'));

        $this->assertDatabaseHas('invitations', [
            'restaurant_id' => $restaurant->id,
            'email' => 'server@bella.test',
            'role' => UserRole::Staff->value,
        ]);

        $staff = User::factory()->forRestaurant($restaurant)->create();
        $this->actingAs($staff)->post(route('onboarding.team.store'), [
            'email' => 'other@bella.test',
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OnboardingWizardStoreTest`
Expected: FAIL — store routes/methods missing.

- [ ] **Step 3: Add store methods to the controller**

Append to `OnboardingWizardController`:

```php
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
    // moment the restaurant goes live (provisioner seeds capacity = 0).
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
        'role' => \App\Enums\UserRole::Staff,
        'token' => Invitation::hashToken(Invitation::generateToken()),
        'expires_at' => now()->addDays(7),
    ]);

    return $this->backToWizard($restaurant);
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
```

> `TableRequest` enforces `seats` between 1 and 20 and is owner-gated only via route policy normally; here authorization is already covered by `ownedRestaurant()` (RestaurantPolicy::manage = owner). The `combinable_with` rule references the user's `restaurant_id`, which is set, so it works unchanged.

- [ ] **Step 4: Add routes**

In `routes/web.php`, under `auth`/`verified`:

```php
Route::patch('onboarding/restaurant', [OnboardingWizardController::class, 'updateRestaurant'])
    ->middleware(['auth', 'verified'])->name('onboarding.restaurant.update');
Route::patch('onboarding/hours', [OnboardingWizardController::class, 'updateHours'])
    ->middleware(['auth', 'verified'])->name('onboarding.hours.update');
Route::post('onboarding/tables', [OnboardingWizardController::class, 'storeTable'])
    ->middleware(['auth', 'verified'])->name('onboarding.tables.store');
Route::patch('onboarding/tonality', [OnboardingWizardController::class, 'updateTonality'])
    ->middleware(['auth', 'verified'])->name('onboarding.tonality.update');
Route::post('onboarding/team', [OnboardingWizardController::class, 'storeStaffInvite'])
    ->middleware(['auth', 'verified'])->name('onboarding.team.store');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=OnboardingWizardStoreTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Onboarding/OnboardingWizardController.php routes/web.php tests/Feature/Onboarding/OnboardingWizardStoreTest.php
git commit -m "Add onboarding wizard store actions and live transition"
```

---

### Task D6: `EnsureOnboardingComplete` gating middleware

**Files:**
- Create: `app/Http/Middleware/EnsureOnboardingComplete.php`
- Create: `resources/js/pages/Onboarding/Pending.vue`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php` (add `onboarding.pending` route)
- Test: `tests/Feature/Onboarding/OnboardingGatingTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OnboardingGatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_without_live_restaurant_is_redirected_to_wizard(): void
    {
        $restaurant = Restaurant::factory()->pendingOnboarding()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('onboarding.wizard'));
    }

    public function test_owner_on_wizard_route_is_not_redirected(): void
    {
        $restaurant = Restaurant::factory()->pendingOnboarding()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->get(route('onboarding.wizard'))->assertOk();
    }

    public function test_staff_of_a_pending_restaurant_sees_the_pending_placeholder(): void
    {
        $restaurant = Restaurant::factory()->pendingOnboarding()->create();
        $staff = User::factory()->forRestaurant($restaurant)->create();

        $this->actingAs($staff)->get(route('dashboard'))->assertRedirect(route('onboarding.pending'));
    }

    public function test_live_restaurant_users_pass_through(): void
    {
        $restaurant = Restaurant::factory()->onboarded()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    }

    public function test_guests_are_unaffected(): void
    {
        // Public reservation form must remain reachable without auth.
        $restaurant = Restaurant::factory()->onboarded()->create(['slug' => 'demo']);
        $this->get(route('public.reservations.create', $restaurant))->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OnboardingGatingTest`
Expected: FAIL — middleware not applied / pending route missing.

- [ ] **Step 3: Write the middleware**

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes authenticated members of a not-yet-live restaurant away from the
 * normal app: owners to the setup wizard, staff to a read-only placeholder.
 * Guests and members of live restaurants pass straight through.
 */
final class EnsureOnboardingComplete
{
    /**
     * Route names that must stay reachable while onboarding is incomplete.
     *
     * @var list<string>
     */
    private const EXEMPT = [
        'onboarding.wizard',
        'onboarding.restaurant.update',
        'onboarding.hours.update',
        'onboarding.tables.store',
        'onboarding.tonality.update',
        'onboarding.team.store',
        'onboarding.pending',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null || $user->restaurant_id === null) {
            return $next($request);
        }

        $restaurant = $user->restaurant;

        if ($restaurant === null || $restaurant->isLive()) {
            return $next($request);
        }

        if ($request->routeIs(self::EXEMPT)) {
            return $next($request);
        }

        $target = $user->role === UserRole::Owner
            ? route('onboarding.wizard')
            : route('onboarding.pending');

        return redirect()->to($target);
    }
}
```

- [ ] **Step 4: Register the middleware in the web group**

In `bootstrap/app.php`, append it to the web middleware list:

```php
$middleware->web(append: [
    HandleInertiaRequests::class,
    AddLinkHeadersForPreloadedAssets::class,
    WarnOnAppUrlMismatch::class,
    \App\Http\Middleware\EnsureOnboardingComplete::class,
]);
```

- [ ] **Step 5: Add the pending route + page**

In `routes/web.php` under `auth`:

```php
Route::get('onboarding/pending', fn () => Inertia::render('Onboarding/Pending'))
    ->middleware('auth')->name('onboarding.pending');
```

Ensure `use Inertia\Inertia;` is imported in `routes/web.php` (it already is per the existing settings route example; add if absent).

```vue
<!-- resources/js/pages/Onboarding/Pending.vue -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
</script>
<template>
    <OnboardingLayout title="Fast bereit">
        <Head title="Wird eingerichtet" />
        <p class="text-muted-foreground">
            Dieses Restaurant wird gerade von der Inhaberin/dem Inhaber eingerichtet. Sobald es bereit ist, können Sie hier loslegen.
        </p>
    </OnboardingLayout>
</template>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=OnboardingGatingTest`
Expected: PASS.

- [ ] **Step 7: Run the full suite to catch gating regressions**

Run: `php artisan test`
Expected: PASS — watch for existing dashboard/settings tests whose users now lack a live restaurant. Fix by switching their factory setup to `Restaurant::factory()->onboarded()` where the test asserts authenticated app access. List any such tests and update them (this is expected fallout of introducing the gate).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Middleware/EnsureOnboardingComplete.php bootstrap/app.php routes/web.php resources/js/pages/Onboarding/Pending.vue tests/Feature/Onboarding/OnboardingGatingTest.php
git commit -m "Gate not-yet-live restaurants to wizard or pending page"
```

---

### Task D7: Dashboard reminders for skipped optional steps

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Test: `tests/Feature/Onboarding/DashboardOnboardingRemindersTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Restaurant;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardOnboardingRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_exposes_pending_optional_steps(): void
    {
        // Live restaurant (passes the gate) but no staff invited yet.
        $restaurant = Restaurant::factory()->onboarded()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('onboardingReminders', ['team']));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DashboardOnboardingRemindersTest`
Expected: FAIL — prop missing.

- [ ] **Step 3: Add the prop in `DashboardController::index`**

Inject the reminder list into the existing `Inertia::render(...)` call. Add near the top of `index`:

```php
$user = $request->user();
$restaurant = $user?->restaurant;
$onboardingReminders = $restaurant !== null
    ? \App\Support\OnboardingProgress::for($restaurant)->pendingOptionalSteps()
    : [];
```

Add `'onboardingReminders' => $onboardingReminders,` to the props array of the `Inertia::render('Dashboard', [...])` call.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=DashboardOnboardingRemindersTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/DashboardController.php tests/Feature/Onboarding/DashboardOnboardingRemindersTest.php
git commit -m "Expose skipped onboarding steps as dashboard reminders"
```

---

### Task D8: Wizard Vue UI + Vitest step test

**Files:**
- Modify: `resources/js/pages/Onboarding/Wizard.vue`
- Create: `resources/js/pages/Onboarding/steps/StepRestaurantInfo.vue`
- Create: `resources/js/pages/Onboarding/steps/StepOpeningHours.vue`
- Create: `resources/js/pages/Onboarding/steps/StepTables.vue`
- Create: `resources/js/pages/Onboarding/steps/StepTonality.vue`
- Create: `resources/js/pages/Onboarding/steps/StepTeam.vue`
- Create: `resources/js/pages/Onboarding/steps/StepOpeningHours.test.ts`
- Modify: `resources/js/types/index.ts`

- [ ] **Step 1: Add types**

In `resources/js/types/index.ts`, extend the `Restaurant` interface with `onboarding_completed_at?: string | null;` and add:

```typescript
export interface OnboardingProgress {
    coreComplete: boolean;
    nextCoreStep: 'restaurant' | 'hours' | 'tables' | null;
    steps: Record<'restaurant' | 'hours' | 'tables' | 'tonality' | 'team', boolean>;
}
```

- [ ] **Step 2: Write `StepOpeningHours.vue`** (the step with non-trivial logic worth a Vitest test)

```vue
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Block = { from: string; to: string };
type Schedule = Record<string, Block[]>;

const props = defineProps<{ openingHours: Schedule }>();
const emit = defineEmits<{ saved: [] }>();

const DAYS: { key: string; label: string }[] = [
    { key: 'mon', label: 'Montag' },
    { key: 'tue', label: 'Dienstag' },
    { key: 'wed', label: 'Mittwoch' },
    { key: 'thu', label: 'Donnerstag' },
    { key: 'fri', label: 'Freitag' },
    { key: 'sat', label: 'Samstag' },
    { key: 'sun', label: 'Sonntag' },
];

const form = useForm<{ opening_hours: Schedule }>({
    opening_hours: DAYS.reduce((acc, d) => {
        acc[d.key] = props.openingHours[d.key] ?? [];
        return acc;
    }, {} as Schedule),
});

const addBlock = (day: string) => form.opening_hours[day].push({ from: '18:00', to: '22:00' });
const removeBlock = (day: string, i: number) => form.opening_hours[day].splice(i, 1);

const submit = () => form.patch(route('onboarding.hours.update'), {
    preserveScroll: true,
    onSuccess: () => emit('saved'),
});

defineExpose({ form, addBlock, removeBlock });
</script>

<template>
    <form class="grid gap-4" @submit.prevent="submit">
        <div v-for="day in DAYS" :key="day.key" class="grid gap-2 border-b border-border pb-3">
            <div class="flex items-center justify-between">
                <span class="font-medium">{{ day.label }}</span>
                <Button type="button" variant="ghost" size="sm" @click="addBlock(day.key)">+ Zeit</Button>
            </div>
            <div v-if="form.opening_hours[day.key].length === 0" class="text-sm text-muted-foreground">Ruhetag</div>
            <div v-for="(block, i) in form.opening_hours[day.key]" :key="i" class="flex items-center gap-2">
                <Input v-model="block.from" type="time" :data-testid="`${day.key}-from-${i}`" />
                <span>–</span>
                <Input v-model="block.to" type="time" :data-testid="`${day.key}-to-${i}`" />
                <Button type="button" variant="ghost" size="sm" @click="removeBlock(day.key, i)">Entfernen</Button>
            </div>
        </div>
        <InputError :message="form.errors.opening_hours" />
        <Button :disabled="form.processing" type="submit">Öffnungszeiten speichern</Button>
    </form>
</template>
```

- [ ] **Step 3: Write the Vitest test for the step**

```typescript
// resources/js/pages/Onboarding/steps/StepOpeningHours.test.ts
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

// Stub Inertia's useForm so the component can be mounted in isolation.
vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) => ({
        ...initial,
        errors: {},
        processing: false,
        patch: vi.fn(),
    }),
}));
// Ziggy route() global.
(globalThis as unknown as { route: () => string }).route = () => '/onboarding/hours';

import StepOpeningHours from './StepOpeningHours.vue';

const stubs = {
    Button: { template: '<button @click="$emit(\'click\')"><slot /></button>' },
    Input: { props: ['modelValue'], template: '<input :value="modelValue" />' },
    InputError: { props: ['message'], template: '<span>{{ message }}</span>' },
};

describe('StepOpeningHours', () => {
    it('renders Ruhetag for days without blocks and a time row when a block exists', () => {
        const wrapper = mount(StepOpeningHours, {
            props: { openingHours: { mon: [{ from: '18:00', to: '22:00' }] } },
            global: { stubs },
        });

        expect(wrapper.find('[data-testid="mon-from-0"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Ruhetag'); // tue–sun default to empty
    });

    it('adds and removes a block via the exposed helpers', async () => {
        const wrapper = mount(StepOpeningHours, {
            props: { openingHours: {} },
            global: { stubs },
        });

        (wrapper.vm as unknown as { addBlock: (d: string) => void }).addBlock('fri');
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="fri-from-0"]').exists()).toBe(true);

        (wrapper.vm as unknown as { removeBlock: (d: string, i: number) => void }).removeBlock('fri', 0);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="fri-from-0"]').exists()).toBe(false);
    });
});
```

- [ ] **Step 4: Write the remaining step components**

Write `StepRestaurantInfo.vue` (name/slug/timezone via `useForm` → `form.patch(route('onboarding.restaurant.update'))`), `StepTables.vue` (label + seats → `form.post(route('onboarding.tables.store'))`, lists `props.tables`), `StepTonality.vue` (radio over `props.tonalities` → `form.patch(route('onboarding.tonality.update'))`), `StepTeam.vue` (email → `form.post(route('onboarding.team.store'))`). Each follows the verified `Profile.vue`/`SendMode.vue` pattern: `useForm`, `<InputError :message="form.errors.*">`, disabled button on `form.processing`. Mark optional steps (Tonality, Team) with a "Überspringen" `Link` to `route('onboarding.wizard')`.

- [ ] **Step 5: Write `Wizard.vue`** (step switch + progress rail)

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import StepRestaurantInfo from './steps/StepRestaurantInfo.vue';
import StepOpeningHours from './steps/StepOpeningHours.vue';
import StepTables from './steps/StepTables.vue';
import StepTonality from './steps/StepTonality.vue';
import StepTeam from './steps/StepTeam.vue';
import type { OnboardingProgress } from '@/types';

interface RestaurantInfo {
    name: string;
    slug: string;
    timezone: string;
    tonality: string;
    opening_hours: Record<string, { from: string; to: string }[]>;
}

const props = defineProps<{
    restaurant: RestaurantInfo;
    tables: { id: number; label: string; seats: number; room_tag: string | null }[];
    tonalities: string[];
    progress: OnboardingProgress;
}>();

const ORDER = ['restaurant', 'hours', 'tables', 'tonality', 'team'] as const;
type StepKey = (typeof ORDER)[number];

// Start at the first incomplete core step, else the first optional gap, else summary.
const active = ref<StepKey>((props.progress.nextCoreStep ?? 'tonality') as StepKey);
const goto = (s: StepKey) => (active.value = s);
const onSaved = () => router.reload({ only: ['progress', 'restaurant', 'tables'] });
import { router } from '@inertiajs/vue3';
</script>

<template>
    <OnboardingLayout title="Restaurant einrichten">
        <Head title="Einrichtung" />
        <nav class="mb-6 flex flex-wrap gap-2">
            <button
                v-for="step in ORDER"
                :key="step"
                type="button"
                class="rounded-md px-3 py-1 text-sm"
                :class="active === step ? 'bg-primary text-primary-foreground' : 'bg-secondary text-secondary-foreground'"
                @click="goto(step)"
            >
                {{ step }} <span v-if="progress.steps[step]">✓</span>
            </button>
        </nav>

        <StepRestaurantInfo v-if="active === 'restaurant'" :restaurant="restaurant" @saved="onSaved" />
        <StepOpeningHours v-else-if="active === 'hours'" :opening-hours="restaurant.opening_hours" @saved="onSaved" />
        <StepTables v-else-if="active === 'tables'" :tables="tables" @saved="onSaved" />
        <StepTonality v-else-if="active === 'tonality'" :tonality="restaurant.tonality" :tonalities="tonalities" @saved="onSaved" />
        <StepTeam v-else @saved="onSaved" />
    </OnboardingLayout>
</template>
```

> Move the `import { router }` line to the top of `<script setup>` with the other imports (shown inline above only for locality).

- [ ] **Step 6: Run Vitest + build + lint**

Run: `npm run test && npm run build && npm run lint:check && npm run format:check`
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add resources/js/pages/Onboarding/ resources/js/types/index.ts
git commit -m "Build onboarding wizard UI with step components and Vitest"
```

---

# Epic E — Design tokens (direction C) + wizard styling

### Task E1: Status-colour & topbar tokens

**Files:**
- Modify: `resources/css/app.css`
- Modify: `tailwind.config.js`
- Test: none directly (consumed in E2/E3); verified by `npm run build`.

- [ ] **Step 1: Add CSS variables**

In `resources/css/app.css`, inside the `:root` block (and matching `.dark` block), add status tokens and a dark topbar surface (direction C). Append within `:root { … }`:

```css
        /* Direction C — reservation status tokens (HSL triplets). */
        --status-new: 217 91% 60%;
        --status-in-review: 243 75% 59%;
        --status-replied: 270 67% 60%;
        --status-confirmed: 152 60% 42%;
        --status-declined: 350 75% 55%;
        --status-cancelled: 240 5% 50%;
        --status-waitlisted: 38 92% 50%;

        /* Dark operator topbar. */
        --topbar: 222 47% 11%;
        --topbar-foreground: 0 0% 98%;
```

Append the same keys to the `.dark { … }` block (you may keep identical values; the topbar is dark in both themes by design).

- [ ] **Step 2: Map them in `tailwind.config.js`**

In `tailwind.config.js`, inside `theme.extend.colors`, add:

```javascript
                status: {
                    new: 'hsl(var(--status-new))',
                    'in-review': 'hsl(var(--status-in-review))',
                    replied: 'hsl(var(--status-replied))',
                    confirmed: 'hsl(var(--status-confirmed))',
                    declined: 'hsl(var(--status-declined))',
                    cancelled: 'hsl(var(--status-cancelled))',
                    waitlisted: 'hsl(var(--status-waitlisted))',
                },
                topbar: {
                    DEFAULT: 'hsl(var(--topbar))',
                    foreground: 'hsl(var(--topbar-foreground))',
                },
```

- [ ] **Step 3: Verify the build**

Run: `npm run build`
Expected: builds without errors; `bg-topbar`, `text-status-confirmed` etc. become available utility classes.

- [ ] **Step 4: Commit**

```bash
git add resources/css/app.css tailwind.config.js
git commit -m "Add direction-C status and topbar design tokens"
```

---

### Task E2: Central reservation-status module + Vitest

**Files:**
- Create: `resources/js/lib/reservationStatus.ts`
- Create: `resources/js/lib/reservationStatus.test.ts`

> This centralises the status→colour/label map so Phase 2 can replace the hardcoded `STATUS_BADGE_CLASS` map in `Dashboard.vue` (lines 97–105) with a single import. Phase 1 only creates and tests it.

- [ ] **Step 1: Write the failing test**

```typescript
// resources/js/lib/reservationStatus.test.ts
import { describe, expect, it } from 'vitest';
import { RESERVATION_STATUSES, statusBadgeClass, statusLabel } from './reservationStatus';

describe('reservationStatus', () => {
    it('covers all seven statuses including cancelled', () => {
        expect(RESERVATION_STATUSES).toEqual([
            'new', 'in_review', 'replied', 'confirmed', 'declined', 'cancelled', 'waitlisted',
        ]);
    });

    it('returns a token-based badge class and a German label for every status', () => {
        for (const status of RESERVATION_STATUSES) {
            expect(statusBadgeClass(status)).toContain('status-');
            expect(statusLabel(status).length).toBeGreaterThan(0);
        }
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- reservationStatus`
Expected: FAIL — module missing.

- [ ] **Step 3: Write the module**

```typescript
// resources/js/lib/reservationStatus.ts
export const RESERVATION_STATUSES = [
    'new',
    'in_review',
    'replied',
    'confirmed',
    'declined',
    'cancelled',
    'waitlisted',
] as const;

export type ReservationStatus = (typeof RESERVATION_STATUSES)[number];

const BADGE: Record<ReservationStatus, string> = {
    new: 'bg-status-new/15 text-status-new',
    in_review: 'bg-status-in-review/15 text-status-in-review',
    replied: 'bg-status-replied/15 text-status-replied',
    confirmed: 'bg-status-confirmed/15 text-status-confirmed',
    declined: 'bg-status-declined/15 text-status-declined',
    cancelled: 'bg-status-cancelled/15 text-status-cancelled',
    waitlisted: 'bg-status-waitlisted/15 text-status-waitlisted',
};

const LABEL: Record<ReservationStatus, string> = {
    new: 'Neu',
    in_review: 'In Prüfung',
    replied: 'Beantwortet',
    confirmed: 'Bestätigt',
    declined: 'Abgelehnt',
    cancelled: 'Storniert',
    waitlisted: 'Warteliste',
};

export const statusBadgeClass = (status: ReservationStatus): string => BADGE[status];
export const statusLabel = (status: ReservationStatus): string => LABEL[status];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test -- reservationStatus`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/lib/reservationStatus.ts resources/js/lib/reservationStatus.test.ts
git commit -m "Add central reservation-status colour and label module"
```

---

### Task E3: `OnboardingLayout` with dark topbar (C-look)

**Files:**
- Create: `resources/js/layouts/OnboardingLayout.vue`
- Modify: the Onboarding pages that referenced it (AcceptInvitation/InvitationError/Pending/Wizard) — already import it.
- Test: a light component test that it renders its slot + title.
- Create: `resources/js/layouts/OnboardingLayout.test.ts`

- [ ] **Step 1: Write the failing test**

```typescript
// resources/js/layouts/OnboardingLayout.test.ts
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import OnboardingLayout from './OnboardingLayout.vue';

describe('OnboardingLayout', () => {
    it('renders the title in the topbar and the default slot', () => {
        const wrapper = mount(OnboardingLayout, {
            props: { title: 'Restaurant einrichten' },
            slots: { default: '<p>Inhalt</p>' },
            global: { stubs: { Head: true } },
        });

        expect(wrapper.find('header').classes()).toContain('bg-topbar');
        expect(wrapper.text()).toContain('Restaurant einrichten');
        expect(wrapper.html()).toContain('Inhalt');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- OnboardingLayout`
Expected: FAIL — layout missing.

- [ ] **Step 3: Write the layout**

```vue
<script setup lang="ts">
defineProps<{ title: string }>();
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <header class="flex h-16 items-center bg-topbar px-6 text-topbar-foreground">
            <span class="text-lg font-semibold">{{ title }}</span>
        </header>
        <main class="mx-auto w-full max-w-2xl px-6 py-10">
            <slot />
        </main>
    </div>
</template>
```

- [ ] **Step 4: Swap any temporary `AppLayout` imports** in the Onboarding pages (from C5/D6/D8) to `OnboardingLayout` if they were stubbed.

- [ ] **Step 5: Run test + build**

Run: `npm run test -- OnboardingLayout && npm run build`
Expected: PASS + clean build.

- [ ] **Step 6: Commit**

```bash
git add resources/js/layouts/OnboardingLayout.vue resources/js/layouts/OnboardingLayout.test.ts resources/js/pages/Onboarding/
git commit -m "Add dark-topbar OnboardingLayout (direction C)"
```

---

# Final verification

- [ ] **Step 1: Full backend suite**

Run: `php artisan test`
Expected: PASS (zero failures). If introducing the gate broke pre-existing dashboard/settings tests, those were updated in Task D6 Step 7 — confirm none remain red.

- [ ] **Step 2: Frontend suite + build + format**

Run: `npm run test && npm run build && npm run lint:check && npm run format:check`
Expected: all green.

- [ ] **Step 3: Pint**

Run: `./vendor/bin/pint --test`
Expected: no findings.

- [ ] **Step 4: Ziggy regen (new routes added)**

Run: `php artisan ziggy:generate`
Then re-run `npm run build` and verify no type drift. Commit the regenerated Ziggy types if tracked.

```bash
git add resources/js/types/ziggy.ts
git commit -m "Regenerate Ziggy types for onboarding routes"
```

- [ ] **Step 5: Manual smoke (optional but recommended)**

```bash
php artisan migrate:fresh
php artisan restaurant:provision --name="Smoke Test" --slug=smoke --email=smoke@example.test
# open the printed link, set a password, walk the wizard, confirm dashboard goes live
```

---

## Acceptance-criteria coverage (PRD-016 §Akzeptanzkriterien)

| PRD criterion | Task(s) |
|---|---|
| `restaurant:provision` creates restaurant (NOT-NULL defaults) + owner (`role=owner`) + invitation, prints link; dup slug/email → error | A2, A3, B1, B2 |
| Invitation link valid → set password → logged in; expired/accepted → clear message, no access | C2, C3 |
| Owner without core → wizard (gating), not empty dashboard | D6 |
| Core complete → `onboarding_completed_at` set → dashboard live | D5, D6 |
| Optional steps skippable; skipped → dashboard reminder | D7, D8 |
| Team invite → staff invitation via same flow; cross-tenant impossible | D3, D5 |
| Migrations up/down; no existing migration changed | A1, A2, A3 |
| Design tokens central; all 7 status colours incl. `cancelled`; wizard in direction C; no regression | E1, E2, E3 |
| Pint / ESLint / Prettier / build clean; Ziggy regenerated | Final verification |

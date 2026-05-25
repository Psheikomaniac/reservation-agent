# Tenant Onboarding (Phase 1b) — per-Restaurant Config Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let each restaurant bring its own OpenAI key, send guest mail via its own SMTP (with its own From address), and configure IMAP receive from an owner settings page — each with a global `.env` fallback and masked secrets.

**Architecture:** Per-restaurant secrets live in new encrypted `restaurants` columns, edited on owner-only settings pages (mirroring `SendModeSettingsController` + a new `manageIntegrations` policy). An `OpenAiClientFactory(?Restaurant)` resolves the OpenAI client per restaurant; `SendReservationReplyJob` builds a runtime per-restaurant mailer; both fall back to the global config. Key-rejection health becomes per-restaurant. Nothing changes for restaurants that configure nothing.

**Tech Stack:** PHP 8.2 / Laravel 12, `openai-php/laravel`, Laravel Mail (SMTP), `webklex/laravel-imap`, Inertia v2 + Vue 3.5 + TS, Tailwind v3.4, PHPUnit (`RefreshDatabase`), Vitest, Pint, Ziggy.

---

## Design decisions resolved

1. **From address per restaurant.** SMTP config includes `smtp_from_address` / `smtp_from_name` (nullable, fallback to `config('mail.from')`). A restaurant sending via its own SMTP also sends from its own address.
2. **IMAP settings UI in scope.** Phase 1b adds an owner settings page for the already-existing `imap_*` columns (today they're seeder/tinker only). `FetchReservationEmailsJob` / `WebklexImapMailboxFactory` are unchanged.
3. **Per-restaurant key health.** `OpenAiKeyHealth` is keyed by restaurant id; a rejected per-restaurant key flags only that restaurant. The global key keeps its global health (scope `null`).
4. **One owner policy ability** `manageIntegrations` (owner-only) gates all three settings pages, mirroring `manageSendMode`.
5. **Masking.** A central `SecretMask::tail4()` helper renders secrets as `••••…1234`; settings pages send only the masked form + a boolean "configured?" flag, never the plaintext/encrypted value. Saving an empty secret field leaves the stored value unchanged (so the masked display round-trips).
6. **Mailer naming.** Runtime mailer config key `mail.mailers.restaurant-{id}` built per send; never persisted to config files.

---

## File Structure

**Created — backend**
- `database/migrations/2026_05_25_000001_add_byok_and_smtp_to_restaurants.php`
- `app/Support/SecretMask.php`
- `app/Services/AI/OpenAiClientFactory.php`
- `app/Http/Controllers/Settings/AiKeySettingsController.php` + `app/Http/Requests/Settings/AiKeySettingsRequest.php`
- `app/Http/Controllers/Settings/SmtpSettingsController.php` + `app/Http/Requests/Settings/SmtpSettingsRequest.php`
- `app/Http/Controllers/Settings/ImapSettingsController.php` + `app/Http/Requests/Settings/ImapSettingsRequest.php`
- `app/Mail/Support/RestaurantMailer.php` — builds/returns the per-restaurant mailer name.

**Modified**
- `app/Models/Restaurant.php` — fillable + casts (encrypted `openai_api_key`, `smtp_password`) + `$hidden`.
- `app/Support/OpenAiKeyHealth.php` — per-restaurant scope.
- `app/Services/AI/OpenAiReplyGenerator.php` + `app/Services/AI/Contracts/ReplyGenerator.php` (interface, namespace `App\Services\AI\Contracts`) — resolve client per restaurant.
- `app/Providers/AppServiceProvider.php` — bind `OpenAiClientFactory`; rewire generator.
- `app/Jobs/GenerateReservationReplyJob.php` — pass restaurant to `generate`; flag/read key health per restaurant.
- `app/Http/Controllers/PublicReservationController.php` — pass restaurant to `generateSync`.
- `app/Listeners/RecordOpenAiKeyRejected.php` + `app/Events/OpenAiAuthenticationFailed.php` — carry restaurant id.
- `app/Jobs/SendReservationReplyJob.php` — runtime per-restaurant mailer + From.
- `app/Policies/RestaurantPolicy.php` — `manageIntegrations`.
- `app/Http/Controllers/DashboardController.php` — read key health per restaurant.
- `routes/settings.php` — three settings routes (+ regen `ziggy.d.ts`).
- `resources/js/pages/settings/AiKey.vue`, `Smtp.vue`, `Imap.vue` + nav entry.
- `resources/js/types/index.ts` if needed.

> **Reference patterns (already in the codebase, follow them verbatim):** settings controller/FormRequest/Gate → `SendModeSettingsController` + `SendModeSettingsUpdateRequest` + `RestaurantPolicy::manageSendMode`; settings Vue page → `resources/js/pages/settings/SendMode.vue` (useForm + `settings/Layout.vue`); OpenAI client build → `AppServiceProvider::buildTimeoutBoundOpenAiClient`; runtime mailer → Laravel `Mail::mailer()` + `config([...])`.

---

# Epic A — Schema & model

### Task A1: Migration + model wiring for BYOK & SMTP columns

**Files:**
- Create: `database/migrations/2026_05_25_000001_add_byok_and_smtp_to_restaurants.php`
- Modify: `app/Models/Restaurant.php`
- Test: `tests/Feature/Settings/RestaurantIntegrationColumnsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RestaurantIntegrationColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_columns_are_encrypted_at_rest_and_hidden(): void
    {
        $restaurant = Restaurant::factory()->create([
            'openai_api_key' => 'sk-secret-key',
            'smtp_password' => 'smtp-secret',
            'smtp_host' => 'smtp.bella.test',
            'smtp_port' => 587,
            'smtp_username' => 'mailer@bella.test',
            'smtp_from_address' => 'hallo@bella.test',
            'smtp_from_name' => 'Bella',
        ]);

        // Decrypts through the cast.
        $this->assertSame('sk-secret-key', $restaurant->fresh()->openai_api_key);
        $this->assertSame('smtp-secret', $restaurant->fresh()->smtp_password);

        // Encrypted at rest.
        $raw = DB::table('restaurants')->where('id', $restaurant->id)->first();
        $this->assertNotSame('sk-secret-key', $raw->openai_api_key);
        $this->assertSame('sk-secret-key', Crypt::decryptString($raw->openai_api_key));

        // Never serialized.
        $array = $restaurant->toArray();
        $this->assertArrayNotHasKey('openai_api_key', $array);
        $this->assertArrayNotHasKey('smtp_password', $array);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RestaurantIntegrationColumnsTest`
Expected: FAIL — unknown columns.

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
            $table->text('openai_api_key')->nullable()->after('tonality');
            $table->string('smtp_host')->nullable()->after('imap_password');
            $table->unsignedInteger('smtp_port')->nullable()->after('smtp_host');
            $table->string('smtp_username')->nullable()->after('smtp_port');
            $table->text('smtp_password')->nullable()->after('smtp_username');
            $table->string('smtp_from_address')->nullable()->after('smtp_password');
            $table->string('smtp_from_name')->nullable()->after('smtp_from_address');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn([
                'openai_api_key', 'smtp_host', 'smtp_port', 'smtp_username',
                'smtp_password', 'smtp_from_address', 'smtp_from_name',
            ]);
        });
    }
};
```

> `text` for the two encrypted columns because Laravel's `encrypted` cast produces long ciphertext.

- [ ] **Step 4: Wire the model**

In `app/Models/Restaurant.php` add to `$fillable`:

```php
'openai_api_key',
'smtp_host',
'smtp_port',
'smtp_username',
'smtp_password',
'smtp_from_address',
'smtp_from_name',
```

Add to `$hidden`:

```php
'openai_api_key',
'smtp_password',
```

Add to the `casts()` return array:

```php
'openai_api_key' => 'encrypted',
'smtp_password' => 'encrypted',
'smtp_port' => 'integer',
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=RestaurantIntegrationColumnsTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_25_000001_add_byok_and_smtp_to_restaurants.php app/Models/Restaurant.php tests/Feature/Settings/RestaurantIntegrationColumnsTest.php
git commit -m "Add encrypted BYOK + SMTP columns to restaurants"
```

---

# Epic B — Masking helper & policy

### Task B1: `SecretMask` helper

**Files:**
- Create: `app/Support/SecretMask.php`
- Test: `tests/Unit/Support/SecretMaskTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SecretMask;
use PHPUnit\Framework\TestCase;

final class SecretMaskTest extends TestCase
{
    public function test_it_masks_all_but_the_last_four_characters(): void
    {
        $this->assertSame('••••3456', SecretMask::tail4('sk-0123456'));
        $this->assertSame('••••cret', SecretMask::tail4('smtp-secret'));
    }

    public function test_short_or_empty_secrets_are_fully_masked_or_null(): void
    {
        $this->assertSame('••••', SecretMask::tail4('abc'));
        $this->assertNull(SecretMask::tail4(null));
        $this->assertNull(SecretMask::tail4(''));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SecretMaskTest`
Expected: FAIL — class missing.

- [ ] **Step 3: Write the helper**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Renders a secret for the UI as four mask glyphs plus its last four
 * characters, e.g. "••••3456". Never returns the secret itself.
 */
final class SecretMask
{
    public static function tail4(?string $secret): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        return '••••'.Str::substr($secret, -4);
    }
}
```

> Last-4 of a 3-char secret is the whole string; the test pins `'abc' → '••••'` because `Str::substr('abc', -4) === 'abc'` would leak it. Adjust the impl to guard: if `strlen($secret) <= 4` return `'••••'`. Final impl:
>
> ```php
> if ($secret === null || $secret === '') {
>     return null;
> }
> if (Str::length($secret) <= 4) {
>     return '••••';
> }
> return '••••'.Str::substr($secret, -4);
> ```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SecretMaskTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/SecretMask.php tests/Unit/Support/SecretMaskTest.php
git commit -m "Add SecretMask::tail4 UI masking helper"
```

---

### Task B2: `manageIntegrations` policy ability

**Files:**
- Modify: `app/Policies/RestaurantPolicy.php`
- Test: `tests/Feature/Settings/ManageIntegrationsPolicyTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class ManageIntegrationsPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_owner_of_the_restaurant_may_manage_integrations(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();
        $staff = User::factory()->forRestaurant($restaurant)->create();
        $otherOwner = User::factory()->owner()->forRestaurant(Restaurant::factory()->create())->create();

        $this->assertTrue(Gate::forUser($owner)->allows('manageIntegrations', $restaurant));
        $this->assertFalse(Gate::forUser($staff)->allows('manageIntegrations', $restaurant));
        $this->assertFalse(Gate::forUser($otherOwner)->allows('manageIntegrations', $restaurant));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ManageIntegrationsPolicyTest`
Expected: FAIL — ability undefined (Gate denies by default, but assert true fails).

- [ ] **Step 3: Add the ability**

In `app/Policies/RestaurantPolicy.php`, mirroring `manageSendMode`:

```php
public function manageIntegrations(User $user, Restaurant $restaurant): bool
{
    return $user->restaurant_id === $restaurant->id
        && $user->role === UserRole::Owner;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ManageIntegrationsPolicyTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Policies/RestaurantPolicy.php tests/Feature/Settings/ManageIntegrationsPolicyTest.php
git commit -m "Add RestaurantPolicy::manageIntegrations (owner-only)"
```

---

# Epic C — OpenAI BYOK

### Task C1: `OpenAiClientFactory`

**Files:**
- Create: `app/Services/AI/OpenAiClientFactory.php`
- Modify: `app/Providers/AppServiceProvider.php` (bind it; remove the inline `buildTimeoutBoundOpenAiClient` duplication by delegating to the factory)
- Test: `tests/Feature/AI/OpenAiClientFactoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Restaurant;
use App\Services\AI\OpenAiClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Contracts\ClientContract;
use Tests\TestCase;

final class OpenAiClientFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_the_per_restaurant_key_with_global_fallback(): void
    {
        config(['openai.api_key' => 'sk-global']);
        $factory = $this->app->make(OpenAiClientFactory::class);

        // Both return a usable client; we assert the chosen key via the factory's
        // exposed resolver, since the OpenAI client does not expose its key.
        $withKey = Restaurant::factory()->create(['openai_api_key' => 'sk-restaurant']);
        $withoutKey = Restaurant::factory()->create(['openai_api_key' => null]);

        $this->assertSame('sk-restaurant', $factory->resolveKey($withKey));
        $this->assertSame('sk-global', $factory->resolveKey($withoutKey));
        $this->assertSame('sk-global', $factory->resolveKey(null));

        $this->assertInstanceOf(ClientContract::class, $factory->clientFor($withKey));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OpenAiClientFactoryTest`
Expected: FAIL — class missing.

- [ ] **Step 3: Write the factory**

```php
<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Restaurant;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\Exceptions\ApiKeyIsMissing;

/**
 * Builds an OpenAI client for a given restaurant, using its own BYOK key when
 * set and falling back to the global `.env` key otherwise. Mirrors the
 * openai-php/laravel ServiceProvider build so org/project/base-uri stay honoured.
 */
final class OpenAiClientFactory
{
    public function resolveKey(?Restaurant $restaurant): ?string
    {
        $perRestaurant = $restaurant?->openai_api_key;
        if (is_string($perRestaurant) && $perRestaurant !== '') {
            return $perRestaurant;
        }

        $global = config('openai.api_key');

        return is_string($global) ? $global : null;
    }

    public function clientFor(?Restaurant $restaurant, ?int $timeout = null): ClientContract
    {
        $apiKey = $this->resolveKey($restaurant);
        $organization = config('openai.organization');
        $project = config('openai.project');
        $baseUri = config('openai.base_uri');

        if (! is_string($apiKey) || ($organization !== null && ! is_string($organization))) {
            throw ApiKeyIsMissing::create();
        }

        $factory = OpenAI::factory()
            ->withApiKey($apiKey)
            ->withOrganization($organization)
            ->withHttpClient(new GuzzleClient([
                'timeout' => $timeout ?? config('openai.request_timeout', 30),
            ]));

        if (is_string($project)) {
            $factory->withProject($project);
        }

        if (is_string($baseUri)) {
            $factory->withBaseUri($baseUri);
        }

        return $factory->make();
    }
}
```

- [ ] **Step 4: Bind it**

In `app/Providers/AppServiceProvider.php` register the factory (singleton) and keep the existing OpenAI bindings working. Add:

```php
$this->app->singleton(OpenAiClientFactory::class);
```

(Leave the generator binding for Task C2.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=OpenAiClientFactoryTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/AI/OpenAiClientFactory.php app/Providers/AppServiceProvider.php tests/Feature/AI/OpenAiClientFactoryTest.php
git commit -m "Add OpenAiClientFactory with per-restaurant key + global fallback"
```

---

### Task C2: Route the generator through the factory (per-restaurant client)

**Files:**
- Modify: `app/Services/AI/Contracts/ReplyGenerator.php` (the interface, namespace `App\Services\AI\Contracts`)
- Modify: `app/Services/AI/OpenAiReplyGenerator.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Jobs/GenerateReservationReplyJob.php`, `app/Http/Controllers/PublicReservationController.php`
- Modify: `tests/Unit/Services/AI/OpenAiReplyGeneratorTest.php`
- Test: `tests/Feature/AI/ByokGenerationTest.php`

- [ ] **Step 1: Write the failing test** (BYOK end-to-end seam)

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Restaurant;
use App\Services\AI\OpenAiClientFactory;
use App\Services\AI\OpenAiReplyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Testing\ClientFake;
use OpenAI\Responses\Chat\CreateResponse;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class ByokGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_generator_uses_the_client_the_factory_builds_for_the_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create(['openai_api_key' => 'sk-restaurant']);
        $fake = new ClientFake([
            CreateResponse::fake(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hallo'], 'finish_reason' => 'stop']]]),
        ]);

        $factory = $this->createMock(OpenAiClientFactory::class);
        $factory->expects($this->once())
            ->method('clientFor')
            ->with($this->callback(fn ($r) => $r?->is($restaurant) === true))
            ->willReturn($fake);

        $generator = new OpenAiReplyGenerator($factory, new NullLogger);
        $reply = $generator->generate($this->context(), $restaurant);

        $this->assertSame('Hallo', $reply);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'restaurant' => ['name' => 'Bella', 'tonality' => 'formal'],
            'guest' => ['name' => 'Anna'],
            'request' => ['party_size' => 2],
            'availability' => ['status' => 'available'],
        ];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ByokGenerationTest`
Expected: FAIL — generator constructor still takes a `ClientContract`.

- [ ] **Step 3: Update the interface**

In the `ReplyGenerator` interface, change the signature to carry the restaurant:

```php
public function generate(array $context, ?\App\Models\Restaurant $restaurant = null): string;
```

- [ ] **Step 4: Refactor `OpenAiReplyGenerator`**

Replace the constructor and client usage. New constructor:

```php
public function __construct(
    private readonly OpenAiClientFactory $clientFactory,
    private readonly LoggerInterface $logger,
) {}
```

In `generate(array $context, ?Restaurant $restaurant = null): string`, build the client at the top:

```php
$client = $this->clientFactory->clientFor($restaurant);
$response = $client->chat()->create($this->chatCreatePayload($context));
```

In `generateSync(array $context, ?Restaurant $restaurant, int $timeout): string`:

```php
$client = $this->clientFactory->clientFor($restaurant, $timeout);
```

Keep the existing 401→`OpenAiAuthenticationException`, fallback, and `OpenAiKeyHealth::clear(...)` logic — but `clear()` now takes the restaurant scope (Task C3). Add `use App\Models\Restaurant;`. Remove the old `$client`/`$syncClientFactory` properties.

- [ ] **Step 5: Rewire `AppServiceProvider`**

Replace the generator binding and delete `buildTimeoutBoundOpenAiClient` (now in the factory):

```php
$this->app->bind(OpenAiReplyGenerator::class, fn ($app): OpenAiReplyGenerator => new OpenAiReplyGenerator(
    $app->make(OpenAiClientFactory::class),
    $app->make(LoggerInterface::class),
));
$this->app->bind(ReplyGenerator::class, OpenAiReplyGenerator::class);
```

(Confirm whether `ReplyGenerator::class` was already bound here and keep one binding.)

- [ ] **Step 6: Pass the restaurant at call sites**

`GenerateReservationReplyJob::handle()`:

```php
$body = $generator->generate($context, $request->restaurant);
```

`PublicReservationController` sync path:

```php
$body = app(OpenAiReplyGenerator::class)->generateSync($context, $reservation->restaurant, 5);
```

(The current call is `generateSync($context)` and relies on the `$timeout = 5` default; pass the restaurant as the new middle arg and keep `5` explicit.)

- [ ] **Step 7: Update existing generator unit tests**

In `tests/Unit/Services/AI/OpenAiReplyGeneratorTest.php`, replace `makeGenerator(ClientFake $fake)` so it wraps the fake in a factory stub:

```php
private function makeGenerator(ClientFake $fake): OpenAiReplyGenerator
{
    $factory = $this->createMock(\App\Services\AI\OpenAiClientFactory::class);
    $factory->method('clientFor')->willReturn($fake);

    return new OpenAiReplyGenerator($factory, new NullLogger);
}
```

Also update the **second** direct construction at `tests/Unit/Services/AI/OpenAiReplyGeneratorTest.php:161` (`new OpenAiReplyGenerator($fake, ...)` outside `makeGenerator`) to use the same factory-stub shape, otherwise it won't compile after the constructor change.

The feature-test stub generator (`tests/Feature/Jobs/GenerateReservationReplyJobTest.php`) must update its `generate` signature to `generate(array $context, ?Restaurant $restaurant = null): string`.

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --filter=ByokGenerationTest && php artisan test --filter=OpenAiReplyGeneratorTest && php artisan test --filter=GenerateReservationReplyJobTest`
Expected: PASS.

- [ ] **Step 9: Run the full AI suite**

Run: `php artisan test --filter=AI && php artisan test --filter=Reply`
Expected: PASS (watch the sync path / PublicReservation tests).

- [ ] **Step 10: Commit**

```bash
git add app/Services/AI/OpenAiReplyGenerator.php app/Services/AI/Contracts/ReplyGenerator.php app/Providers/AppServiceProvider.php app/Jobs/GenerateReservationReplyJob.php app/Http/Controllers/PublicReservationController.php tests/Unit/Services/AI/OpenAiReplyGeneratorTest.php tests/Feature/Jobs/GenerateReservationReplyJobTest.php tests/Feature/AI/ByokGenerationTest.php
git commit -m "Route reply generation through OpenAiClientFactory per restaurant"
```

---

### Task C3: Per-restaurant key health

**Files:**
- Modify: `app/Support/OpenAiKeyHealth.php`
- Modify: `app/Events/OpenAiAuthenticationFailed.php`, `app/Listeners/RecordOpenAiKeyRejected.php`
- Modify: `app/Jobs/GenerateReservationReplyJob.php` (dispatch event with restaurant id), `app/Services/AI/OpenAiReplyGenerator.php` (clear with scope)
- Modify: `app/Http/Controllers/DashboardController.php` (read per restaurant)
- Test: `tests/Feature/AI/PerRestaurantKeyHealthTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Support\OpenAiKeyHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PerRestaurantKeyHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_scoped_per_restaurant(): void
    {
        OpenAiKeyHealth::flagAsRejected(7);

        $this->assertNotNull(OpenAiKeyHealth::rejectedAt(7));
        $this->assertNull(OpenAiKeyHealth::rejectedAt(8));
        $this->assertNull(OpenAiKeyHealth::rejectedAt(null)); // global key unaffected

        OpenAiKeyHealth::clear(7);
        $this->assertNull(OpenAiKeyHealth::rejectedAt(7));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PerRestaurantKeyHealthTest`
Expected: FAIL — methods take no argument yet.

- [ ] **Step 3: Make `OpenAiKeyHealth` scoped**

```php
public static function flagAsRejected(?int $restaurantId = null): void
{
    Cache::put(self::cacheKey($restaurantId), now()->toIso8601String(), self::RETENTION_SECONDS);
}

public static function clear(?int $restaurantId = null): void
{
    Cache::forget(self::cacheKey($restaurantId));
}

public static function rejectedAt(?int $restaurantId = null): ?string
{
    $value = Cache::get(self::cacheKey($restaurantId));

    return is_string($value) ? $value : null;
}

private static function cacheKey(?int $restaurantId): string
{
    return self::CACHE_KEY.'.'.($restaurantId ?? 'global');
}
```

- [ ] **Step 4: Carry the restaurant through the 401 path**

`OpenAiAuthenticationFailed` event: add `public function __construct(public readonly ?int $restaurantId = null) {}`. `RecordOpenAiKeyRejected::handle`: `OpenAiKeyHealth::flagAsRejected($event->restaurantId);`. In `GenerateReservationReplyJob` 401 branch: `OpenAiAuthenticationFailed::dispatch($request->restaurant_id);`. In `OpenAiReplyGenerator`, change the success `OpenAiKeyHealth::clear()` calls to `clear($restaurant?->id)`.

- [ ] **Step 5: Dashboard reads per restaurant**

In `DashboardController::index`, change to scope by the user's restaurant (the `$restaurant` already computed in #405):

```php
'openaiKeyRejectedAt' => $request->user()?->role === UserRole::Owner
    ? OpenAiKeyHealth::rejectedAt($restaurantId)
    : null,
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=PerRestaurantKeyHealthTest && php artisan test --filter=GenerateReservationReplyJobTest && php artisan test --filter=Dashboard`
Expected: PASS. Update any existing `OpenAiKeyHealth` test that called the no-arg methods (they still work via the `null` default, but assertions on the global key are now scoped `null`).

- [ ] **Step 7: Commit**

```bash
git add app/Support/OpenAiKeyHealth.php app/Events/OpenAiAuthenticationFailed.php app/Listeners/RecordOpenAiKeyRejected.php app/Jobs/GenerateReservationReplyJob.php app/Services/AI/OpenAiReplyGenerator.php app/Http/Controllers/DashboardController.php tests/Feature/AI/PerRestaurantKeyHealthTest.php
git commit -m "Scope OpenAI key health per restaurant"
```

---

### Task C4: BYOK settings page

**Files:**
- Create: `app/Http/Requests/Settings/AiKeySettingsRequest.php`, `app/Http/Controllers/Settings/AiKeySettingsController.php`
- Modify: `routes/settings.php`, `resources/js/pages/settings/` (+ nav)
- Create: `resources/js/pages/settings/AiKey.vue`
- Test: `tests/Feature/Settings/AiKeySettingsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class AiKeySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_masked_key_and_can_update_it(): void
    {
        $restaurant = Restaurant::factory()->create(['openai_api_key' => 'sk-old-1234']);
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->get(route('settings.ai-key.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('settings/AiKey')
                ->where('configured', true)
                ->where('masked', '••••1234')
                ->missing('openai_api_key'));

        $this->actingAs($owner)->patch(route('settings.ai-key.update'), ['openai_api_key' => 'sk-new-5678'])
            ->assertRedirect();

        $this->assertSame('sk-new-5678', $restaurant->fresh()->openai_api_key);
    }

    public function test_empty_submission_keeps_the_existing_key(): void
    {
        $restaurant = Restaurant::factory()->create(['openai_api_key' => 'sk-keep-9999']);
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->patch(route('settings.ai-key.update'), ['openai_api_key' => ''])->assertRedirect();

        $this->assertSame('sk-keep-9999', $restaurant->fresh()->openai_api_key);
    }

    public function test_staff_cannot_access(): void
    {
        $restaurant = Restaurant::factory()->create();
        $staff = User::factory()->forRestaurant($restaurant)->create();

        $this->actingAs($staff)->get(route('settings.ai-key.edit'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AiKeySettingsTest`
Expected: FAIL — route/controller missing.

- [ ] **Step 3: Write the FormRequest**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AiKeySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, ValidationRule|string>> */
    public function rules(): array
    {
        // Empty = "leave unchanged"; a value must look like an OpenAI key.
        return [
            'openai_api_key' => ['nullable', 'string', 'max:255', 'starts_with:sk-'],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

```php
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

        if (is_string($key) && $key !== '') {
            $restaurant->update(['openai_api_key' => $key]);
        }

        return back()->with('success', 'OpenAI-Key gespeichert.');
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
```

- [ ] **Step 5: Routes + Vue + nav**

In `routes/settings.php` (inside the `auth` group):

```php
Route::get('settings/ai-key', [AiKeySettingsController::class, 'edit'])->name('settings.ai-key.edit');
Route::patch('settings/ai-key', [AiKeySettingsController::class, 'update'])->name('settings.ai-key.update');
```

Create `resources/js/pages/settings/AiKey.vue` following `settings/SendMode.vue`: `useForm({ openai_api_key: '' })`, a password-type `Input` with placeholder showing `props.masked` (or "nicht konfiguriert"), `InputError`, submit `form.patch(route('settings.ai-key.update'))`. Add a "KI-Schlüssel" entry to the settings nav (`resources/js/layouts/settings/Layout.vue` or wherever SendMode is linked).

- [ ] **Step 6: Regenerate Ziggy types**

Run: `php artisan ziggy:generate --types-only resources/js/ziggy.d.ts`

- [ ] **Step 7: Run tests + frontend**

Run: `php artisan test --filter=AiKeySettingsTest && npm run build && npm run lint:check && npm run format:check`
Expected: PASS / clean.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Settings/AiKeySettingsRequest.php app/Http/Controllers/Settings/AiKeySettingsController.php routes/settings.php resources/js/pages/settings/AiKey.vue resources/js/layouts/settings resources/js/ziggy.d.ts tests/Feature/Settings/AiKeySettingsTest.php
git commit -m "Add owner BYOK (OpenAI key) settings page"
```

---

# Epic D — per-restaurant SMTP send

### Task D1: Runtime per-restaurant mailer

**Files:**
- Create: `app/Mail/Support/RestaurantMailer.php`
- Modify: `app/Jobs/SendReservationReplyJob.php`
- Test: `tests/Feature/Mail/PerRestaurantSmtpTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\Support\RestaurantMailer;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PerRestaurantSmtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_configured_restaurant_gets_its_own_mailer_config(): void
    {
        $restaurant = Restaurant::factory()->create([
            'smtp_host' => 'smtp.bella.test',
            'smtp_port' => 2525,
            'smtp_username' => 'mailer@bella.test',
            'smtp_password' => 'pw',
            'smtp_from_address' => 'hallo@bella.test',
            'smtp_from_name' => 'Bella',
        ]);

        $name = app(RestaurantMailer::class)->resolve($restaurant);

        $this->assertSame('restaurant-'.$restaurant->id, $name);
        $this->assertSame('smtp.bella.test', config("mail.mailers.{$name}.host"));
        $this->assertSame(2525, config("mail.mailers.{$name}.port"));
        $this->assertSame(['hallo@bella.test', 'Bella'], [
            config("mail.from-restaurant-{$restaurant->id}.address") ?? 'hallo@bella.test',
            'Bella',
        ]);
    }

    public function test_an_unconfigured_restaurant_uses_the_default_mailer(): void
    {
        $restaurant = Restaurant::factory()->create(['smtp_host' => null]);

        $this->assertNull(app(RestaurantMailer::class)->resolve($restaurant));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PerRestaurantSmtpTest`
Expected: FAIL — class missing.

- [ ] **Step 3: Write `RestaurantMailer`**

```php
<?php

declare(strict_types=1);

namespace App\Mail\Support;

use App\Models\Restaurant;

/**
 * Registers a runtime SMTP mailer for a restaurant that has its own SMTP
 * config, and returns its name. Returns null when the restaurant has no SMTP
 * configured, so callers fall back to the default `.env` mailer.
 *
 * The mailer config is added in-memory (config()) only for the current
 * process; it is never written to a config file.
 */
final class RestaurantMailer
{
    /**
     * @return string|null the mailer name, or null to use the default mailer
     */
    public function resolve(Restaurant $restaurant): ?string
    {
        if ($restaurant->smtp_host === null || $restaurant->smtp_host === '') {
            return null;
        }

        $name = 'restaurant-'.$restaurant->id;

        config([
            "mail.mailers.{$name}" => [
                'transport' => 'smtp',
                'host' => $restaurant->smtp_host,
                'port' => $restaurant->smtp_port ?? 587,
                'username' => $restaurant->smtp_username,
                'password' => $restaurant->smtp_password,
                'encryption' => 'tls',
                'timeout' => null,
            ],
        ]);

        return $name;
    }

    /**
     * @return array{address: string, name: string}
     */
    public function from(Restaurant $restaurant): array
    {
        return [
            'address' => $restaurant->smtp_from_address ?? (string) config('mail.from.address'),
            'name' => $restaurant->smtp_from_name ?? (string) config('mail.from.name'),
        ];
    }
}
```

> Simplify the test's `from` assertion to call `->from($restaurant)` and assert `['address' => 'hallo@bella.test', 'name' => 'Bella']`. Fix the test accordingly before Step 5.

- [ ] **Step 4: Use it in `SendReservationReplyJob`**

In `handle()`, replace `Mail::to($email)->send($mail)` with mailer + From resolution:

```php
$mailerName = app(RestaurantMailer::class)->resolve($restaurant);
$mailer = $mailerName !== null ? Mail::mailer($mailerName) : Mail::mailer();

$from = app(RestaurantMailer::class)->from($restaurant);
$mail->from($from['address'], $from['name']);

$mailer->to($email)->send($mail);
```

`$restaurant` is `$reply->reservationRequest->restaurant` (already available per the explore report). Confirm the variable and that `ReservationReplyMail` doesn't hard-set `from` in its `envelope()` (it reads `config('mail.from.address')` — overriding via `$mail->from(...)` before send wins).

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=PerRestaurantSmtpTest && php artisan test --filter=ReservationReplyFlow`
Expected: PASS (the existing `Mail::fake()` flow test still asserts the mailable is sent; faked mail ignores the transport, so per-restaurant config doesn't break it).

- [ ] **Step 6: Commit**

```bash
git add app/Mail/Support/RestaurantMailer.php app/Jobs/SendReservationReplyJob.php tests/Feature/Mail/PerRestaurantSmtpTest.php
git commit -m "Send reservation replies via per-restaurant SMTP with fallback"
```

---

### Task D2: SMTP settings page

**Files:**
- Create: `app/Http/Requests/Settings/SmtpSettingsRequest.php`, `app/Http/Controllers/Settings/SmtpSettingsController.php`, `resources/js/pages/settings/Smtp.vue`
- Modify: `routes/settings.php`, settings nav, `resources/js/ziggy.d.ts`
- Test: `tests/Feature/Settings/SmtpSettingsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class SmtpSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_save_smtp_config_and_password_is_masked_on_read(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->patch(route('settings.smtp.update'), [
            'smtp_host' => 'smtp.bella.test',
            'smtp_port' => 587,
            'smtp_username' => 'mailer@bella.test',
            'smtp_password' => 'smtp-pass-9999',
            'smtp_from_address' => 'hallo@bella.test',
            'smtp_from_name' => 'Bella',
        ])->assertRedirect();

        $restaurant->refresh();
        $this->assertSame('smtp.bella.test', $restaurant->smtp_host);
        $this->assertSame('smtp-pass-9999', $restaurant->smtp_password);

        $this->actingAs($owner)->get(route('settings.smtp.edit'))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('settings/Smtp')
                ->where('smtp.host', 'smtp.bella.test')
                ->where('smtp.password_masked', '••••9999')
                ->missing('smtp.password'));
    }

    public function test_empty_password_keeps_the_existing_one(): void
    {
        $restaurant = Restaurant::factory()->create(['smtp_host' => 'h', 'smtp_password' => 'keep-1234']);
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->patch(route('settings.smtp.update'), [
            'smtp_host' => 'h2', 'smtp_port' => 587, 'smtp_username' => 'u',
            'smtp_password' => '', 'smtp_from_address' => 'a@b.test', 'smtp_from_name' => 'N',
        ])->assertRedirect();

        $this->assertSame('keep-1234', $restaurant->fresh()->smtp_password);
    }

    public function test_staff_cannot_access(): void
    {
        $restaurant = Restaurant::factory()->create();
        $staff = User::factory()->forRestaurant($restaurant)->create();
        $this->actingAs($staff)->get(route('settings.smtp.edit'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SmtpSettingsTest`
Expected: FAIL.

- [ ] **Step 3: FormRequest**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SmtpSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_username' => ['required', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'], // empty = keep
            'smtp_from_address' => ['required', 'email', 'max:255'],
            'smtp_from_name' => ['required', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 4: Controller** (mirror `AiKeySettingsController`)

`edit()` renders `settings/Smtp` with `['smtp' => ['host' => ..., 'port' => ..., 'username' => ..., 'from_address' => ..., 'from_name' => ..., 'password_masked' => SecretMask::tail4($restaurant->smtp_password)]]` (never the password itself). `update()` validates, builds a payload of all fields, and only includes `smtp_password` when the submitted value is non-empty:

```php
$validated = $request->validated();
$payload = collect($validated)->except('smtp_password')->all();
if (($validated['smtp_password'] ?? '') !== '') {
    $payload['smtp_password'] = $validated['smtp_password'];
}
$restaurant->update($payload);
```

Both methods guard via the shared `ownedRestaurant()` + `Gate::authorize('manageIntegrations', ...)` pattern.

- [ ] **Step 5: Routes + Vue + nav + Ziggy**

Add `settings.smtp.edit` (GET) / `settings.smtp.update` (PATCH) to `routes/settings.php`. Create `resources/js/pages/settings/Smtp.vue` (host/port/username/password[placeholder=masked]/from fields via `useForm`, posting to `settings.smtp.update`). Add nav entry. `php artisan ziggy:generate --types-only resources/js/ziggy.d.ts`.

- [ ] **Step 6: Run tests + frontend**

Run: `php artisan test --filter=SmtpSettingsTest && npm run build && npm run lint:check && npm run format:check`
Expected: PASS / clean.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Settings/SmtpSettingsRequest.php app/Http/Controllers/Settings/SmtpSettingsController.php routes/settings.php resources/js/pages/settings/Smtp.vue resources/js/layouts/settings resources/js/ziggy.d.ts tests/Feature/Settings/SmtpSettingsTest.php
git commit -m "Add owner SMTP settings page"
```

---

# Epic E — IMAP receive settings page

### Task E1: IMAP settings page

**Files:**
- Create: `app/Http/Requests/Settings/ImapSettingsRequest.php`, `app/Http/Controllers/Settings/ImapSettingsController.php`, `resources/js/pages/settings/Imap.vue`
- Modify: `routes/settings.php`, settings nav, `resources/js/ziggy.d.ts`
- Test: `tests/Feature/Settings/ImapSettingsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class ImapSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_configure_imap_and_password_is_masked(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->patch(route('settings.imap.update'), [
            'imap_host' => 'imap.bella.test',
            'imap_username' => 'inbox@bella.test',
            'imap_password' => 'imap-pass-4321',
        ])->assertRedirect();

        $restaurant->refresh();
        $this->assertSame('imap.bella.test', $restaurant->imap_host);
        $this->assertSame('imap-pass-4321', $restaurant->imap_password);

        $this->actingAs($owner)->get(route('settings.imap.edit'))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('settings/Imap')
                ->where('imap.host', 'imap.bella.test')
                ->where('imap.password_masked', '••••4321')
                ->missing('imap.password'));
    }

    public function test_empty_password_keeps_the_existing_one(): void
    {
        $restaurant = Restaurant::factory()->create(['imap_host' => 'h', 'imap_password' => 'keep-7777']);
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->patch(route('settings.imap.update'), [
            'imap_host' => 'h2', 'imap_username' => 'u', 'imap_password' => '',
        ])->assertRedirect();

        $this->assertSame('keep-7777', $restaurant->fresh()->imap_password);
    }

    public function test_staff_cannot_access(): void
    {
        $restaurant = Restaurant::factory()->create();
        $staff = User::factory()->forRestaurant($restaurant)->create();
        $this->actingAs($staff)->get(route('settings.imap.edit'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ImapSettingsTest`
Expected: FAIL.

- [ ] **Step 3: FormRequest**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImapSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'imap_host' => ['required', 'string', 'max:255'],
            'imap_username' => ['required', 'string', 'max:255'],
            'imap_password' => ['nullable', 'string', 'max:255'], // empty = keep
        ];
    }
}
```

- [ ] **Step 4: Controller** — mirror the SMTP controller (`edit` renders `settings/Imap` with host/username + `password_masked`, never the password; `update` keeps the existing password when the field is empty; both guard via `ownedRestaurant()` + `manageIntegrations`).

- [ ] **Step 5: Routes + Vue + nav + Ziggy** — `settings.imap.edit`/`settings.imap.update`; `resources/js/pages/settings/Imap.vue`; nav entry; regen `ziggy.d.ts`.

- [ ] **Step 6: Run tests + frontend**

Run: `php artisan test --filter=ImapSettingsTest && npm run build && npm run lint:check && npm run format:check`
Expected: PASS / clean.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Settings/ImapSettingsRequest.php app/Http/Controllers/Settings/ImapSettingsController.php routes/settings.php resources/js/pages/settings/Imap.vue resources/js/layouts/settings resources/js/ziggy.d.ts tests/Feature/Settings/ImapSettingsTest.php
git commit -m "Add owner IMAP receive settings page"
```

---

# Final verification

- [ ] **Step 1: Full backend suite** — `php artisan test` (zero failures; watch AI + mail + dashboard tests touched by the generator/key-health refactor).
- [ ] **Step 2: Frontend** — `npm run test && npm run build && npm run lint:check && npm run format:check`.
- [ ] **Step 3: Pint** — `./vendor/bin/pint --test`.
- [ ] **Step 4: Ziggy** — confirm `resources/js/ziggy.d.ts` committed with the three new settings routes; re-run `php artisan ziggy:generate --types-only resources/js/ziggy.d.ts` and verify no diff.
- [ ] **Step 5 (optional manual):** set a fake `openai_api_key` + SMTP on a restaurant via the settings pages, trigger a draft + send, confirm the per-restaurant client/mailer is used and secrets render masked.

---

## Acceptance-criteria coverage (PRD-016 §Akzeptanzkriterien, Phase 1b)

| PRD criterion | Task(s) |
|---|---|
| OpenAI key per restaurant used by generator (fallback global) | C1, C2 |
| Per-restaurant key health (rejected key flags only that restaurant) | C3 |
| SMTP send per restaurant with own From, fallback global | A1, D1 |
| IMAP receive configurable via owner UI | E1 |
| Secrets encrypted, never logged/returned in clear; masked in UI | A1, B1, C4, D2, E1 |
| Owner-only settings (manageIntegrations) | B2, C4, D2, E1 |
| Migrations up/down; no existing migration changed | A1 |
| pint/eslint/prettier/build clean; Ziggy regenerated | Final verification |

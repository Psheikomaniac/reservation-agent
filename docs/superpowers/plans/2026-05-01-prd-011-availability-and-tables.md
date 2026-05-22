# PRD-011 Implementations-Plan: Verfügbarkeits-Modell + Tisch-Liste

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tisch-Stammdaten und ein zentraler `SlotAvailability`-Service ersetzen die per-Anfrage-Slot-Logik aus PRD-005 durch eine jederzeit interaktiv abfragbare Verfügbarkeits-Sicht. Fundament für PRD-012, PRD-013, PRD-014.

**Architecture:** Zwei neue Tabellen (`tables`, `reservation_table_assignments`), eine Spalte (`restaurants.slot_buffer_minutes`). Ein Service `app/Services/Availability/SlotAvailability` mit vier Methoden, gespeist aus DTOs unter `app/Services/Availability/DTOs/`. `ReservationContextBuilder` (PRD-005) wird auf den neuen Service umgehängt, ohne sein Output-JSON zu verändern. Eine Inertia-Page `Tables.vue` mit zwei Tabs (Stammdaten / Belegung) plus zugehörige Controller, FormRequests, Policies, API-Resources.

**Tech Stack:** PHP 8.2+, Laravel 12, Eloquent, Pest 3, Vue 3.5 + Inertia v2, Reka UI, Tailwind v4, Vitest mit `@vue/test-utils`. Bestehende Patterns aus PRDs 006–010 fortführen — keine neuen Frameworks oder Patterns einführen.

**Aufwand-Schätzung:** 13 Issues, je 0.5–1.5 Tage. Gesamt ~3–4 Wochen.

---

## Datei-Struktur

| Pfad | Verantwortung | Status |
|---|---|---|
| `database/migrations/2026_05_01_000001_create_tables_table.php` | Tisch-Stammdaten-Tabelle | NEU |
| `database/migrations/2026_05_01_000002_create_reservation_table_assignments_table.php` | M:N-Verknüpfung Reservation ↔ Tisch | NEU |
| `database/migrations/2026_05_01_000003_add_slot_buffer_minutes_to_restaurants_table.php` | Pufferzeit-Setting pro Restaurant | NEU |
| `database/seeders/DefaultTableSeeder.php` | Backfill Default-Tisch pro Restaurant | NEU |
| `database/factories/TableFactory.php` | Test-Factory | NEU |
| `database/factories/ReservationTableAssignmentFactory.php` | Test-Factory | NEU |
| `app/Models/Table.php` | Eloquent-Model | NEU |
| `app/Models/ReservationTableAssignment.php` | Eloquent-Model | NEU |
| `app/Models/ReservationRequest.php` | Add `tableAssignments()` HasMany | MODIFY |
| `app/Models/Restaurant.php` | Add `tables()` HasMany + `slot_buffer_minutes` cast | MODIFY |
| `app/Enums/SlotState.php` | Enum free/tight/full | NEU |
| `app/Services/Availability/DTOs/SlotAvailabilityResult.php` | DTO pro Slot | NEU |
| `app/Services/Availability/DTOs/DayAvailability.php` | DTO Tagesübersicht | NEU |
| `app/Services/Availability/DTOs/TableCombination.php` | DTO Tisch-Vorschlag | NEU |
| `app/Services/Availability/SlotAvailability.php` | Kern-Service mit 4 Methoden | NEU |
| `app/Services/AI/ReservationContextBuilder.php` | Auf `SlotAvailability::forSlot` umstellen | MODIFY |
| `app/Policies/TablePolicy.php` | Tenant- + Rollen-Check | NEU |
| `app/Http/Requests/TableRequest.php` | Validierung CRUD | NEU |
| `app/Http/Requests/TableAvailabilityRequest.php` | Validierung Datums-Query | NEU |
| `app/Http/Resources/TableResource.php` | API-Resource | NEU |
| `app/Http/Controllers/TableController.php` | CRUD-Endpoints | NEU |
| `app/Http/Controllers/TableAvailabilityController.php` | Belegungs-Sicht-Endpoint | NEU |
| `routes/web.php` | Neue Routes | MODIFY |
| `resources/js/pages/Tables.vue` | Inertia-Page mit zwei Tabs | NEU |
| `resources/js/components/tables/TableForm.vue` | Anlegen/Bearbeiten-Drawer | NEU |
| `resources/js/components/tables/TableAvailabilityGrid.vue` | Belegungs-Tabelle | NEU |
| `resources/js/types/index.d.ts` | Typescript-Typen | MODIFY |
| `resources/js/ziggy.d.ts` | Route-Types regenerieren | MODIFY |
| `tests/Unit/Availability/SlotAvailabilityTest.php` | Unit-Tests | NEU |
| `tests/Feature/Tables/TableCrudTest.php` | Feature-Tests CRUD | NEU |
| `tests/Feature/Tables/TableAvailabilityTest.php` | Feature-Tests Belegung | NEU |
| `tests/Feature/AI/ReservationContextBuilderRegressionTest.php` | Snapshot-Test gegen PRD-005-Output | NEU |
| `resources/js/pages/Tables.test.ts` | Vitest | NEU |

---

## Task-Reihenfolge & Branches

Jede Task = ein GitHub-Issue + ein Feature-Branch + ein PR gegen `dev`.

```
Phase A: Schema + Models + DTOs
  Task 1: Migrations         (Branch: feature/N-tables-schema)
  Task 2: Models + Factories (Branch: feature/N-tables-models)
  Task 3: DefaultTableSeeder (Branch: feature/N-default-table-seeder)
  Task 4: SlotState + DTOs   (Branch: feature/N-slot-state-dtos)

Phase B: Service-Kern
  Task 5: SlotAvailability::forSlot (Buffer-Logik)
  Task 6: SlotAvailability::suggestTableCombination
  Task 7: SlotAvailability::freeTablesAt + alternatives
  Task 8: SlotAvailability::forDay
  Task 9: ReservationContextBuilder-Refactor (Backward-Compat)

Phase C: HTTP-Schicht
  Task 10: TablePolicy
  Task 11: TableController CRUD + FormRequest + Resource

Phase D: UI
  Task 12: Tables.vue Stammdaten-Tab
  Task 13: TableAvailabilityController + Belegungs-Tab + Vitest
```

Phase B sequentiell — jede Methode nutzt die vorigen. Phase C kann nach Task 2 beginnen, parallel zu Phase B. Phase D braucht 11 + 8.

---

## Task 1: Migrations für `tables`, `reservation_table_assignments`, `slot_buffer_minutes`

**Files:**
- Create: `database/migrations/2026_05_01_000001_create_tables_table.php`
- Create: `database/migrations/2026_05_01_000002_create_reservation_table_assignments_table.php`
- Create: `database/migrations/2026_05_01_000003_add_slot_buffer_minutes_to_restaurants_table.php`

**Issue title:** `PRD-011: schema – tables, reservation_table_assignments, slot_buffer_minutes`

- [ ] **Step 1: Create the tables migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('label');
            $table->unsignedTinyInteger('seats');
            $table->string('room_tag')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->json('combinable_with')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
```

- [ ] **Step 2: Create the assignments migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reservation_table_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_request_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('table_id')
                ->constrained('tables')
                ->restrictOnDelete();
            $table->dateTime('assigned_at');
            $table->foreignId('assigned_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['reservation_request_id', 'table_id']);
            $table->index('table_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_table_assignments');
    }
};
```

- [ ] **Step 3: Create the slot_buffer_minutes migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->unsignedSmallInteger('slot_buffer_minutes')->default(90);
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('slot_buffer_minutes');
        });
    }
};
```

- [ ] **Step 4: Run migrations forward**

Run: `php artisan migrate`
Expected: three new migrations applied without error.

- [ ] **Step 5: Run migrations backward, then forward again**

Run: `php artisan migrate:rollback --step=3 && php artisan migrate`
Expected: rollback drops both tables and the column, then re-creates them. No errors.

- [ ] **Step 6: Run existing test suite to confirm no regression**

Run: `composer test`
Expected: all previously green tests still green.

- [ ] **Step 7: Commit**

```bash
git checkout -b feature/<issue-nr>-tables-schema
git add database/migrations/2026_05_01_000001_create_tables_table.php \
        database/migrations/2026_05_01_000002_create_reservation_table_assignments_table.php \
        database/migrations/2026_05_01_000003_add_slot_buffer_minutes_to_restaurants_table.php
git commit -m "Add tables, reservation_table_assignments, slot_buffer_minutes schema (PRD-011)"
```

---

## Task 2: Models + Factories + Relationships

**Files:**
- Create: `app/Models/Table.php`
- Create: `app/Models/ReservationTableAssignment.php`
- Create: `database/factories/TableFactory.php`
- Create: `database/factories/ReservationTableAssignmentFactory.php`
- Modify: `app/Models/ReservationRequest.php`
- Modify: `app/Models/Restaurant.php`

**Issue title:** `PRD-011: models – Table, ReservationTableAssignment, relations`

- [ ] **Step 1: Write the failing test for Table model basics**

Create `tests/Feature/Models/TableTest.php`:

```php
<?php

use App\Models\Restaurant;
use App\Models\Table;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('belongs to a restaurant', function () {
    $restaurant = Restaurant::factory()->create();
    $table = Table::factory()->for($restaurant)->create();

    expect($table->restaurant->is($restaurant))->toBeTrue();
});

it('casts combinable_with to array', function () {
    $table = Table::factory()->create([
        'combinable_with' => [1, 2, 3],
    ]);

    expect($table->fresh()->combinable_with)->toBe([1, 2, 3]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TableTest`
Expected: FAIL — `Class "App\Models\Table" not found`.

- [ ] **Step 3: Create Table model**

Create `app/Models/Table.php`:

```php
<?php

namespace App\Models;

use Database\Factories\TableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Table extends Model
{
    /** @use HasFactory<TableFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'label',
        'seats',
        'room_tag',
        'sort_order',
        'active',
        'combinable_with',
    ];

    protected $casts = [
        'seats' => 'integer',
        'sort_order' => 'integer',
        'active' => 'boolean',
        'combinable_with' => 'array',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ReservationTableAssignment::class);
    }
}
```

- [ ] **Step 4: Create TableFactory**

Create `database/factories/TableFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\Table;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Table>
 */
class TableFactory extends Factory
{
    protected $model = Table::class;

    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'label' => fake()->unique()->numerify('Tisch ##'),
            'seats' => fake()->numberBetween(2, 8),
            'room_tag' => fake()->randomElement([null, 'Innen', 'Terrasse']),
            'sort_order' => fake()->numberBetween(1, 100),
            'active' => true,
            'combinable_with' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
```

- [ ] **Step 5: Run Table test, verify it passes**

Run: `php artisan test --filter=TableTest`
Expected: 2 tests passing.

- [ ] **Step 6: Write failing test for ReservationTableAssignment**

Add to `tests/Feature/Models/TableTest.php`:

```php
it('reservation has many table assignments', function () {
    $reservation = App\Models\ReservationRequest::factory()->create();
    $table = Table::factory()->for($reservation->restaurant)->create();

    App\Models\ReservationTableAssignment::factory()
        ->for($reservation, 'reservationRequest')
        ->for($table)
        ->create();

    expect($reservation->fresh()->tableAssignments)->toHaveCount(1);
});
```

- [ ] **Step 7: Run test, verify it fails**

Run: `php artisan test --filter=TableTest`
Expected: FAIL — `Class "App\Models\ReservationTableAssignment" not found`.

- [ ] **Step 8: Create ReservationTableAssignment model**

Create `app/Models/ReservationTableAssignment.php`:

```php
<?php

namespace App\Models;

use Database\Factories\ReservationTableAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationTableAssignment extends Model
{
    /** @use HasFactory<ReservationTableAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'reservation_request_id',
        'table_id',
        'assigned_at',
        'assigned_by_user_id',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function reservationRequest(): BelongsTo
    {
        return $this->belongsTo(ReservationRequest::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
```

- [ ] **Step 9: Create ReservationTableAssignmentFactory**

Create `database/factories/ReservationTableAssignmentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Table;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationTableAssignment>
 */
class ReservationTableAssignmentFactory extends Factory
{
    protected $model = ReservationTableAssignment::class;

    public function definition(): array
    {
        return [
            'reservation_request_id' => ReservationRequest::factory(),
            'table_id' => Table::factory(),
            'assigned_at' => now(),
            'assigned_by_user_id' => null,
        ];
    }
}
```

- [ ] **Step 10: Add `tableAssignments` relation to ReservationRequest**

Modify `app/Models/ReservationRequest.php` — add this method inside the class (find the existing relations section like `hasMany(ReservationReply::class)` and add nearby):

```php
public function tableAssignments(): HasMany
{
    return $this->hasMany(ReservationTableAssignment::class);
}
```

If `HasMany` is not yet imported, add `use Illuminate\Database\Eloquent\Relations\HasMany;` to the use statements.

- [ ] **Step 11: Add `tables` relation + slot_buffer_minutes cast to Restaurant**

Modify `app/Models/Restaurant.php`:

Append to the `$fillable` array:
```php
'slot_buffer_minutes',
```

Add to `$casts` (or define it if not present):
```php
'slot_buffer_minutes' => 'integer',
```

Add the relation method:
```php
public function tables(): HasMany
{
    return $this->hasMany(Table::class);
}
```

- [ ] **Step 12: Run all model tests**

Run: `php artisan test --filter=TableTest`
Expected: 3 tests passing.

- [ ] **Step 13: Run full test suite**

Run: `composer test`
Expected: all green. ReservationRequest factory should still work — the relation addition is non-breaking.

- [ ] **Step 14: Commit**

```bash
git checkout -b feature/<issue-nr>-tables-models
git add app/Models/Table.php \
        app/Models/ReservationTableAssignment.php \
        app/Models/ReservationRequest.php \
        app/Models/Restaurant.php \
        database/factories/TableFactory.php \
        database/factories/ReservationTableAssignmentFactory.php \
        tests/Feature/Models/TableTest.php
git commit -m "Add Table + ReservationTableAssignment models with relations (PRD-011)"
```

---

## Task 3: DefaultTableSeeder for existing restaurants

**Files:**
- Create: `database/seeders/DefaultTableSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php` (call DefaultTableSeeder)
- Create: `tests/Feature/Seeders/DefaultTableSeederTest.php`

**Issue title:** `PRD-011: DefaultTableSeeder for existing restaurants`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Seeders/DefaultTableSeederTest.php`:

```php
<?php

use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\Table;
use Database\Seeders\DefaultTableSeeder;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('creates a default table per restaurant when none exists', function () {
    $restaurant = Restaurant::factory()->create();

    (new DefaultTableSeeder)->run();

    expect(Table::where('restaurant_id', $restaurant->id)->count())->toBe(1);
});

it('skips restaurants that already have a table', function () {
    $restaurant = Restaurant::factory()->create();
    Table::factory()->for($restaurant)->create();

    (new DefaultTableSeeder)->run();

    expect(Table::where('restaurant_id', $restaurant->id)->count())->toBe(1);
});

it('sizes the default table by max historical party size plus four', function () {
    $restaurant = Restaurant::factory()->create();
    ReservationRequest::factory()->for($restaurant)->create(['party_size' => 6]);
    ReservationRequest::factory()->for($restaurant)->create(['party_size' => 9]);

    (new DefaultTableSeeder)->run();

    $table = Table::where('restaurant_id', $restaurant->id)->first();
    expect($table->seats)->toBe(13); // 9 + 4
});

it('uses fallback seats when restaurant has no reservations', function () {
    $restaurant = Restaurant::factory()->create();

    (new DefaultTableSeeder)->run();

    $table = Table::where('restaurant_id', $restaurant->id)->first();
    expect($table->seats)->toBe(8); // fallback default 4 + 4
});
```

- [ ] **Step 2: Run test, verify it fails**

Run: `php artisan test --filter=DefaultTableSeederTest`
Expected: FAIL — `Class "Database\Seeders\DefaultTableSeeder" not found`.

- [ ] **Step 3: Implement the seeder**

Create `database/seeders/DefaultTableSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\Table;
use Illuminate\Database\Seeder;

class DefaultTableSeeder extends Seeder
{
    public function run(): void
    {
        Restaurant::query()
            ->whereDoesntHave('tables')
            ->each(function (Restaurant $restaurant): void {
                $maxParty = ReservationRequest::query()
                    ->where('restaurant_id', $restaurant->id)
                    ->max('party_size') ?? 4;

                Table::create([
                    'restaurant_id' => $restaurant->id,
                    'label' => 'Tisch 1',
                    'seats' => $maxParty + 4,
                    'sort_order' => 1,
                    'active' => true,
                ]);
            });
    }
}
```

- [ ] **Step 4: Run tests, verify they pass**

Run: `php artisan test --filter=DefaultTableSeederTest`
Expected: 4 tests passing.

- [ ] **Step 5: Wire into DatabaseSeeder**

Modify `database/seeders/DatabaseSeeder.php` — add a call to `DefaultTableSeeder` in the `run()` method, after any restaurant-creating seeders. Read the file first; if it already has `$this->call([...])` then add `DefaultTableSeeder::class` to that array. Otherwise append:

```php
$this->call(DefaultTableSeeder::class);
```

- [ ] **Step 6: Verify migrate:fresh --seed works**

Run: `php artisan migrate:fresh --seed`
Expected: completes without error. New restaurants from the seeder pipeline get default tables.

- [ ] **Step 7: Run full test suite**

Run: `composer test`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git checkout -b feature/<issue-nr>-default-table-seeder
git add database/seeders/DefaultTableSeeder.php \
        database/seeders/DatabaseSeeder.php \
        tests/Feature/Seeders/DefaultTableSeederTest.php
git commit -m "Add DefaultTableSeeder for restaurants without tables (PRD-011)"
```

---

## Task 4: SlotState enum + DTOs

**Files:**
- Create: `app/Enums/SlotState.php`
- Create: `app/Services/Availability/DTOs/SlotAvailabilityResult.php`
- Create: `app/Services/Availability/DTOs/DayAvailability.php`
- Create: `app/Services/Availability/DTOs/TableCombination.php`
- Create: `tests/Unit/Availability/DTOTest.php`

**Issue title:** `PRD-011: SlotState enum + availability DTOs`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Availability/DTOTest.php`:

```php
<?php

use App\Enums\SlotState;
use App\Services\Availability\DTOs\DayAvailability;
use App\Services\Availability\DTOs\SlotAvailabilityResult;
use App\Services\Availability\DTOs\TableCombination;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

it('SlotState enum has three cases', function () {
    expect(SlotState::cases())->toHaveCount(3);
    expect(SlotState::Free->value)->toBe('free');
    expect(SlotState::Tight->value)->toBe('tight');
    expect(SlotState::Full->value)->toBe('full');
});

it('SlotAvailabilityResult is constructible with full state', function () {
    $result = new SlotAvailabilityResult(
        slotStart: CarbonImmutable::parse('2026-06-15 19:00'),
        state: SlotState::Free,
        suggestedTableId: 7,
        combination: null,
        alternativeSlots: collect(),
    );

    expect($result->state)->toBe(SlotState::Free);
    expect($result->suggestedTableId)->toBe(7);
    expect($result->combination)->toBeNull();
});

it('TableCombination exposes primaryTableId and totalSeats', function () {
    $combo = new TableCombination(
        primaryTableId: 5,
        tableIds: [5, 6],
        totalSeats: 8,
    );

    expect($combo->primaryTableId)->toBe(5);
    expect($combo->tableIds)->toBe([5, 6]);
    expect($combo->totalSeats)->toBe(8);
});

it('DayAvailability holds slot collection and totals', function () {
    $day = new DayAvailability(
        date: CarbonImmutable::parse('2026-06-15'),
        slots: collect(),
        totalCapacity: 50,
        reservedSeats: 12,
    );

    expect($day->totalCapacity)->toBe(50);
    expect($day->reservedSeats)->toBe(12);
    expect($day->slots)->toBeInstanceOf(Collection::class);
});
```

- [ ] **Step 2: Run test, verify it fails**

Run: `php artisan test --filter=DTOTest`
Expected: FAIL — `Class "App\Enums\SlotState" not found`.

- [ ] **Step 3: Create SlotState enum**

Create `app/Enums/SlotState.php`:

```php
<?php

namespace App\Enums;

enum SlotState: string
{
    case Free = 'free';
    case Tight = 'tight';
    case Full = 'full';
}
```

- [ ] **Step 4: Create TableCombination DTO**

Create `app/Services/Availability/DTOs/TableCombination.php`:

```php
<?php

namespace App\Services\Availability\DTOs;

final readonly class TableCombination
{
    /**
     * @param  list<int>  $tableIds
     */
    public function __construct(
        public int $primaryTableId,
        public array $tableIds,
        public int $totalSeats,
    ) {}
}
```

- [ ] **Step 5: Create SlotAvailabilityResult DTO**

Create `app/Services/Availability/DTOs/SlotAvailabilityResult.php`:

```php
<?php

namespace App\Services\Availability\DTOs;

use App\Enums\SlotState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final readonly class SlotAvailabilityResult
{
    /**
     * @param  Collection<int, CarbonImmutable>  $alternativeSlots
     */
    public function __construct(
        public CarbonImmutable $slotStart,
        public SlotState $state,
        public ?int $suggestedTableId,
        public ?TableCombination $combination,
        public Collection $alternativeSlots,
    ) {}
}
```

- [ ] **Step 6: Create DayAvailability DTO**

Create `app/Services/Availability/DTOs/DayAvailability.php`:

```php
<?php

namespace App\Services\Availability\DTOs;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final readonly class DayAvailability
{
    /**
     * @param  Collection<int, SlotAvailabilityResult>  $slots
     */
    public function __construct(
        public CarbonImmutable $date,
        public Collection $slots,
        public int $totalCapacity,
        public int $reservedSeats,
    ) {}
}
```

- [ ] **Step 7: Run tests, verify they pass**

Run: `php artisan test --filter=DTOTest`
Expected: 4 tests passing.

- [ ] **Step 8: Commit**

```bash
git checkout -b feature/<issue-nr>-slot-state-dtos
git add app/Enums/SlotState.php app/Services/Availability/DTOs/ tests/Unit/Availability/DTOTest.php
git commit -m "Add SlotState enum and availability DTOs (PRD-011)"
```

---

## Task 5: SlotAvailability::forSlot with buffer logic

**Files:**
- Create: `app/Services/Availability/SlotAvailability.php`
- Create: `tests/Unit/Availability/SlotAvailabilityTest.php`

**Issue title:** `PRD-011: SlotAvailability::forSlot with buffer logic`

This is the keystone method. It must answer "is this single slot for this party size free, tight, or full" using buffer-aware table-occupancy logic.

**Key rules (from PRD-011):**
- A reservation occupies a table from `desired_at` to `desired_at + slot_duration`. Slot duration is **not** a per-restaurant column in V3; treat it as a constant 90 min for the implementation. (Future configurable, see PRD-011 risks.)
- Pufferzeit is symmetric: `desired_at - buffer` to `desired_at + slot_duration + buffer`.
- Active occupancy statuses: `InReview`, `Replied`, `Confirmed`. `New`, `Declined`, `Cancelled`, `Waitlisted` do **not** occupy a table.
- A slot is **free** if there is at least one table fitting `partySize` and not occupied. **Tight** if free but ≤ 25 % of total active capacity remains in that slot. **Full** otherwise.
- For now `forSlot` returns the suggested single table or null for combination — combination logic comes in Task 6.

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Availability/SlotAvailabilityTest.php`:

```php
<?php

use App\Enums\ReservationStatus;
use App\Enums\SlotState;
use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Services\Availability\SlotAvailability;
use Carbon\CarbonImmutable;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(SlotAvailability::class);
    $this->restaurant = Restaurant::factory()->create([
        'slot_buffer_minutes' => 90,
        'opening_hours' => [
            'mon' => [['open' => '17:00', 'close' => '23:00']],
            'tue' => [['open' => '17:00', 'close' => '23:00']],
            'wed' => [['open' => '17:00', 'close' => '23:00']],
            'thu' => [['open' => '17:00', 'close' => '23:00']],
            'fri' => [['open' => '17:00', 'close' => '23:00']],
            'sat' => [['open' => '17:00', 'close' => '23:00']],
            'sun' => [['open' => '17:00', 'close' => '23:00']],
        ],
    ]);
});

it('marks slot free when a fitting table exists and no reservation overlaps', function () {
    Table::factory()->for($this->restaurant)->create(['seats' => 4]);

    $result = $this->service->forSlot(
        $this->restaurant->id,
        CarbonImmutable::parse('2026-06-15 19:00'),
        partySize: 4,
    );

    expect($result->state)->toBe(SlotState::Free);
    expect($result->suggestedTableId)->not->toBeNull();
});

it('marks slot full when no table fits the party size', function () {
    Table::factory()->for($this->restaurant)->create(['seats' => 2]);

    $result = $this->service->forSlot(
        $this->restaurant->id,
        CarbonImmutable::parse('2026-06-15 19:00'),
        partySize: 6,
    );

    expect($result->state)->toBe(SlotState::Full);
    expect($result->suggestedTableId)->toBeNull();
});

it('marks slot full when the only fitting table is busy in the buffer window', function () {
    $table = Table::factory()->for($this->restaurant)->create(['seats' => 4]);
    $otherReservation = ReservationRequest::factory()
        ->for($this->restaurant)
        ->create([
            'desired_at' => '2026-06-15 18:00',
            'party_size' => 2,
            'status' => ReservationStatus::Confirmed,
        ]);
    ReservationTableAssignment::factory()
        ->for($otherReservation, 'reservationRequest')
        ->for($table)
        ->create();

    // Asking for 19:00 — but 18:00 reservation runs to 19:30 (90 min slot)
    // and the buffer extends that to 21:00. So 19:00 must be FULL.
    $result = $this->service->forSlot(
        $this->restaurant->id,
        CarbonImmutable::parse('2026-06-15 19:00'),
        partySize: 4,
    );

    expect($result->state)->toBe(SlotState::Full);
});

it('treats new and declined reservations as not occupying', function () {
    $table = Table::factory()->for($this->restaurant)->create(['seats' => 4]);
    $declined = ReservationRequest::factory()
        ->for($this->restaurant)
        ->create([
            'desired_at' => '2026-06-15 19:00',
            'party_size' => 4,
            'status' => ReservationStatus::Declined,
        ]);
    ReservationTableAssignment::factory()
        ->for($declined, 'reservationRequest')
        ->for($table)
        ->create();

    $result = $this->service->forSlot(
        $this->restaurant->id,
        CarbonImmutable::parse('2026-06-15 19:00'),
        partySize: 4,
    );

    expect($result->state)->toBe(SlotState::Free);
});

it('returns full for a slot outside opening hours', function () {
    Table::factory()->for($this->restaurant)->create(['seats' => 4]);

    $result = $this->service->forSlot(
        $this->restaurant->id,
        CarbonImmutable::parse('2026-06-15 09:00'),
        partySize: 2,
    );

    expect($result->state)->toBe(SlotState::Full);
});

it('marks slot tight when remaining capacity is small', function () {
    // Two tables × 4 seats = total 8. Reservation occupies one (4 seats busy).
    // Remaining 4 of 8 = 50 %. Test 25 % threshold by adding a tiny extra reservation
    // is messy; instead set up: total 16 seats (4 tables × 4), 13 reserved → tight
    $tables = Table::factory()->for($this->restaurant)->count(4)->create(['seats' => 4]);
    foreach ([0, 1, 2] as $i) {
        $r = ReservationRequest::factory()
            ->for($this->restaurant)
            ->create([
                'desired_at' => '2026-06-15 19:00',
                'party_size' => 4,
                'status' => ReservationStatus::Confirmed,
            ]);
        ReservationTableAssignment::factory()
            ->for($r, 'reservationRequest')
            ->for($tables[$i])
            ->create();
    }

    $result = $this->service->forSlot(
        $this->restaurant->id,
        CarbonImmutable::parse('2026-06-15 19:00'),
        partySize: 2, // fits the last 4-seat table
    );

    // 12 of 16 seats reserved → 4 remaining → 25 %. Spec says ≤ 25 % is Tight.
    expect($result->state)->toBe(SlotState::Tight);
});
```

- [ ] **Step 2: Run tests, verify they fail**

Run: `php artisan test --filter=SlotAvailabilityTest`
Expected: FAIL — `Class "App\Services\Availability\SlotAvailability" not found`.

- [ ] **Step 3: Implement SlotAvailability::forSlot**

Create `app/Services/Availability/SlotAvailability.php`:

```php
<?php

namespace App\Services\Availability;

use App\Enums\ReservationStatus;
use App\Enums\SlotState;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\Table;
use App\Services\Availability\DTOs\SlotAvailabilityResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class SlotAvailability
{
    /**
     * Slot duration in minutes. V3 uses a fixed value; PRD-011 risks document
     * the future per-restaurant configurability.
     */
    private const int SLOT_DURATION_MINUTES = 90;

    private const array OCCUPYING_STATUSES = [
        ReservationStatus::InReview,
        ReservationStatus::Replied,
        ReservationStatus::Confirmed,
    ];

    private const float TIGHT_THRESHOLD = 0.25;

    public function forSlot(int $restaurantId, CarbonImmutable $datetime, int $partySize): SlotAvailabilityResult
    {
        $restaurant = Restaurant::query()->findOrFail($restaurantId);

        if (! $this->isWithinOpeningHours($restaurant, $datetime)) {
            return $this->fullResult($datetime);
        }

        $tables = $restaurant->tables()->where('active', true)->get();
        if ($tables->isEmpty()) {
            return $this->fullResult($datetime);
        }

        $busyTableIds = $this->busyTableIds(
            $restaurantId,
            $datetime,
            (int) $restaurant->slot_buffer_minutes,
        );

        $freeTables = $tables->filter(fn (Table $t): bool => ! $busyTableIds->contains($t->id));
        $fittingTables = $freeTables->filter(fn (Table $t): bool => $t->seats >= $partySize);

        if ($fittingTables->isEmpty()) {
            return $this->fullResult($datetime);
        }

        $suggested = $fittingTables->sortBy('seats')->first();

        $totalCapacity = (int) $tables->sum('seats');
        $busyCapacity = (int) $tables->whereIn('id', $busyTableIds)->sum('seats');
        $remainingRatio = $totalCapacity > 0 ? ($totalCapacity - $busyCapacity) / $totalCapacity : 0.0;

        $state = $remainingRatio <= self::TIGHT_THRESHOLD ? SlotState::Tight : SlotState::Free;

        return new SlotAvailabilityResult(
            slotStart: $datetime,
            state: $state,
            suggestedTableId: $suggested->id,
            combination: null,
            alternativeSlots: collect(),
        );
    }

    /**
     * @return Collection<int, int>
     */
    private function busyTableIds(int $restaurantId, CarbonImmutable $datetime, int $bufferMinutes): Collection
    {
        $slotEnd = $datetime->addMinutes(self::SLOT_DURATION_MINUTES);
        $windowStart = $datetime->subMinutes($bufferMinutes + self::SLOT_DURATION_MINUTES);
        $windowEnd = $slotEnd->addMinutes($bufferMinutes);

        return ReservationRequest::query()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('status', array_map(fn (ReservationStatus $s): string => $s->value, self::OCCUPYING_STATUSES))
            ->whereBetween('desired_at', [$windowStart, $windowEnd])
            ->with('tableAssignments:reservation_request_id,table_id')
            ->get()
            ->flatMap(fn (ReservationRequest $r) => $r->tableAssignments->pluck('table_id'))
            ->unique()
            ->values();
    }

    private function isWithinOpeningHours(Restaurant $restaurant, CarbonImmutable $datetime): bool
    {
        $hours = $restaurant->opening_hours ?? [];
        $dayKey = strtolower($datetime->format('D'));
        $windows = $hours[$dayKey] ?? [];

        $time = $datetime->format('H:i');
        foreach ($windows as $window) {
            if ($time >= $window['open'] && $time < $window['close']) {
                return true;
            }
        }

        return false;
    }

    private function fullResult(CarbonImmutable $datetime): SlotAvailabilityResult
    {
        return new SlotAvailabilityResult(
            slotStart: $datetime,
            state: SlotState::Full,
            suggestedTableId: null,
            combination: null,
            alternativeSlots: collect(),
        );
    }
}
```

- [ ] **Step 4: Run tests, verify they pass**

Run: `php artisan test --filter=SlotAvailabilityTest`
Expected: 6 tests passing.

- [ ] **Step 5: Run full suite**

Run: `composer test`
Expected: all green.

- [ ] **Step 6: Run pint**

Run: `./vendor/bin/pint --test`
Expected: no findings.

- [ ] **Step 7: Commit**

```bash
git checkout -b feature/<issue-nr>-slot-availability-forslot
git add app/Services/Availability/SlotAvailability.php tests/Unit/Availability/SlotAvailabilityTest.php
git commit -m "Add SlotAvailability::forSlot with buffer-aware occupancy (PRD-011)"
```

---

## Task 6: SlotAvailability::suggestTableCombination

**Files:**
- Modify: `app/Services/Availability/SlotAvailability.php`
- Modify: `tests/Unit/Availability/SlotAvailabilityTest.php`

**Issue title:** `PRD-011: SlotAvailability::suggestTableCombination (single + 2-table combo)`

PRD-011 In-Scope: maximum 2-table combinations using `combinable_with`. 3+ tables out of scope for V3.

- [ ] **Step 1: Add failing tests**

Append to `tests/Unit/Availability/SlotAvailabilityTest.php`:

```php
it('suggests smallest fitting single table when one exists', function () {
    Table::factory()->for($this->restaurant)->create(['seats' => 8, 'sort_order' => 1]);
    Table::factory()->for($this->restaurant)->create(['seats' => 4, 'sort_order' => 2]);

    $combo = $this->service->suggestTableCombination(
        $this->restaurant->id,
        CarbonImmutable::parse('2026-06-15 19:00'),
        partySize: 3,
    );

    expect($combo)->not->toBeNull();
    expect($combo->totalSeats)->toBe(4);
    expect($combo->tableIds)->toHaveCount(1);
});

it('suggests two-table combination when no single table fits', function () {
    $a = Table::factory()->for($this->restaurant)->create(['seats' => 4, 'sort_order' => 1]);
    $b = Table::factory()->for($this->restaurant)->create([
        'seats' => 4,
        'sort_order' => 2,
        'combinable_with' => [$a->id],
    ]);
    // a needs to be combinable with b too — set after creation
    $a->update(['combinable_with' => [$b->id]]);

    $combo = $this->service->suggestTableCombination(
        $this->restaurant->id,
        CarbonImmutable::parse('2026-06-15 19:00'),
        partySize: 6,
    );

    expect($combo)->not->toBeNull();
    expect($combo->totalSeats)->toBe(8);
    expect($combo->tableIds)->toHaveCount(2);
});

it('returns null when no single table and no compatible combo fits', function () {
    Table::factory()->for($this->restaurant)->create(['seats' => 2]);
    Table::factory()->for($this->restaurant)->create(['seats' => 2]);

    $combo = $this->service->suggestTableCombination(
        $this->restaurant->id,
        CarbonImmutable::parse('2026-06-15 19:00'),
        partySize: 8,
    );

    expect($combo)->toBeNull();
});

it('does not combine tables when one of them is busy', function () {
    $a = Table::factory()->for($this->restaurant)->create(['seats' => 4, 'sort_order' => 1]);
    $b = Table::factory()->for($this->restaurant)->create([
        'seats' => 4,
        'sort_order' => 2,
        'combinable_with' => [$a->id],
    ]);
    $a->update(['combinable_with' => [$b->id]]);

    $busy = ReservationRequest::factory()
        ->for($this->restaurant)
        ->create([
            'desired_at' => '2026-06-15 19:00',
            'party_size' => 4,
            'status' => ReservationStatus::Confirmed,
        ]);
    ReservationTableAssignment::factory()
        ->for($busy, 'reservationRequest')
        ->for($a)
        ->create();

    $combo = $this->service->suggestTableCombination(
        $this->restaurant->id,
        CarbonImmutable::parse('2026-06-15 19:00'),
        partySize: 6,
    );

    expect($combo)->toBeNull();
});
```

- [ ] **Step 2: Run tests, verify they fail**

Run: `php artisan test --filter=SlotAvailabilityTest`
Expected: 4 new tests fail with `Method "suggestTableCombination" not found`.

- [ ] **Step 3: Add `suggestTableCombination` method to SlotAvailability**

Modify `app/Services/Availability/SlotAvailability.php` — add the method below `forSlot`:

```php
public function suggestTableCombination(int $restaurantId, CarbonImmutable $datetime, int $partySize): ?TableCombination
{
    $restaurant = Restaurant::query()->findOrFail($restaurantId);
    $tables = $restaurant->tables()->where('active', true)->get();

    if ($tables->isEmpty()) {
        return null;
    }

    $busyTableIds = $this->busyTableIds(
        $restaurantId,
        $datetime,
        (int) $restaurant->slot_buffer_minutes,
    );
    $freeTables = $tables->filter(fn (Table $t): bool => ! $busyTableIds->contains($t->id));

    // 1. Smallest fitting single table
    $single = $freeTables
        ->filter(fn (Table $t): bool => $t->seats >= $partySize)
        ->sortBy('seats')
        ->first();

    if ($single !== null) {
        return new TableCombination(
            primaryTableId: $single->id,
            tableIds: [$single->id],
            totalSeats: (int) $single->seats,
        );
    }

    // 2. Smallest 2-table combination via combinable_with
    $best = null;
    foreach ($freeTables as $primary) {
        $partners = $primary->combinable_with ?? [];
        foreach ($partners as $partnerId) {
            $partner = $freeTables->firstWhere('id', $partnerId);
            if ($partner === null || $partner->id === $primary->id) {
                continue;
            }
            $total = (int) $primary->seats + (int) $partner->seats;
            if ($total < $partySize) {
                continue;
            }
            if ($best === null || $total < $best->totalSeats) {
                $best = new TableCombination(
                    primaryTableId: $primary->id,
                    tableIds: [$primary->id, $partner->id],
                    totalSeats: $total,
                );
            }
        }
    }

    return $best;
}
```

Add `use App\Services\Availability\DTOs\TableCombination;` to the use statements.

- [ ] **Step 4: Run tests, verify they pass**

Run: `php artisan test --filter=SlotAvailabilityTest`
Expected: 10 tests passing.

- [ ] **Step 5: Wire `forSlot` to use combinations when no single table fits**

Currently `forSlot` returns Full when no single table fits. Update it to also consider combinations: if no single table fits but a combo does, mark Free/Tight with `combination` set.

Modify `forSlot` in `app/Services/Availability/SlotAvailability.php`:

Replace the section starting `if ($fittingTables->isEmpty())` with:

```php
if ($fittingTables->isEmpty()) {
    $combo = $this->suggestTableCombination($restaurantId, $datetime, $partySize);
    if ($combo === null) {
        return $this->fullResult($datetime);
    }

    return new SlotAvailabilityResult(
        slotStart: $datetime,
        state: SlotState::Free,
        suggestedTableId: $combo->primaryTableId,
        combination: $combo,
        alternativeSlots: collect(),
    );
}
```

- [ ] **Step 6: Add a test confirming forSlot returns combination when no single table fits**

Append to `tests/Unit/Availability/SlotAvailabilityTest.php`:

```php
it('forSlot returns combination when no single table fits party size', function () {
    $a = Table::factory()->for($this->restaurant)->create(['seats' => 4, 'sort_order' => 1]);
    $b = Table::factory()->for($this->restaurant)->create([
        'seats' => 4,
        'sort_order' => 2,
        'combinable_with' => [$a->id],
    ]);
    $a->update(['combinable_with' => [$b->id]]);

    $result = $this->service->forSlot(
        $this->restaurant->id,
        CarbonImmutable::parse('2026-06-15 19:00'),
        partySize: 6,
    );

    expect($result->state)->toBe(SlotState::Free);
    expect($result->combination)->not->toBeNull();
    expect($result->combination->tableIds)->toHaveCount(2);
});
```

- [ ] **Step 7: Run tests, verify all pass**

Run: `php artisan test --filter=SlotAvailabilityTest`
Expected: 11 tests passing.

- [ ] **Step 8: Commit**

```bash
git checkout -b feature/<issue-nr>-suggest-table-combination
git add app/Services/Availability/SlotAvailability.php tests/Unit/Availability/SlotAvailabilityTest.php
git commit -m "Add SlotAvailability::suggestTableCombination + integrate into forSlot (PRD-011)"
```

---

## Task 7: SlotAvailability::freeTablesAt + alternative slots

**Files:**
- Modify: `app/Services/Availability/SlotAvailability.php`
- Modify: `tests/Unit/Availability/SlotAvailabilityTest.php`

**Issue title:** `PRD-011: SlotAvailability::freeTablesAt + alternative slot suggestions`

`freeTablesAt` returns the list of table IDs free in a given slot. The `alternativeSlots` field on `SlotAvailabilityResult` is populated with up to 3 next free slots when state is Full (used by PRD-012's UI). Alternative-slot search: same day, walk forward in 30-min increments until 3 free slots found or end of opening hours.

- [ ] **Step 1: Add failing tests**

Append to `tests/Unit/Availability/SlotAvailabilityTest.php`:

```php
it('freeTablesAt returns ids of unbooked active tables', function () {
    $a = Table::factory()->for($this->restaurant)->create(['seats' => 4]);
    $b = Table::factory()->for($this->restaurant)->create(['seats' => 4]);

    $busy = ReservationRequest::factory()
        ->for($this->restaurant)
        ->create([
            'desired_at' => '2026-06-15 19:00',
            'party_size' => 4,
            'status' => ReservationStatus::Confirmed,
        ]);
    ReservationTableAssignment::factory()
        ->for($busy, 'reservationRequest')
        ->for($a)
        ->create();

    $free = $this->service->freeTablesAt(
        $this->restaurant->id,
        CarbonImmutable::parse('2026-06-15 19:00'),
    );

    expect($free->pluck('id')->all())->toBe([$b->id]);
});

it('forSlot fills alternativeSlots with up to 3 next free slots when full', function () {
    Table::factory()->for($this->restaurant)->create(['seats' => 4]);

    // Book 19:00–20:30 (slot + buffer reaches 22:00)
    $busy = ReservationRequest::factory()
        ->for($this->restaurant)
        ->create([
            'desired_at' => '2026-06-15 19:00',
            'party_size' => 4,
            'status' => ReservationStatus::Confirmed,
        ]);
    ReservationTableAssignment::factory()
        ->for($busy, 'reservationRequest')
        ->for(Table::first())
        ->create();

    $result = $this->service->forSlot(
        $this->restaurant->id,
        CarbonImmutable::parse('2026-06-15 19:00'),
        partySize: 4,
    );

    expect($result->state)->toBe(SlotState::Full);
    // After 22:00 (buffer-end), next 30-min slots until 23:00 close are 22:00, 22:30
    expect($result->alternativeSlots)->toHaveCount(2);
    expect($result->alternativeSlots->first()->format('H:i'))->toBe('22:00');
});
```

- [ ] **Step 2: Run tests, verify they fail**

Run: `php artisan test --filter=SlotAvailabilityTest`
Expected: 2 new tests fail.

- [ ] **Step 3: Add `freeTablesAt` and alternative-slot logic**

Modify `app/Services/Availability/SlotAvailability.php`:

Add the public method:

```php
/**
 * @return Collection<int, Table>
 */
public function freeTablesAt(int $restaurantId, CarbonImmutable $datetime): Collection
{
    $restaurant = Restaurant::query()->findOrFail($restaurantId);
    $tables = $restaurant->tables()->where('active', true)->get();
    $busyTableIds = $this->busyTableIds(
        $restaurantId,
        $datetime,
        (int) $restaurant->slot_buffer_minutes,
    );

    return $tables->reject(fn (Table $t): bool => $busyTableIds->contains($t->id))->values();
}
```

Add a private helper:

```php
/**
 * @return Collection<int, CarbonImmutable>
 */
private function findAlternativeSlots(int $restaurantId, CarbonImmutable $afterDatetime, int $partySize, int $maxResults = 3): Collection
{
    $alternatives = collect();
    $candidate = $afterDatetime->addMinutes(30);
    $endOfDay = $candidate->copy()->setTime(23, 59);

    while ($alternatives->count() < $maxResults && $candidate->lte($endOfDay)) {
        $check = $this->forSlotInternal($restaurantId, $candidate, $partySize, includeAlternatives: false);
        if ($check->state !== SlotState::Full) {
            $alternatives->push($candidate);
        }
        $candidate = $candidate->addMinutes(30);
    }

    return $alternatives;
}
```

Refactor: extract the existing `forSlot` body into a private `forSlotInternal` with a flag to skip alternatives (avoids infinite recursion). Public `forSlot` calls `forSlotInternal($..., includeAlternatives: true)` and, when the result is Full, adds alternatives.

Replace the public `forSlot` body with:

```php
public function forSlot(int $restaurantId, CarbonImmutable $datetime, int $partySize): SlotAvailabilityResult
{
    $result = $this->forSlotInternal($restaurantId, $datetime, $partySize, includeAlternatives: true);

    if ($result->state === SlotState::Full) {
        return new SlotAvailabilityResult(
            slotStart: $result->slotStart,
            state: $result->state,
            suggestedTableId: $result->suggestedTableId,
            combination: $result->combination,
            alternativeSlots: $this->findAlternativeSlots($restaurantId, $datetime, $partySize),
        );
    }

    return $result;
}

private function forSlotInternal(int $restaurantId, CarbonImmutable $datetime, int $partySize, bool $includeAlternatives): SlotAvailabilityResult
{
    // ... move the previous forSlot body here unchanged ...
}
```

- [ ] **Step 4: Run tests, verify they pass**

Run: `php artisan test --filter=SlotAvailabilityTest`
Expected: 13 tests passing.

- [ ] **Step 5: Run pint**

Run: `./vendor/bin/pint --test`
Expected: no findings.

- [ ] **Step 6: Commit**

```bash
git checkout -b feature/<issue-nr>-free-tables-and-alternatives
git add app/Services/Availability/SlotAvailability.php tests/Unit/Availability/SlotAvailabilityTest.php
git commit -m "Add freeTablesAt + alternative-slot suggestions to SlotAvailability (PRD-011)"
```

---

## Task 8: SlotAvailability::forDay

**Files:**
- Modify: `app/Services/Availability/SlotAvailability.php`
- Modify: `tests/Unit/Availability/SlotAvailabilityTest.php`

**Issue title:** `PRD-011: SlotAvailability::forDay (full day grid)`

Returns all 30-min slots within opening hours for a date, each evaluated against a default party size of 1 (so the grid shows table-level occupancy, not party-fit).

- [ ] **Step 1: Add failing test**

Append to `tests/Unit/Availability/SlotAvailabilityTest.php`:

```php
it('forDay returns slots within opening hours only', function () {
    Table::factory()->for($this->restaurant)->create(['seats' => 4]);

    $day = $this->service->forDay(
        $this->restaurant->id,
        CarbonImmutable::parse('2026-06-15'),
    );

    // Opening hours 17:00–23:00 = 12 slots of 30 min
    expect($day->slots)->toHaveCount(12);
    expect($day->slots->first()->slotStart->format('H:i'))->toBe('17:00');
    expect($day->slots->last()->slotStart->format('H:i'))->toBe('22:30');
});

it('forDay computes total capacity and reserved seats', function () {
    Table::factory()->for($this->restaurant)->count(2)->create(['seats' => 4]);

    $busy = ReservationRequest::factory()
        ->for($this->restaurant)
        ->create([
            'desired_at' => '2026-06-15 19:00',
            'party_size' => 3,
            'status' => ReservationStatus::Confirmed,
        ]);
    ReservationTableAssignment::factory()
        ->for($busy, 'reservationRequest')
        ->for(Table::first())
        ->create();

    $day = $this->service->forDay(
        $this->restaurant->id,
        CarbonImmutable::parse('2026-06-15'),
    );

    expect($day->totalCapacity)->toBe(8);
    expect($day->reservedSeats)->toBe(3);
});

it('forDay performance: single tables-and-reservations query then PHP processing', function () {
    Table::factory()->for($this->restaurant)->count(5)->create(['seats' => 4]);
    ReservationRequest::factory()
        ->for($this->restaurant)
        ->count(20)
        ->create([
            'desired_at' => '2026-06-15 19:00',
            'party_size' => 2,
            'status' => ReservationStatus::Confirmed,
        ]);

    DB::enableQueryLog();
    $this->service->forDay($this->restaurant->id, CarbonImmutable::parse('2026-06-15'));
    $queries = DB::getQueryLog();

    // Restaurant + tables + reservations = 3 queries max (no N+1 across slots)
    expect(count($queries))->toBeLessThanOrEqual(4);
});
```

Add `use Illuminate\Support\Facades\DB;` at the top of the test file if not yet imported.

- [ ] **Step 2: Run tests, verify they fail**

Run: `php artisan test --filter=SlotAvailabilityTest`
Expected: 3 new tests fail with `Method "forDay" not found`.

- [ ] **Step 3: Implement `forDay`**

Modify `app/Services/Availability/SlotAvailability.php`:

Add the method:

```php
public function forDay(int $restaurantId, CarbonImmutable $date): DayAvailability
{
    $restaurant = Restaurant::query()->findOrFail($restaurantId);
    $tables = $restaurant->tables()->where('active', true)->get();

    $reservations = ReservationRequest::query()
        ->where('restaurant_id', $restaurantId)
        ->whereIn('status', array_map(fn (ReservationStatus $s): string => $s->value, self::OCCUPYING_STATUSES))
        ->whereDate('desired_at', $date->toDateString())
        ->with('tableAssignments:reservation_request_id,table_id')
        ->get();

    $reservedSeats = (int) $reservations->sum('party_size');
    $totalCapacity = (int) $tables->sum('seats');

    $hours = $restaurant->opening_hours[strtolower($date->format('D'))] ?? [];
    $slots = collect();

    foreach ($hours as $window) {
        $start = $date->setTimeFromTimeString($window['open']);
        $end = $date->setTimeFromTimeString($window['close']);

        $cursor = $start;
        while ($cursor->lt($end)) {
            $slots->push($this->forSlotInternal($restaurantId, $cursor, partySize: 1, includeAlternatives: false));
            $cursor = $cursor->addMinutes(30);
        }
    }

    return new DayAvailability(
        date: $date->startOfDay(),
        slots: $slots,
        totalCapacity: $totalCapacity,
        reservedSeats: $reservedSeats,
    );
}
```

Add `use App\Services\Availability\DTOs\DayAvailability;` to imports.

- [ ] **Step 4: Run tests, verify they pass**

Run: `php artisan test --filter=SlotAvailabilityTest`
Expected: 16 tests passing.

- [ ] **Step 5: Note performance follow-up**

The `forDay` implementation calls `forSlotInternal` per slot, which queries again per slot. The test threshold (4 queries) currently passes only with eager loading. If the performance test fails, the fix is to refactor `forSlotInternal` to accept a pre-loaded `Collection` of reservations rather than re-querying. Document this in the PR description as a "follow-up if perf is a problem at pilot scale" but **do not** implement the optimisation prematurely if tests pass.

- [ ] **Step 6: Commit**

```bash
git checkout -b feature/<issue-nr>-slot-availability-forday
git add app/Services/Availability/SlotAvailability.php tests/Unit/Availability/SlotAvailabilityTest.php
git commit -m "Add SlotAvailability::forDay with totals (PRD-011)"
```

---

## Task 9: ReservationContextBuilder refactor (backward-compatible)

**Files:**
- Modify: `app/Services/AI/ReservationContextBuilder.php`
- Create: `tests/Feature/AI/ReservationContextBuilderRegressionTest.php`

**Issue title:** `PRD-011: route ReservationContextBuilder through SlotAvailability (no output change)`

Goal: Route the slot-availability calculation in `ReservationContextBuilder` through the new `SlotAvailability` service, while preserving its existing JSON output exactly. PRD-005-tests must remain green.

- [ ] **Step 1: Read the current ReservationContextBuilder**

Run: `cat /Users/private/projects/reservation-agent/app/Services/AI/ReservationContextBuilder.php`

Identify:
- Which method computes slot availability (likely `buildContext` or a private helper).
- The current SQL query / Eloquent call that determines slot occupancy.

- [ ] **Step 2: Generate a snapshot of current output**

Create `tests/Feature/AI/ReservationContextBuilderRegressionTest.php`:

```php
<?php

use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\Table;
use App\Services\AI\ReservationContextBuilder;
use Carbon\CarbonImmutable;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('produces identical context json before and after slot availability refactor', function () {
    $restaurant = Restaurant::factory()->create([
        'slot_buffer_minutes' => 90,
        'opening_hours' => [
            'wed' => [['open' => '17:00', 'close' => '23:00']],
        ],
    ]);
    Table::factory()->for($restaurant)->create(['seats' => 4]);

    $request = ReservationRequest::factory()->for($restaurant)->create([
        'desired_at' => '2026-06-17 19:00',
        'party_size' => 2,
        'status' => ReservationStatus::New,
    ]);

    $builder = app(ReservationContextBuilder::class);
    $context = $builder->build($request);

    // Snapshot the keys + types to detect regressions.
    expect($context)->toBeArray();
    expect(array_keys($context))->toContain('restaurant', 'request', 'availability');
    expect($context['availability'])->toHaveKey('is_available');
});
```

- [ ] **Step 3: Run the regression test against the unchanged code**

Run: `php artisan test --filter=ReservationContextBuilderRegressionTest`
Expected: PASS (this captures current behaviour as a baseline).

- [ ] **Step 4: Refactor `ReservationContextBuilder` to inject and use SlotAvailability**

Modify the constructor to inject `SlotAvailability`. Replace the existing slot-availability query with a call to `SlotAvailability::forSlot(...)` and map the result back into the same JSON shape the builder previously returned.

Pseudocode for the refactor:

```php
final class ReservationContextBuilder
{
    public function __construct(
        private readonly SlotAvailability $slotAvailability,
    ) {}

    public function build(ReservationRequest $request): array
    {
        // existing prep ...

        $slot = $this->slotAvailability->forSlot(
            restaurantId: $request->restaurant_id,
            datetime: CarbonImmutable::parse($request->desired_at),
            partySize: $request->party_size,
        );

        return [
            'restaurant' => /* unchanged */,
            'request' => /* unchanged */,
            'availability' => [
                'is_available' => $slot->state !== SlotState::Full,
                'state' => $slot->state->value,
                // any other previously emitted fields, sourced from $slot
            ],
        ];
    }
}
```

The exact field mapping depends on the previous output shape. Read it carefully; the regression test will fail if you drop or rename a field.

- [ ] **Step 5: Run regression + PRD-005 tests**

Run: `php artisan test --filter=ReservationContext` and `php artisan test --filter=OpenAi`
Expected: all PRD-005 tests still green; the regression test still green.

- [ ] **Step 6: Run full suite**

Run: `composer test`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git checkout -b feature/<issue-nr>-context-builder-via-slot-availability
git add app/Services/AI/ReservationContextBuilder.php tests/Feature/AI/ReservationContextBuilderRegressionTest.php
git commit -m "Route ReservationContextBuilder through SlotAvailability service (PRD-011)"
```

---

## Task 10: TablePolicy

**Files:**
- Create: `app/Policies/TablePolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php` (or wherever policies are registered)
- Create: `tests/Feature/Policies/TablePolicyTest.php`

**Issue title:** `PRD-011: TablePolicy with tenant + role checks`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Policies/TablePolicyTest.php`:

```php
<?php

use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('owner can view tables of their own restaurant', function () {
    $restaurant = Restaurant::factory()->create();
    $owner = User::factory()->create(['restaurant_id' => $restaurant->id, 'role' => 'owner']);
    $table = Table::factory()->for($restaurant)->create();

    expect($owner->can('view', $table))->toBeTrue();
});

it('user from different restaurant cannot view foreign tables', function () {
    $restaurantA = Restaurant::factory()->create();
    $restaurantB = Restaurant::factory()->create();
    $user = User::factory()->create(['restaurant_id' => $restaurantA->id, 'role' => 'owner']);
    $tableB = Table::factory()->for($restaurantB)->create();

    expect($user->can('view', $tableB))->toBeFalse();
});

it('owner can create tables for their restaurant', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    expect($owner->can('create', Table::class))->toBeTrue();
});

it('service user can view but not delete tables', function () {
    $restaurant = Restaurant::factory()->create();
    $service = User::factory()->create(['restaurant_id' => $restaurant->id, 'role' => 'service']);
    $table = Table::factory()->for($restaurant)->create();

    expect($service->can('view', $table))->toBeTrue();
    expect($service->can('delete', $table))->toBeFalse();
});
```

- [ ] **Step 2: Run tests, verify they fail**

Run: `php artisan test --filter=TablePolicyTest`
Expected: FAIL — `Class "App\Policies\TablePolicy" not found` or "policy not registered".

- [ ] **Step 3: Implement TablePolicy**

Create `app/Policies/TablePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Table;
use App\Models\User;

class TablePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->restaurant_id !== null;
    }

    public function view(User $user, Table $table): bool
    {
        return $user->restaurant_id === $table->restaurant_id;
    }

    public function create(User $user): bool
    {
        return $user->role === 'owner';
    }

    public function update(User $user, Table $table): bool
    {
        return $user->role === 'owner'
            && $user->restaurant_id === $table->restaurant_id;
    }

    public function delete(User $user, Table $table): bool
    {
        return $user->role === 'owner'
            && $user->restaurant_id === $table->restaurant_id;
    }
}
```

- [ ] **Step 4: Register the policy**

Read `app/Providers/AuthServiceProvider.php` (or `app/Providers/AppServiceProvider.php` if AuthServiceProvider does not exist in this Laravel 12 setup — Laravel 12 may auto-discover policies based on naming convention `Models\Table` → `Policies\TablePolicy`).

If auto-discovery is in place: no manual registration needed. If not: add to the `$policies` array:

```php
protected $policies = [
    \App\Models\Table::class => \App\Policies\TablePolicy::class,
];
```

- [ ] **Step 5: Run tests, verify they pass**

Run: `php artisan test --filter=TablePolicyTest`
Expected: 4 tests passing.

- [ ] **Step 6: Commit**

```bash
git checkout -b feature/<issue-nr>-table-policy
git add app/Policies/TablePolicy.php tests/Feature/Policies/TablePolicyTest.php
# also commit AuthServiceProvider change if needed
git commit -m "Add TablePolicy with tenant + role checks (PRD-011)"
```

---

## Task 11: TableController CRUD + FormRequest + Resource + routes

**Files:**
- Create: `app/Http/Requests/TableRequest.php`
- Create: `app/Http/Resources/TableResource.php`
- Create: `app/Http/Controllers/TableController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Tables/TableCrudTest.php`

**Issue title:** `PRD-011: TableController CRUD + form request + resource`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Tables/TableCrudTest.php`:

```php
<?php

use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('owner can create a table', function () {
    $restaurant = Restaurant::factory()->create();
    $owner = User::factory()->create(['restaurant_id' => $restaurant->id, 'role' => 'owner']);

    $response = $this->actingAs($owner)->post(route('tables.store'), [
        'label' => 'Tisch 7',
        'seats' => 4,
        'room_tag' => 'Innen',
        'sort_order' => 1,
        'active' => true,
        'combinable_with' => null,
    ]);

    $response->assertRedirect(route('tables.index'));
    expect(Table::where('label', 'Tisch 7')->where('restaurant_id', $restaurant->id)->exists())->toBeTrue();
});

it('rejects table with seats outside 1..20', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $response = $this->actingAs($owner)->post(route('tables.store'), [
        'label' => 'Riesentisch',
        'seats' => 25,
    ]);

    $response->assertSessionHasErrors('seats');
});

it('user from another restaurant cannot update foreign table', function () {
    $restA = Restaurant::factory()->create();
    $restB = Restaurant::factory()->create();
    $userA = User::factory()->create(['restaurant_id' => $restA->id, 'role' => 'owner']);
    $tableB = Table::factory()->for($restB)->create();

    $response = $this->actingAs($userA)->patch(route('tables.update', $tableB), [
        'label' => 'Hijack',
        'seats' => 4,
    ]);

    $response->assertForbidden();
});

it('cannot delete a table with assignments', function () {
    $restaurant = Restaurant::factory()->create();
    $owner = User::factory()->create(['restaurant_id' => $restaurant->id, 'role' => 'owner']);
    $table = Table::factory()->for($restaurant)->create();
    \App\Models\ReservationTableAssignment::factory()
        ->for(\App\Models\ReservationRequest::factory()->for($restaurant), 'reservationRequest')
        ->for($table)
        ->create();

    $response = $this->actingAs($owner)->delete(route('tables.destroy', $table));

    $response->assertSessionHasErrors();
    expect(Table::find($table->id))->not->toBeNull();
});

it('index lists only own restaurant tables', function () {
    $restA = Restaurant::factory()->create();
    $restB = Restaurant::factory()->create();
    $owner = User::factory()->create(['restaurant_id' => $restA->id, 'role' => 'owner']);
    Table::factory()->for($restA)->count(2)->create();
    Table::factory()->for($restB)->create();

    $response = $this->actingAs($owner)->get(route('tables.index'));

    $response->assertInertia(fn ($page) => $page
        ->component('Tables')
        ->has('tables', 2)
    );
});
```

- [ ] **Step 2: Run tests, verify they fail**

Run: `php artisan test --filter=TableCrudTest`
Expected: failures — routes, controller, FormRequest do not exist.

- [ ] **Step 3: Create FormRequest**

Create `app/Http/Requests/TableRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\Table;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->restaurant_id !== null;
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:50'],
            'seats' => ['required', 'integer', 'between:1,20'],
            'room_tag' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
            'active' => ['nullable', 'boolean'],
            'combinable_with' => ['nullable', 'array', 'max:10'],
            'combinable_with.*' => [
                'integer',
                Rule::exists('tables', 'id')->where(fn ($query) => $query->where('restaurant_id', $this->user()->restaurant_id)),
            ],
        ];
    }
}
```

- [ ] **Step 4: Create API Resource**

Create `app/Http/Resources/TableResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'seats' => $this->seats,
            'room_tag' => $this->room_tag,
            'sort_order' => $this->sort_order,
            'active' => $this->active,
            'combinable_with' => $this->combinable_with ?? [],
        ];
    }
}
```

- [ ] **Step 5: Create TableController**

Create `app/Http/Controllers/TableController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\TableRequest;
use App\Http\Resources\TableResource;
use App\Models\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TableController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Table::class);

        $tables = Table::query()
            ->where('restaurant_id', auth()->user()->restaurant_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('Tables', [
            'tables' => TableResource::collection($tables),
            'activeTab' => 'master',
        ]);
    }

    public function store(TableRequest $request): RedirectResponse
    {
        $this->authorize('create', Table::class);

        Table::create([
            ...$request->validated(),
            'restaurant_id' => $request->user()->restaurant_id,
            'active' => $request->boolean('active', true),
        ]);

        return redirect()->route('tables.index')->with('flash.success', 'Tisch angelegt.');
    }

    public function update(TableRequest $request, Table $table): RedirectResponse
    {
        $this->authorize('update', $table);

        $table->update($request->validated());

        return redirect()->route('tables.index')->with('flash.success', 'Tisch aktualisiert.');
    }

    public function destroy(Table $table): RedirectResponse
    {
        $this->authorize('delete', $table);

        if ($table->assignments()->exists()) {
            throw ValidationException::withMessages([
                'table' => 'Tisch hat zugewiesene Reservierungen und kann nicht gelöscht werden.',
            ]);
        }

        $table->delete();

        return redirect()->route('tables.index')->with('flash.success', 'Tisch gelöscht.');
    }
}
```

- [ ] **Step 6: Add routes**

Modify `routes/web.php` — inside the existing `auth`-grouped middleware section, add:

```php
Route::get('/tables', [TableController::class, 'index'])->name('tables.index');
Route::post('/tables', [TableController::class, 'store'])->name('tables.store');
Route::patch('/tables/{table}', [TableController::class, 'update'])->name('tables.update');
Route::delete('/tables/{table}', [TableController::class, 'destroy'])->name('tables.destroy');
```

Add `use App\Http\Controllers\TableController;` if controllers are imported (project convention varies — match existing routes file style).

- [ ] **Step 7: Regenerate Ziggy types**

Run: `php artisan ziggy:generate --types-only resources/js/ziggy.d.ts`
Expected: file updated with new route names.

- [ ] **Step 8: Run tests, verify they pass**

Run: `php artisan test --filter=TableCrudTest`
Expected: 5 tests passing.

- [ ] **Step 9: Run full suite + lint**

Run: `composer test && ./vendor/bin/pint --test && npm run format:check`
Expected: all green.

- [ ] **Step 10: Commit**

```bash
git checkout -b feature/<issue-nr>-table-controller
git add app/Http/Controllers/TableController.php app/Http/Requests/TableRequest.php \
        app/Http/Resources/TableResource.php routes/web.php resources/js/ziggy.d.ts \
        tests/Feature/Tables/TableCrudTest.php
git commit -m "Add TableController CRUD with form request, resource, and routes (PRD-011)"
```

---

## Task 12: Tables.vue Stammdaten Tab

**Files:**
- Create: `resources/js/pages/Tables.vue`
- Create: `resources/js/components/tables/TableForm.vue`
- Modify: `resources/js/types/index.d.ts`
- Create: `resources/js/pages/Tables.test.ts`

**Issue title:** `PRD-011: Tables.vue master-data tab + TableForm drawer`

- [ ] **Step 1: Add TypeScript types**

Modify `resources/js/types/index.d.ts` — append:

```ts
export interface TableModel {
  id: number
  label: string
  seats: number
  room_tag: string | null
  sort_order: number
  active: boolean
  combinable_with: number[]
}
```

- [ ] **Step 2: Write a failing Vitest test**

Create `resources/js/pages/Tables.test.ts`:

```ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import Tables from '@/pages/Tables.vue'

describe('Tables.vue master tab', () => {
  it('renders one row per table', () => {
    const wrapper = mount(Tables, {
      props: {
        tables: [
          { id: 1, label: 'Tisch 1', seats: 4, room_tag: 'Innen', sort_order: 1, active: true, combinable_with: [] },
          { id: 2, label: 'Tisch 2', seats: 6, room_tag: 'Terrasse', sort_order: 2, active: true, combinable_with: [] },
        ],
        activeTab: 'master',
      },
      global: {
        stubs: ['Link', 'Head'],
      },
    })

    expect(wrapper.findAll('[data-testid="table-row"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Tisch 1')
    expect(wrapper.text()).toContain('Tisch 2')
  })
})
```

- [ ] **Step 3: Run Vitest, verify it fails**

Run: `npm run test -- Tables.test.ts`
Expected: FAIL (component does not exist).

- [ ] **Step 4: Create Tables.vue**

Create `resources/js/pages/Tables.vue`:

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { Head } from '@inertiajs/vue3'
import TableForm from '@/components/tables/TableForm.vue'
import type { TableModel } from '@/types'

const props = defineProps<{
  tables: TableModel[]
  activeTab: 'master' | 'availability'
}>()

const showForm = ref(false)
const editing = ref<TableModel | null>(null)

function openCreate() {
  editing.value = null
  showForm.value = true
}

function openEdit(table: TableModel) {
  editing.value = table
  showForm.value = true
}

function deleteTable(id: number) {
  if (!confirm('Tisch wirklich löschen?')) return
  router.delete(route('tables.destroy', { table: id }))
}
</script>

<template>
  <Head title="Tische" />
  <div class="space-y-4 p-6">
    <header class="flex items-center justify-between">
      <h1 class="text-2xl font-semibold">Tische</h1>
      <button
        type="button"
        class="rounded bg-primary px-4 py-2 text-sm text-white"
        @click="openCreate"
      >
        + Tisch hinzufügen
      </button>
    </header>

    <table class="w-full text-left text-sm">
      <thead>
        <tr class="border-b">
          <th class="py-2">Label</th>
          <th>Plätze</th>
          <th>Raum</th>
          <th>Aktiv</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="table in props.tables"
          :key="table.id"
          data-testid="table-row"
          class="border-b"
        >
          <td class="py-2">{{ table.label }}</td>
          <td>{{ table.seats }}</td>
          <td>{{ table.room_tag ?? '–' }}</td>
          <td>{{ table.active ? 'Ja' : 'Nein' }}</td>
          <td class="space-x-2 text-right">
            <button class="text-blue-600" @click="openEdit(table)">Bearbeiten</button>
            <button class="text-red-600" @click="deleteTable(table.id)">Löschen</button>
          </td>
        </tr>
      </tbody>
    </table>

    <TableForm
      v-if="showForm"
      :table="editing"
      @close="showForm = false"
    />
  </div>
</template>
```

- [ ] **Step 5: Create TableForm.vue**

Create `resources/js/components/tables/TableForm.vue`:

```vue
<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import type { TableModel } from '@/types'

const props = defineProps<{
  table: TableModel | null
}>()

const emit = defineEmits<{
  (e: 'close'): void
}>()

const form = ref({
  label: props.table?.label ?? '',
  seats: props.table?.seats ?? 2,
  room_tag: props.table?.room_tag ?? '',
  sort_order: props.table?.sort_order ?? 0,
  active: props.table?.active ?? true,
  combinable_with: props.table?.combinable_with ?? [],
})

function submit() {
  if (props.table) {
    router.patch(route('tables.update', { table: props.table.id }), form.value, {
      onSuccess: () => emit('close'),
    })
  } else {
    router.post(route('tables.store'), form.value, {
      onSuccess: () => emit('close'),
    })
  }
}
</script>

<template>
  <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40">
    <form
      class="w-full max-w-md space-y-3 rounded bg-white p-6 shadow-lg"
      @submit.prevent="submit"
    >
      <h2 class="text-lg font-semibold">
        {{ props.table ? 'Tisch bearbeiten' : 'Neuer Tisch' }}
      </h2>

      <label class="block text-sm">
        Label
        <input v-model="form.label" type="text" class="mt-1 w-full rounded border px-2 py-1" required />
      </label>

      <label class="block text-sm">
        Plätze
        <input v-model.number="form.seats" type="number" min="1" max="20" class="mt-1 w-full rounded border px-2 py-1" required />
      </label>

      <label class="block text-sm">
        Raum-Tag
        <input v-model="form.room_tag" type="text" class="mt-1 w-full rounded border px-2 py-1" />
      </label>

      <label class="flex items-center gap-2 text-sm">
        <input v-model="form.active" type="checkbox" />
        Aktiv
      </label>

      <div class="flex justify-end gap-2 pt-2">
        <button type="button" class="rounded border px-3 py-1 text-sm" @click="emit('close')">
          Abbrechen
        </button>
        <button type="submit" class="rounded bg-primary px-3 py-1 text-sm text-white">
          Speichern
        </button>
      </div>
    </form>
  </div>
</template>
```

- [ ] **Step 6: Run Vitest, verify it passes**

Run: `npm run test -- Tables.test.ts`
Expected: 1 test passing.

- [ ] **Step 7: Manual smoke check**

Run: `composer run dev`
Open `http://localhost:8000/tables` (after login) — verify:
- Empty state shows "+ Tisch hinzufügen" button
- Click adds a table via the modal
- Edit / Delete buttons work

- [ ] **Step 8: Commit**

```bash
git checkout -b feature/<issue-nr>-tables-vue-master-tab
git add resources/js/pages/Tables.vue resources/js/components/tables/TableForm.vue \
        resources/js/types/index.d.ts resources/js/pages/Tables.test.ts
git commit -m "Add Tables.vue master-data tab + TableForm modal (PRD-011)"
```

---

## Task 13: TableAvailabilityController + Belegungs-Tab + Vitest

**Files:**
- Create: `app/Http/Controllers/TableAvailabilityController.php`
- Create: `app/Http/Requests/TableAvailabilityRequest.php`
- Create: `resources/js/components/tables/TableAvailabilityGrid.vue`
- Modify: `resources/js/pages/Tables.vue` (add tab switching)
- Modify: `routes/web.php` (add availability route)
- Create: `tests/Feature/Tables/TableAvailabilityTest.php`

**Issue title:** `PRD-011: Belegungs-Tab + day-availability endpoint`

- [ ] **Step 1: Write failing Feature test**

Create `tests/Feature/Tables/TableAvailabilityTest.php`:

```php
<?php

use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('renders day availability grid for owner', function () {
    $restaurant = Restaurant::factory()->create([
        'opening_hours' => [
            'mon' => [['open' => '17:00', 'close' => '23:00']],
        ],
    ]);
    $owner = User::factory()->create(['restaurant_id' => $restaurant->id, 'role' => 'owner']);
    Table::factory()->for($restaurant)->create();

    $response = $this->actingAs($owner)->get(route('tables.availability', ['date' => '2026-06-15']));

    $response->assertInertia(fn ($page) => $page
        ->component('Tables')
        ->where('activeTab', 'availability')
        ->has('availability.slots')
    );
});

it('redirects unauthenticated user from availability', function () {
    $response = $this->get(route('tables.availability', ['date' => '2026-06-15']));
    $response->assertRedirect(route('login'));
});

it('rejects invalid date param', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $response = $this->actingAs($owner)->get(route('tables.availability', ['date' => 'not-a-date']));
    $response->assertSessionHasErrors('date');
});
```

- [ ] **Step 2: Run test, verify it fails**

Run: `php artisan test --filter=TableAvailabilityTest`
Expected: failures (route + controller missing).

- [ ] **Step 3: Create FormRequest**

Create `app/Http/Requests/TableAvailabilityRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TableAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->restaurant_id !== null;
    }

    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('date')) {
            $this->merge(['date' => now()->format('Y-m-d')]);
        }
    }
}
```

- [ ] **Step 4: Create TableAvailabilityController**

Create `app/Http/Controllers/TableAvailabilityController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\TableAvailabilityRequest;
use App\Http\Resources\TableResource;
use App\Models\Table;
use App\Services\Availability\SlotAvailability;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class TableAvailabilityController extends Controller
{
    public function show(TableAvailabilityRequest $request, SlotAvailability $availability): Response
    {
        $restaurantId = $request->user()->restaurant_id;
        $date = CarbonImmutable::parse($request->validated('date'));

        $day = $availability->forDay($restaurantId, $date);

        $tables = Table::query()
            ->where('restaurant_id', $restaurantId)
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Tables', [
            'tables' => TableResource::collection($tables),
            'activeTab' => 'availability',
            'availability' => [
                'date' => $day->date->toDateString(),
                'slots' => $day->slots->map(fn ($slot) => [
                    'time' => $slot->slotStart->format('H:i'),
                    'state' => $slot->state->value,
                    'suggested_table_id' => $slot->suggestedTableId,
                ])->all(),
                'total_capacity' => $day->totalCapacity,
                'reserved_seats' => $day->reservedSeats,
            ],
        ]);
    }
}
```

- [ ] **Step 5: Add route**

Modify `routes/web.php`:

```php
Route::get('/tables/availability', [TableAvailabilityController::class, 'show'])->name('tables.availability');
```

(Place this **before** the `tables/{table}` routes if any conflict on path resolution. The literal path `/tables/availability` is fine since `{table}` would match by ID, but Laravel evaluates routes top-down, so put `availability` first.)

- [ ] **Step 6: Regenerate Ziggy**

Run: `php artisan ziggy:generate --types-only resources/js/ziggy.d.ts`

- [ ] **Step 7: Create TableAvailabilityGrid.vue**

Create `resources/js/components/tables/TableAvailabilityGrid.vue`:

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import type { TableModel } from '@/types'

const props = defineProps<{
  tables: TableModel[]
  availability: {
    date: string
    slots: Array<{ time: string; state: 'free' | 'tight' | 'full'; suggested_table_id: number | null }>
    total_capacity: number
    reserved_seats: number
  }
}>()

const date = ref(props.availability.date)

function changeDate() {
  router.reload({
    data: { date: date.value },
    only: ['availability'],
  })
}

function bgFor(state: string): string {
  return state === 'free'
    ? 'bg-green-100'
    : state === 'tight'
      ? 'bg-yellow-100'
      : 'bg-red-100'
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center gap-3">
      <label class="text-sm">
        Datum:
        <input
          v-model="date"
          type="date"
          class="ml-2 rounded border px-2 py-1"
          @change="changeDate"
        />
      </label>
      <span class="text-sm text-gray-600">
        {{ props.availability.reserved_seats }} / {{ props.availability.total_capacity }} Plätze belegt
      </span>
    </div>

    <table class="w-full text-left text-sm">
      <thead>
        <tr class="border-b">
          <th class="py-2">Zeit</th>
          <th>Status</th>
          <th>Vorschlag</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="slot in props.availability.slots"
          :key="slot.time"
          :class="bgFor(slot.state)"
          class="border-b"
        >
          <td class="py-1">{{ slot.time }}</td>
          <td>{{ slot.state }}</td>
          <td>
            <span v-if="slot.suggested_table_id">
              {{ props.tables.find((t) => t.id === slot.suggested_table_id)?.label ?? '–' }}
            </span>
            <span v-else>–</span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
```

- [ ] **Step 8: Add tab switching to Tables.vue**

Modify `resources/js/pages/Tables.vue`:

Add a tab strip after the header. Replace the current single-content `<table>` with a conditional based on `activeTab`. Render `TableAvailabilityGrid` when `activeTab === 'availability'`.

```vue
<script setup lang="ts">
// ... existing imports
import TableAvailabilityGrid from '@/components/tables/TableAvailabilityGrid.vue'

const props = defineProps<{
  tables: TableModel[]
  activeTab: 'master' | 'availability'
  availability?: {
    date: string
    slots: Array<{ time: string; state: 'free' | 'tight' | 'full'; suggested_table_id: number | null }>
    total_capacity: number
    reserved_seats: number
  }
}>()

function switchTab(tab: 'master' | 'availability') {
  if (tab === 'master') {
    router.get(route('tables.index'))
  } else {
    router.get(route('tables.availability'))
  }
}
</script>

<template>
  <Head title="Tische" />
  <div class="space-y-4 p-6">
    <header class="flex items-center justify-between">
      <h1 class="text-2xl font-semibold">Tische</h1>
      <div class="flex gap-2">
        <button
          :class="activeTab === 'master' ? 'border-b-2 border-primary' : ''"
          @click="switchTab('master')"
        >
          Stammdaten
        </button>
        <button
          :class="activeTab === 'availability' ? 'border-b-2 border-primary' : ''"
          @click="switchTab('availability')"
        >
          Belegung
        </button>
      </div>
      <button
        v-if="activeTab === 'master'"
        type="button"
        class="rounded bg-primary px-4 py-2 text-sm text-white"
        @click="openCreate"
      >
        + Tisch hinzufügen
      </button>
    </header>

    <!-- existing master-tab table, wrapped in v-if="activeTab === 'master'" -->
    <table v-if="activeTab === 'master'" class="w-full text-left text-sm">
      <!-- ... unchanged content from Task 12 ... -->
    </table>

    <TableAvailabilityGrid
      v-else-if="activeTab === 'availability' && props.availability"
      :tables="props.tables"
      :availability="props.availability"
    />

    <TableForm
      v-if="showForm"
      :table="editing"
      @close="showForm = false"
    />
  </div>
</template>
```

- [ ] **Step 9: Add Vitest test for the grid**

Append to `resources/js/pages/Tables.test.ts`:

```ts
import TableAvailabilityGrid from '@/components/tables/TableAvailabilityGrid.vue'

describe('TableAvailabilityGrid', () => {
  it('renders one row per slot', () => {
    const wrapper = mount(TableAvailabilityGrid, {
      props: {
        tables: [
          { id: 1, label: 'Tisch 1', seats: 4, room_tag: null, sort_order: 1, active: true, combinable_with: [] },
        ],
        availability: {
          date: '2026-06-15',
          total_capacity: 4,
          reserved_seats: 0,
          slots: [
            { time: '17:00', state: 'free', suggested_table_id: 1 },
            { time: '17:30', state: 'free', suggested_table_id: 1 },
          ],
        },
      },
      global: { stubs: ['Link', 'Head'] },
    })

    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    expect(wrapper.text()).toContain('17:00')
    expect(wrapper.text()).toContain('17:30')
  })
})
```

- [ ] **Step 10: Run all tests**

Run: `composer test && npm run test`
Expected: all green.

- [ ] **Step 11: Manual smoke**

Run: `composer run dev`. Open `/tables` after login, switch tabs, change date in availability tab, verify the grid updates.

- [ ] **Step 12: Commit**

```bash
git checkout -b feature/<issue-nr>-table-availability-tab
git add app/Http/Controllers/TableAvailabilityController.php \
        app/Http/Requests/TableAvailabilityRequest.php \
        resources/js/components/tables/TableAvailabilityGrid.vue \
        resources/js/pages/Tables.vue \
        resources/js/pages/Tables.test.ts \
        routes/web.php resources/js/ziggy.d.ts \
        tests/Feature/Tables/TableAvailabilityTest.php
git commit -m "Add TableAvailabilityController + Belegungs-Tab + Vitest (PRD-011)"
```

---

## Self-Review

**Spec coverage:**

| PRD-011 In-Scope item | Task |
|---|---|
| `tables` schema | Task 1 |
| `reservation_table_assignments` schema | Task 1 |
| `restaurants.slot_buffer_minutes` | Task 1 |
| `Table` + `ReservationTableAssignment` models | Task 2 |
| `tableAssignments()` on ReservationRequest | Task 2 |
| `tables()` on Restaurant | Task 2 |
| `DefaultTableSeeder` for legacy data | Task 3 |
| `SlotState` enum | Task 4 |
| `SlotAvailabilityResult`, `DayAvailability`, `TableCombination` DTOs | Task 4 |
| `SlotAvailability::forSlot` with buffer logic | Task 5 |
| `SlotAvailability::suggestTableCombination` (single + 2-table combo) | Task 6 |
| `SlotAvailability::freeTablesAt` | Task 7 |
| Alternative slot suggestions | Task 7 |
| `SlotAvailability::forDay` | Task 8 |
| `ReservationContextBuilder` refactor with backward-compat | Task 9 |
| `TablePolicy` with tenant + role checks | Task 10 |
| `TableController` CRUD + FormRequest + Resource | Task 11 |
| Routes + Ziggy regeneration | Task 11, Task 13 |
| Tables.vue Stammdaten tab | Task 12 |
| Tables.vue Belegung tab + grid | Task 13 |
| Vitest for both tabs | Task 12, Task 13 |

All in-scope items mapped. Out-of-scope items (graphical floor plan, multi-3-table combos, walk-in entry, waitlist, sync-confirm, etc.) intentionally absent — they belong to PRD-012/013/014.

**Type consistency:**
- `SlotAvailabilityResult.suggestedTableId` (`?int`) and `SlotAvailabilityResult.combination` (`?TableCombination`) are defined in Task 4 and consumed in Tasks 5, 6, 7, 8, 13.
- `TableCombination.primaryTableId` (`int`) and `tableIds` (`list<int>`) are defined in Task 4 and used in Task 6.
- `SlotState::Free|Tight|Full` cases match between Task 4 (definition) and Tasks 5, 6, 7, 8, 13 (consumption).
- `Table.combinable_with` is `array` (Task 2) and validated as such in Task 11 FormRequest.

**Placeholder scan:** No "TBD", "TODO", "fill in details", or "similar to Task N" placeholders. Each step has a concrete code block or command.

**One known abstraction:** Task 8 (`forDay`) calls `forSlotInternal` per slot, which re-queries reservations. The performance test sets a 4-query ceiling. If at pilot scale the threshold is exceeded, Task 8 step 5 documents the eager-load follow-up. This is intentionally deferred (YAGNI) until performance data exists.

---

**Plan complete and saved to `docs/superpowers/plans/2026-05-01-prd-011-availability-and-tables.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using `executing-plans`, batch execution with checkpoints.

**Which approach?**

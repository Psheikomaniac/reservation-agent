# PRD-011: Verfügbarkeits-Modell + Tisch-Liste

**Produkt:** reservation-agent
**Version:** V3.0
**Priorität:** P0 – Fundament für PRD-012, PRD-013, PRD-014
**Entwicklungsphase:** V3-Phase 1

---

## Problem

In V1.0 + V2.0 berechnet `ReservationContextBuilder` (PRD-005) Verfügbarkeit **pro eingehender Anfrage**: einmalig, im Hintergrund, im Job. Die Antwort ist „Slot frei oder nicht" auf Basis einer Personenzahl-Summe pro Slot. Kein Konzept von Tischen, kein Konzept von Pufferzeit zwischen Reservierungen am selben Tisch, keine interaktive Sicht.

Das reicht nicht für V3:

1. **Telefon-Reservierungen brauchen Live-Antwort** – der Inhaber tippt am Hörer Datum/Uhrzeit/Personen ein und braucht in <1 s eine ehrliche Antwort. Per-Job-Berechnung ist nicht interaktiv.
2. **Doppelbuchungs-Pain entsteht aus Tisch-Belegung, nicht aus Platz-Summen** – ein Slot mit 40 freien Plätzen kann für eine 8er-Gruppe trotzdem belegt sein, wenn kein 8er-Tisch und keine zwei zusammenschiebbaren Tische frei sind. Die V1-Logik weiß davon nichts.
3. **Pufferzeit ist real** – wenn um 18 Uhr eine 4er-Gruppe an Tisch 7 sitzt, ist Tisch 7 nicht um 19 Uhr für die nächste Gruppe frei, sondern frühestens um 19:30. V1 ignoriert das.
4. **Belegungs-Sicht fehlt komplett** – der Owner sieht heute keine Tagesübersicht „welche Tische sind in welchen Slots belegt". Tisch-Stammdaten existieren gar nicht.

Das V3-Brainstorming am 2026-05-01 hat Verfügbarkeit als Kern-Wertversprechen identifiziert, nicht Eingabe-Geschwindigkeit. Siehe [`docs/superpowers/specs/2026-05-01-v3-scope-sharpened-design.md`](superpowers/specs/2026-05-01-v3-scope-sharpened-design.md).

## Ziel

Ein zentraler `SlotAvailability`-Service liefert für ein Restaurant + Datum eine vollständige Tagesbelegung in 30-Minuten-Slots. Pro Slot: gesamte Kapazität, belegte Plätze, freie Plätze, freie Tische, mögliche Tisch-Kombinationen für eine gewünschte Personenzahl. Dieselbe Logik bedient PRD-012 (Telefon-Form), PRD-013 (Warteliste-Banner), PRD-014 (Sync-Web-Confirm) und intern den `ReservationContextBuilder`.

Tisch-Stammdaten werden im Restaurant-Setting verwaltet (CRUD). Die Belegungs-Sicht ist eine eigene Seite im Dashboard mit Datum-Picker und Tabellen-Layout (Tische × Slots).

---

## Scope V3.0

### In Scope

- Neue Tabelle `tables` (Tisch-Stammdaten pro Restaurant)
- Neue Tabelle `reservation_table_assignments` (welche Reservierung belegt welche Tische)
- Erweiterung `restaurants.slot_buffer_minutes` (Default 90, konfigurierbar)
- Service `SlotAvailability` mit Methoden `forDay`, `forSlot`, `freeTablesAt`, `suggestTableCombination`
- `ReservationContextBuilder` (PRD-005) wird intern auf `SlotAvailability` umgestellt – Output-Format unverändert
- Inertia-Page `Tables.vue` mit zwei Tabs: „Stammdaten" (CRUD) + „Belegung" (Tages-Sicht)
- Migrations-Seed: pro Bestands-Restaurant ein „Default-Tisch" mit `seats = max(party_size aus historischen Reservierungen) + 4`, damit V1+V2-Daten ohne manuelle Pflege weiter funktionieren
- Auto-Tisch-Vorschlag bei manueller Zuweisung (kleinster passender Einzeltisch oder kleinste Kombi aus 2 Tischen)

### Out of Scope

- **Grafischer Tischplan** (mit Drag-and-Drop, Raum-Hintergrund) – „später", weiterhin offen
- **Auto-Tisch-Zuweisung beim Reservierungs-Eingang** ohne menschliche Bestätigung – kommt in V3-014 (Sync-Web-Confirm) als opt-in mit Hard-Gates, nicht hier
- **Tisch-Kombinationen ≥ 3 Tische** – V1.0 von PRD-011 unterstützt nur Einzel + 2-Tisch-Kombi. 3+ Kombinationen kommen in V4 wenn Pilot-Daten zeigen, dass das ein realer Pain ist
- **Mehrere parallele Service-Wechsel pro Tag** (Tisch ist um 18 Uhr für Slot A reserviert, um 21 Uhr für Slot B, dazwischen 2h Pufferzeit) – wird unterstützt, aber **kein** dediziertes „Slot-Wechsel"-UI, nur die normale Liste
- **Walk-in-Erfassung** – PRD-012
- **Warteliste-Banner** – PRD-013
- **Web-Sofort-Bestätigung** – PRD-014

---

## Technische Anforderungen

### Datenmodell

**Neue Tabelle `tables`:**

| Spalte         | Typ                 | Notiz                                          |
|----------------|---------------------|------------------------------------------------|
| id             | bigint PK           |                                                |
| restaurant_id  | bigint FK           | indexiert                                      |
| label          | string              | „Tisch 7", „Terrasse 3"                        |
| seats          | unsignedTinyInteger | 1–20, validiert in FormRequest                 |
| room_tag       | string null         | „Innen", „Terrasse", „Raucherbereich"          |
| sort_order     | unsignedSmallInteger| Anzeige-Reihenfolge in Liste                   |
| active         | boolean             | inaktive Tische zählen nicht in Verfügbarkeit  |
| combinable_with| json null           | Liste anderer `table.id`, mit denen kombinierbar (für 2er-Kombi-Logik) |
| created_at, updated_at | timestamps  |                                                |

Index: `(restaurant_id, active, sort_order)`.

**Neue Tabelle `reservation_table_assignments`:**

| Spalte                  | Typ           | Notiz                                          |
|-------------------------|---------------|------------------------------------------------|
| id                      | bigint PK     |                                                |
| reservation_request_id  | bigint FK     | indexiert, `cascadeOnDelete`                   |
| table_id                | bigint FK     | indexiert, `restrictOnDelete` (kein Tisch löschen, an dem noch Reservierungen hängen) |
| assigned_at             | datetime      |                                                |
| assigned_by_user_id     | bigint FK null| null = automatischer Vorschlag                 |

Composite-Unique: `(reservation_request_id, table_id)` – ein Tisch wird einer Reservierung nur einmal zugeordnet.

**Erweiterung `restaurants`:**

| Spalte                  | Typ                 | Notiz                                          |
|-------------------------|---------------------|------------------------------------------------|
| slot_buffer_minutes     | unsignedSmallInteger| Default 90, Range 0–240                        |

### SlotAvailability-Service

`app/Services/Availability/SlotAvailability.php`:

```php
final class SlotAvailability
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Liefert die Tages-Belegung in 30-Minuten-Slots.
     * Eager-loaded: alle Reservierungen + Assignments + Tische des Restaurants für den Tag.
     */
    public function forDay(int $restaurantId, CarbonImmutable $date): DayAvailability
    {
        // 1. Öffnungszeiten des Restaurants laden, Slots generieren (30-min-Raster)
        // 2. Alle Reservierungen des Tages plus Pufferzeit-Window laden (eine Query)
        // 3. Pro Slot: belegte Tische berechnen
        // 4. Pro Slot: freie Tische, Gesamt-Kapazität, freie Plätze
    }

    public function forSlot(int $restaurantId, CarbonImmutable $datetime, int $partySize): SlotAvailabilityResult
    {
        // Ein einzelner Slot, optimiert für interaktive Anfragen aus PRD-012/PRD-014.
        // Liefert: free | tight | full + Liste freier Tische bzw. Vorschlags-Kombi
    }

    public function freeTablesAt(int $restaurantId, CarbonImmutable $datetime): Collection
    {
        // Liste der Tisch-IDs, die im Slot inkl. Pufferzeit nicht belegt sind.
    }

    public function suggestTableCombination(int $restaurantId, CarbonImmutable $datetime, int $partySize): ?TableCombination
    {
        // Kleinster passender Einzeltisch ODER kleinste 2-Tisch-Kombi aus combinable_with.
        // Liefert null, wenn keine Kombi passt.
    }
}
```

**Pufferzeit-Logik:**

Ein Tisch ist im Slot `[t_start, t_end]` belegt, wenn eine Reservierung an demselben Tisch existiert mit:

```
reservation_start - buffer ≤ t_end UND reservation_start + slot_duration + buffer > t_start
```

Slot-Dauer ist ein Restaurant-Setting (Default 90 min, später kombinierbar mit `slot_buffer_minutes`). Pro Slot wird die Pufferzeit symmetrisch addiert: davor und danach.

**DTOs + Enum:**

`app/Enums/SlotState.php`:

```php
enum SlotState: string
{
    case Free = 'free';   // Slot ist verfügbar, mindestens ein passender Tisch frei
    case Tight = 'tight'; // Slot hat passenden Tisch, aber ≤ 25 % der Tagskapazität frei
    case Full = 'full';   // Kein passender Tisch / keine Tisch-Kombi für die Personenzahl
}
```

`app/Services/Availability/DTOs/SlotAvailabilityResult.php`:

```php
final readonly class SlotAvailabilityResult
{
    public function __construct(
        public CarbonImmutable $slotStart,
        public SlotState $state,
        public ?int $suggestedTableId,         // Tisch, der für die Personenzahl vorgeschlagen wird
        public ?TableCombination $combination, // Wenn Kombi nötig, sonst null
        public Collection $alternativeSlots,   // Bei state = Full: nächste 3 freie Slots am selben Tag
    ) {}
}
```

`app/Services/Availability/DTOs/DayAvailability.php`:

```php
final readonly class DayAvailability
{
    public function __construct(
        public CarbonImmutable $date,
        public Collection $slots,        // Collection<SlotAvailabilityResult>
        public int $totalCapacity,       // Summe aller Sitzplätze aktiver Tische
        public int $reservedSeats,       // Belegte Plätze über alle Slots
    ) {}
}
```

`app/Services/Availability/DTOs/TableCombination.php`:

```php
final readonly class TableCombination
{
    public function __construct(
        public int $primaryTableId,        // Haupt-Tisch (für Auto-Zuweisung in PRD-012)
        public array $tableIds,            // Alle Tisch-IDs der Kombi (1 oder 2 Einträge)
        public int $totalSeats,
    ) {}
}
```

Alle DTOs und der Enum werden von PRD-012, PRD-013 und PRD-014 ohne Änderung wiederverwendet.

### Inertia-Page `Tables.vue`

`resources/js/pages/Tables.vue` mit zwei Tabs:

**Tab „Stammdaten":**

- Tabelle aller Tische des Restaurants (sortiert nach `sort_order`, dann `id`)
- Spalten: Label, Plätze, Raum-Tag, Aktiv (Toggle), Aktionen (Bearbeiten / Löschen)
- „+ Tisch hinzufügen"-Dialog mit Label, Plätze, Raum-Tag, Combinable-with-Multi-Select
- Bearbeiten/Löschen via Drawer, kein Inline-Edit

**Tab „Belegung":**

- Datum-Picker (Default: heute)
- Tabellen-Layout: Zeilen = Tische (nach `sort_order`), Spalten = 30-Minuten-Slots der Öffnungszeit
- Zellen-Farbe: grün = frei, gelb = Pufferzeit-Schatten, rot = belegt
- Klick auf belegte Zelle: Drawer mit Reservierungs-Details
- Header zeigt Tages-Stats: „14 Reservierungen, 38 belegte Plätze von 60"

Keine Drag-and-Drop-Interaktion in V3.0 – das wäre der „grafische Tischplan", der explizit out of scope ist.

### Routing

```php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/tables', [TableController::class, 'index'])->name('tables.index');
    Route::post('/tables', [TableController::class, 'store'])->name('tables.store');
    Route::patch('/tables/{table}', [TableController::class, 'update'])->name('tables.update');
    Route::delete('/tables/{table}', [TableController::class, 'destroy'])->name('tables.destroy');

    Route::get('/tables/availability', [TableAvailabilityController::class, 'show'])->name('tables.availability');
});
```

`TableController` arbeitet mit `TableRequest` (FormRequest), `TableAvailabilityController` mit `TableAvailabilityRequest` (Datums-Validierung).

### Policies

`TablePolicy`:

- `viewAny` – User darf nur Tische seines Restaurants sehen
- `update`/`delete` – nur Owner-Rolle
- `view`/`create` – auch Service-Rolle (in V3 noch nicht ausdifferenziert, aber Hook eingebaut)

`reservation_table_assignments` haben keine eigene Policy – die Berechtigung wird über die zugehörige `ReservationRequest` geregelt (`ReservationRequestPolicy`).

### Bestandsdaten-Migration

Neuer Seeder `DefaultTableSeeder` (läuft als Teil der Migration für Bestands-Restaurants):

```php
foreach (Restaurant::all() as $restaurant) {
    $maxParty = ReservationRequest::where('restaurant_id', $restaurant->id)
        ->max('party_size') ?? 4;

    Table::create([
        'restaurant_id' => $restaurant->id,
        'label' => 'Tisch 1',
        'seats' => $maxParty + 4,
        'sort_order' => 1,
        'active' => true,
    ]);
}
```

**Wichtig:** Bestehende Reservierungen bekommen **keinen** retroaktiven Eintrag in `reservation_table_assignments`. Sie laufen mit „kein Tisch zugewiesen". Die `SlotAvailability`-Logik behandelt das als „Reservierung blockiert die Default-Kapazität, aber keinen spezifischen Tisch" – Pufferzeit greift dann pro Slot statt pro Tisch.

### `ReservationContextBuilder`-Anpassung

Der bestehende `ReservationContextBuilder` (PRD-005) ruft intern statt eigener Slot-Query nun `SlotAvailability::forSlot(...)` auf. Output-Format (das KI-Prompt-JSON) bleibt unverändert – der Generator-Pfad und PRD-005-Tests müssen ohne Anpassung weiter funktionieren.

---

## Akzeptanzkriterien

- [ ] Migrations für `tables`, `reservation_table_assignments`, `restaurants.slot_buffer_minutes` laufen vorwärts und rückwärts
- [ ] `DefaultTableSeeder` legt für jedes Bestands-Restaurant genau einen Default-Tisch an
- [ ] `SlotAvailability::forDay` liefert für ein Restaurant + Datum die korrekten Slots mit Belegung in einer Query plus PHP-Postprocessing (kein N+1)
- [ ] `SlotAvailability::forSlot` antwortet in < 100 ms bei realistischer Datenmenge (50 Tische × 100 Reservierungen / Tag)
- [ ] Pufferzeit wird symmetrisch berücksichtigt – Tisch ist im Slot 19 Uhr belegt, wenn vorherige Reservierung um 17:30 angefangen hat (90 min Slot + 90 min Buffer)
- [ ] Tisch-CRUD im UI: Anlegen, Editieren, Aktiv-Toggle, Löschen mit Bestätigungs-Dialog
- [ ] Löschen scheitert kontrolliert mit verständlicher Fehlermeldung, wenn Reservierungen am Tisch hängen
- [ ] Belegungs-Sicht zeigt Tabellen-Layout mit Tag-Stats im Header
- [ ] `ReservationContextBuilder` produziert weiterhin identisches Output-JSON für PRD-005-Tests (Backward-Compat)
- [ ] Bestehende V1+V2-Tests bleiben grün
- [ ] `./vendor/bin/pint --test`, `npm run lint`, `npm run format:check` ohne Findings
- [ ] `php artisan ziggy:generate --types-only resources/js/ziggy.d.ts` wurde nach neuen Routes ausgeführt

---

## Tests

**Unit (`tests/Unit/Availability/`)**

- `SlotAvailabilityTest::it_returns_full_day_slots_within_opening_hours`
- `SlotAvailabilityTest::it_excludes_inactive_tables_from_capacity`
- `SlotAvailabilityTest::it_marks_table_as_busy_during_buffer_window`
- `SlotAvailabilityTest::it_finds_smallest_fitting_single_table`
- `SlotAvailabilityTest::it_finds_two_table_combination_when_no_single_table_fits`
- `SlotAvailabilityTest::it_returns_full_when_no_combination_fits`
- `SlotAvailabilityTest::it_excludes_combinations_that_share_a_busy_table`
- `SlotAvailabilityTest::it_treats_legacy_reservations_without_assignment_as_capacity_block`

**Feature (`tests/Feature/Tables/`)**

- `TableCrudTest::owner_can_create_table`
- `TableCrudTest::service_user_cannot_delete_table`
- `TableCrudTest::user_cannot_see_tables_of_other_restaurants` (Tenant-Scope)
- `TableCrudTest::deleting_table_with_assignments_fails_with_message`
- `TableAvailabilityTest::it_renders_day_availability_for_owner`
- `TableAvailabilityTest::it_redirects_unauthenticated_user`
- `ReservationContextBuilderRegressionTest::it_produces_identical_json_after_slot_availability_refactor` (Snapshot-Test gegen V2-Output)

**Vitest (`resources/js/pages/Tables.test.ts`)**

- `it renders availability grid with correct slot count` (Mocked Inertia Props)
- `it filters by date picker change` (Partial Reload Mock)

---

## Risiken & offene Fragen

- **Performance** – 50 Tische × 30-min-Slots × 12h Öffnungszeit = 1200 Slot-Tisch-Kombinationen pro Tag. `forDay` muss das in einer Query laden + PHP-seitig berechnen. Falls Lücke beim Pilot-Restaurant: Per-Day-Cache mit Invalidation auf Reservierungs-Änderungen (Event-Listener). Cache-Decision wird in einem `docs/decisions/`-Eintrag festgehalten, sobald Performance-Messung vorliegt.
- **Combinable-with-Komplexität** – ein User kann theoretisch alle Tische als „kombinierbar" markieren, was die Kombi-Suche kombinatorisch explodieren lässt. Hard-Limit: maximal 2-Tisch-Kombinationen, maximal 10 `combinable_with`-Einträge pro Tisch. Validierung in `TableRequest`.
- **Bestandsdaten ohne Tisch-Zuweisung** – Reservierungen aus V1+V2 haben keine `reservation_table_assignments`. Die Logik behandelt sie als „blockiert Default-Kapazität". Beim Pilot kann das zu unerwarteten „belegt"-Antworten führen, wenn der Default-Tisch zu klein ist. Mitigation: der Seed-Default-Tisch hat `seats = max(party_size) + 4`, was historische Worst-Cases abdeckt.
- **DSGVO** – `tables` und `reservation_table_assignments` enthalten keine personenbezogenen Daten. Kein neuer Retention-Pfad nötig.
- **Locking** – zwei gleichzeitige Telefon-Reservierungen für denselben Slot: ohne Locking kann ein Tisch doppelt zugewiesen werden. PRD-011 implementiert noch **kein** Pessimistic Locking. PRD-012 wird das mit `lockForUpdate()` beim `forSlot`+`store`-Pfad ergänzen, damit ist die Race-Condition geschlossen.

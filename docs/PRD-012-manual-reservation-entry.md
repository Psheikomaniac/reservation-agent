# PRD-012: Manuelle Erfassung Telefon/Walk-in mit Live-Verfügbarkeit

**Produkt:** reservation-agent
**Version:** V3.0
**Priorität:** P0 – schließt den größten operativen Kanal-Schmerz nach V2
**Entwicklungsphase:** V3-Phase 2
**Voraussetzungen:** PRD-011 (Verfügbarkeits-Modell)

---

## Problem

Der dominante Reservierungs-Kanal in inhabergeführten Restaurants ist das **Telefon**, gefolgt von **Walk-ins**. V1+V2 hat dafür keinen Erfassungs-Pfad – Reservierungen kommen nur aus dem Web-Formular (PRD-002) oder als E-Mail (PRD-003). Telefon und Walk-in landen heute zwangsläufig im Buch / Zettel und damit **außerhalb** des Systems.

Folge:

- Doppelbuchungen entstehen, weil parallel Online gebucht wird, ohne dass der Telefon-Schreiber das sieht.
- Statistiken in PRD-008 zeigen nur einen Teilausschnitt der Reservierungen.
- Der Wert von PRD-011 (Verfügbarkeits-Modell) wird gar nicht ausgespielt – am Telefon, dem Hauptkanal, ist er unsichtbar.

Das V3-Brainstorming am 2026-05-01 hat den eigentlichen Telefon-Engpass identifiziert: nicht *Eingabe-Geschwindigkeit*, sondern **Verfügbarkeits-Erkenntnis**. „Mittwoch in 4 Tagen 19 Uhr noch frei?" lässt sich aus dem Kopf nicht verlässlich beantworten, sobald Tische, Personenzahlen und Pufferzeiten ins Spiel kommen. Das System schlägt den Menschen hier kategorisch.

## Ziel

Ein internes, **Single-Screen, Tastatur-fokussiertes** Erfassungs-Formular im Dashboard. Der Owner tippt während des Telefonats Datum, Uhrzeit, Personenzahl, Name, Telefon ein – und sieht **live** während der Eingabe, ob der gewünschte Slot frei ist, mit konkretem Tisch-Vorschlag oder den nächsten 3 freien Slots.

Walk-ins funktionieren über denselben Pfad mit `source = walk_in` und sinnvollen Defaults (jetzt+15 min).

Die Reservierung wird **direkt als `confirmed` gespeichert** – keine KI-Antwort, keine Mail. Der Inhaber hat dem Gast am Telefon mündlich zugesagt; das Tool ist Buchführung, nicht Kommunikation.

---

## Scope V3.0

### In Scope

- Inertia-Page `Reservations/Quick.vue` – Single-Screen, Tastatur-First
- Smart-Defaults: heute, jetzt+1h aufgerundet auf 30-min-Raster, 2 Personen
- Live-Verfügbarkeitsprüfung debounced 250 ms via Inertia Partial Reload (`only: ['availability']`)
- Anzeige im Status-Banner: ✅ frei + Tisch-Vorschlag / ⚠️ knapp / ❌ belegt + nächste 3 freie Slots
- Auto-Tisch-Zuweisung beim Speichern aus `SlotAvailability::suggestTableCombination`
- Manuelle Tisch-Override per Dropdown möglich (für Inhaber, der „Tisch 7 möchte ich Stammkunden geben")
- Submit erzeugt `ReservationRequest` mit `status = confirmed`, `source = phone` oder `walk_in`, **keine** KI-Pipeline, **keine** Mail
- Pessimistic Locking (`lockForUpdate`) beim Submit, um Race-Conditions zwischen Telefon-Eingabe und parallelem Web-Submit zu schließen
- Dashboard-Quick-Action „+ Telefon-Reservierung" als prominenter Button im Dashboard-Header

### Out of Scope

- **KI-formulierte SMS-Bestätigung an den Gast** – kein V3-Thema, nicht im Sound von „Telefon-Reservierung = mündlich bestätigt"
- **Walk-in-spezifische UX** (z. B. Kreditkarten-Vorautorisierung) – V7
- **Mehrere Reservierungen am Stück eingeben** (Batch-Modus) – V4 frühestens
- **Historische Eingaben rückwirkend** (für Daten-Migration aus altem System) – nicht versioniert, ad-hoc bei Bedarf

---

## Technische Anforderungen

### Inertia-Page Layout

`resources/js/pages/Reservations/Quick.vue`:

```
┌────────────────────────────────────────────────────────────┐
│  Telefon-Reservierung                              [Esc ✕] │
├────────────────────────────────────────────────────────────┤
│                                                            │
│   Datum:    [25.05.2026  ▼]      Zeit:  [19:00 ▼]          │
│   Personen: [4   ▼]                                        │
│                                                            │
│   ┌──────────────────────────────────────────────────────┐ │
│   │  ✅ Slot frei – Tisch 7 (4 Plätze) wird vorgeschlagen │ │
│   │     (Override:  [Tisch 7 ▼])                         │ │
│   └──────────────────────────────────────────────────────┘ │
│                                                            │
│   Name:        [Müller_______________]                     │
│   Telefon:     [+49 157 …____________]                     │
│   E-Mail:      [optional_____________]                     │
│   Anmerkung:   [optional, z. B. "Geburtstag"_____________] │
│                                                            │
│   [Abbrechen (Esc)]              [Speichern (Strg+Enter)]  │
└────────────────────────────────────────────────────────────┘
```

**Tastatur-Flow:** Tab springt logisch (Datum → Zeit → Personen → Name → Telefon → Mail → Anmerkung). `Strg+Enter` löst Submit aus. `Esc` schließt das Form. Datum/Zeit-Felder akzeptieren auch direkte Eingabe (nicht nur Picker), z. B. „25.05" → wird zu nächstem 25.05 ergänzt.

### Live-Verfügbarkeit

Bei jeder Änderung an Datum/Zeit/Personen wird ein Inertia Partial Reload gegen denselben Page-Endpoint mit `only: ['availability']` gefeuert. Debounced 250 ms.

```typescript
const refreshAvailability = useDebounceFn(() => {
  router.reload({
    only: ['availability'],
    data: {
      date: form.date,
      time: form.time,
      party_size: form.partySize,
    },
    preserveScroll: true,
    preserveState: true,
  })
}, 250)
```

Server-seitig liefert `QuickReservationController@create` die Inertia-Page mit Prop `availability: SlotAvailabilityResult` aus PRD-011. Bei initialem Load: `availability` wird aus den Smart-Defaults berechnet.

**Status-Banner-Logik:**

| `SlotAvailability::forSlot`-Result | Banner |
|---|---|
| `state = free` | ✅ Slot frei – Tisch X (Y Plätze) wird vorgeschlagen |
| `state = tight` (≤ 25 % der Kapazität frei) | ⚠️ Slot knapp – Tisch X passt noch, andere Slots sicherer |
| `state = full` | ❌ Slot belegt – Vorschläge: 18:30, 19:30, 20:00 |

### Routing

```php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/reservations/quick', [QuickReservationController::class, 'create'])
        ->name('reservations.quick.create');
    Route::post('/reservations/quick', [QuickReservationController::class, 'store'])
        ->name('reservations.quick.store');
});
```

### Controller + FormRequest

`app/Http/Controllers/QuickReservationController.php`:

```php
public function create(QuickReservationCreateRequest $request, SlotAvailability $availability): Response
{
    $validated = $request->validated();
    $defaultStart = CarbonImmutable::now()
        ->ceilMinutes(30)
        ->addHour();

    $datetime = isset($validated['date'], $validated['time'])
        ? CarbonImmutable::parse("{$validated['date']} {$validated['time']}")
        : $defaultStart;

    return Inertia::render('Reservations/Quick', [
        'availability' => $availability->forSlot(
            $request->user()->restaurant_id,
            $datetime,
            (int) ($validated['party_size'] ?? 2),
        ),
        'tables' => Table::where('restaurant_id', $request->user()->restaurant_id)
            ->where('active', true)
            ->orderBy('sort_order')
            ->get(),
    ]);
}

public function store(QuickReservationStoreRequest $request, SlotAvailability $availability): RedirectResponse
{
    return DB::transaction(function () use ($request, $availability) {
        $datetime = CarbonImmutable::parse("{$request->date} {$request->time}");

        // Lock alle Tische des Restaurants für diesen Slot
        Table::where('restaurant_id', $request->user()->restaurant_id)
            ->where('active', true)
            ->lockForUpdate()
            ->get();

        $reservation = ReservationRequest::create([
            'restaurant_id' => $request->user()->restaurant_id,
            'source' => $request->source, // 'phone' | 'walk_in'
            'status' => ReservationStatus::Confirmed,
            'guest_name' => $request->guest_name,
            'guest_phone' => $request->guest_phone,
            'guest_email' => $request->guest_email,
            'party_size' => $request->party_size,
            'desired_at' => $datetime,
            'note' => $request->note,
            'created_by_user_id' => $request->user()->id,
        ]);

        $tableId = $request->table_id
            ?? $availability->suggestTableCombination(
                $request->user()->restaurant_id,
                $datetime,
                $request->party_size,
            )?->primaryTableId;

        if ($tableId === null) {
            throw ValidationException::withMessages([
                'table_id' => 'Kein passender Tisch verfügbar. Bitte manuell wählen oder anderen Slot.',
            ]);
        }

        ReservationTableAssignment::create([
            'reservation_request_id' => $reservation->id,
            'table_id' => $tableId,
            'assigned_at' => now(),
            'assigned_by_user_id' => $request->user()->id,
        ]);

        return redirect()->route('dashboard')
            ->with('flash.success', 'Reservierung gespeichert.');
    });
}
```

`QuickReservationStoreRequest`:

- `source` – required, in `['phone', 'walk_in']`
- `date` – required, date, after-or-equal today
- `time` – required, format `H:i`
- `party_size` – required, integer, 1–20 (Cap aus `docs/decisions/party-size-cap.md`)
- `guest_name` – required, max 200
- `guest_phone` – nullable, max 50, simple regex `[+0-9 ()/-]+`
- `guest_email` – nullable, email
- `note` – nullable, max 500
- `table_id` – nullable, exists in `tables` für Restaurant + active

### Locking-Strategie

`lockForUpdate()` auf den Tisch-Stammdaten plus die Transaktion stellt sicher, dass zwischen `SlotAvailability::forSlot` (für die UI-Vorschau) und `store` keine andere Session denselben Tisch verbucht. Der Lock wird auf der `tables`-Tabelle gehalten – nicht auf `reservation_table_assignments`, weil die Tabelle leer für den fraglichen Slot ist und kein Lock greift.

### Dashboard-Integration

`resources/js/pages/Dashboard.vue` Header bekommt einen prominenten Button:

```vue
<Link :href="route('reservations.quick.create')" class="...">
  <PhoneIcon /> Telefon-Reservierung
</Link>
```

Direkt links vom bestehenden Filter-Block, nicht in einem überladenen Drop-Down. Der Knopf ist die wichtigste Aktion auf dem Dashboard für die Zielgruppe „Owner am Telefon".

---

## Akzeptanzkriterien

- [ ] Page `/reservations/quick` öffnet sich mit Smart-Defaults (heute, jetzt+1h auf 30-min-Raster, 2 Personen)
- [ ] Tab-Reihenfolge logisch: Datum → Zeit → Personen → Name → Telefon → Mail → Anmerkung
- [ ] `Strg+Enter` löst Submit aus, `Esc` schließt das Form (Bestätigung wenn Daten eingetragen)
- [ ] Live-Verfügbarkeit reagiert auf Datum/Zeit/Personen-Änderung in <500 ms inkl. Round-Trip
- [ ] Status-Banner zeigt korrekte Farbe + Text für `free` / `tight` / `full`
- [ ] Bei `full`: drei nächste freie Slots werden vorgeschlagen, Klick füllt Datum/Zeit
- [ ] Submit speichert `ReservationRequest` mit `status = confirmed`, `source = phone` oder `walk_in`, **keine** KI-Antwort, **keine** Mail
- [ ] Auto-Tisch-Zuweisung greift, wenn kein manueller `table_id` übergeben wurde
- [ ] Race-Condition-Test: zwei parallele Submits für denselben Tisch + Slot → einer gewinnt, der andere kriegt Validierungsfehler
- [ ] Dashboard zeigt neue Reservierung nach Submit unmittelbar (Polling reicht, kein Reverb)
- [ ] User aus anderem Restaurant kann nicht auf `/reservations/quick` zugreifen (Policy)
- [ ] `./vendor/bin/pint --test`, `npm run lint`, `npm run format:check` ohne Findings
- [ ] Vitest-Komponente prüft Tastatur-Flow + Debounce-Verhalten

---

## Tests

**Feature (`tests/Feature/Reservations/QuickReservationTest.php`)**

- `it shows quick form with smart defaults`
- `owner can create phone reservation`
- `owner can create walk_in reservation`
- `it auto-assigns smallest fitting table`
- `it accepts manual table override`
- `it rejects when chosen table is busy in the slot`
- `it rejects when no table fits party size`
- `it locks the slot during transaction` (zwei parallele Requests → einer scheitert)
- `user from other restaurant cannot access quick form`
- `it does not create a reservation_reply` (kein KI-Pfad)
- `it does not dispatch any mail job` (`Mail::fake` + `assertNothingSent`)

**Vitest (`resources/js/pages/Reservations/Quick.test.ts`)**

- `it triggers debounced availability reload on date change`
- `it submits on Ctrl+Enter`
- `it cancels on Esc with confirmation when form is dirty`
- `it shows next free slots when status is full`

---

## Risiken & offene Fragen

- **Validierungs-Stress am Telefon** – wenn Validierung scheitert (z. B. „Tisch belegt"), während der Owner am Hörer ist, ist Frust hoch. Mitigation: Live-Verfügbarkeit bringt das Problem **vor** dem Submit ans Licht. Bei trotzdem scheiterndem Submit zeigt der Fehler einen klaren Vorschlags-Slot, kein Fachjargon.
- **Owner möchte ohne Telefon einen Walk-in eintragen** – das geht über denselben Pfad mit `source = walk_in` und Default „jetzt+15 min". Kein eigener Walk-in-Workflow nötig.
- **Inhaber gibt absichtlich überlappende Reservierungen ein** („Tisch 7 für 18 Uhr UND 18:30, sind dieselben Leute") – das System lehnt ab. V3 unterstützt **kein** Force-Override. Workaround: Reservierung anpassen statt zwei anzulegen. Wenn das im Pilot ein realer Pain wird, kommt ein Force-Flag in V4 mit Audit-Trail.
- **Telefonnummer-Format** – V3 validiert nur grob (`[+0-9 ()/-]+`). E.164-Normalisierung kommt in V4 mit Stammgast-Profil (PRD-016), wo Auto-Match auf Telefonnummer relevant wird.
- **Sound-/Push-Alerts** – Telefon-Reservierungen lösen **keinen** Push aus (PRD-010 betrifft nur eingehende externe Anfragen). Eine Telefon-Reservierung ist per Definition vom Owner selbst angelegt – Push wäre Selbst-Spam.

# PRD-004: Reservierungs-Dashboard

**Produkt:** reservation-agent
**Version:** V1.0
**Priorität:** P0 – die zentrale Ansicht, die das Produkt überhaupt nutzbar macht
**Entwicklungsphase:** Woche 3

---

## Problem

Reservierungsanfragen aus PRD-002 (Web-Formular) und PRD-003 (E-Mail) liegen im System, aber der Gastronom kann sie nirgendwo sinnvoll sehen. Ohne eine bündelnde, filterbare und priorisierbare Übersicht gibt es keinen Mehrwert gegenüber dem Status Quo (manuelles Durchsehen mehrerer Quellen).

## Ziel

Ein Dashboard, das alle Reservierungsanfragen des eigenen Restaurants in einer einheitlichen Liste anzeigt – unabhängig von der Quelle – mit sinnvollen Filtern, einem Detail-Drawer und klaren Statusindikatoren. Das Dashboard aktualisiert sich im Hintergrund, damit neue Anfragen ohne manuelles Reload sichtbar werden.

---

## Scope V1.0

### In Scope

- Inertia-Page `resources/js/pages/Dashboard.vue` ersetzt den Platzhalter aus PRD-001
- Liste aller `ReservationRequest` des eigenen Restaurants, paginiert
- Filter (Query-Parameter, über Inertia-Form):
  - Status (Multi-Select): `new`, `in_review`, `replied`, `confirmed`, `declined`, `cancelled`
  - Quelle (Multi-Select): `web_form`, `email`
  - Datumsbereich (von / bis, bezogen auf `desired_at`)
  - Suche (Freitext über `guest_name`, `guest_email`)
- Standard-Filter beim ersten Besuch: Status = `new` + `in_review`, Datum = ab heute
- Spalten: Eingegangen, Gewünscht (Datum/Zeit), Gäste (Count), Name, Quelle, Status, Aktionen
- Zeilen mit `needs_manual_review = true` visuell markiert (gelbe Chip „Prüfen")
- Detail-Drawer: vollständige Anfrage, Nachricht, bei `source = email` Original-Body ausklappbar
- Bulk-Aktionen: Status setzen (z. B. „Als abgelehnt markieren"), „Als gelesen markieren" (Übergang `new → in_review`)
- Polling via Inertia Partial Reload alle 30 s, **nur solange die Tab-Visibility `visible` ist**
- Autorisierung via Policy (nur eigenes Restaurant)

### Out of Scope

- KI-Antwortvorschläge / Freigabe-UI (PRD-005)
- Mail-Versand (PRD-005)
- Kalenderansicht / Tischplan (V2.0)
- Export (CSV/PDF) (V2.0)
- Push-Benachrichtigungen / Sound-Alert (V2.0)

---

## Technische Anforderungen

### Controller

`app/Http/Controllers/DashboardController.php`:

```php
public function index(DashboardFilterRequest $request): Response
{
    $filters = $request->validated();

    $requests = ReservationRequest::query()
        ->filter($filters) // lokaler Scope
        ->with(['latestReply'])
        ->orderByDesc('created_at')
        ->paginate(25)
        ->withQueryString();

    return Inertia::render('Dashboard', [
        'filters'  => $filters,
        'requests' => ReservationRequestResource::collection($requests),
        'stats'    => [
            'new'       => ReservationRequest::where('status', ReservationStatus::New)->count(),
            'in_review' => ReservationRequest::where('status', ReservationStatus::InReview)->count(),
        ],
    ]);
}
```

Mandantentrennung: der globale `RestaurantScope` aus PRD-001 sorgt dafür, dass `ReservationRequest::query()` bereits nur Records des eigenen Restaurants liefert.

### FormRequest für Filter

`DashboardFilterRequest`:

```php
return [
    'status'      => ['array'],
    'status.*'    => ['in:new,in_review,replied,confirmed,declined,cancelled'],
    'source'      => ['array'],
    'source.*'    => ['in:web_form,email'],
    'from'        => ['nullable', 'date'],
    'to'          => ['nullable', 'date', 'after_or_equal:from'],
    'q'           => ['nullable', 'string', 'max:120'],
];
```

### Eloquent Scope

`ReservationRequest::scopeFilter(Builder $query, array $filters)` konsumiert das validierte Array und setzt die `where`-Bedingungen. Kein dynamisches SQL – jedes Feld wird explizit gemappt.

### API Resource

`ReservationRequestResource` – definiert den JSON-Contract:

```php
return [
    'id'                  => $this->id,
    'status'              => $this->status->value,
    'source'              => $this->source->value,
    'guest_name'          => $this->guest_name,
    'guest_email'         => $this->guest_email,
    'guest_phone'         => $this->guest_phone,
    'party_size'          => $this->party_size,
    'desired_at'          => $this->desired_at?->toIso8601String(),
    'needs_manual_review' => $this->needs_manual_review,
    'created_at'          => $this->created_at->toIso8601String(),
    'has_raw_email'       => $this->source === ReservationSource::Email,
];
```

Der Original-Mail-Body wird **nicht** mit ausgeliefert – nur auf Detail-Anforderung über einen eigenen Endpoint `GET /reservations/{id}` (ebenfalls policy-geschützt).

### Frontend

`resources/js/pages/Dashboard.vue`:

- Filter-Leiste oben (Chips für Status/Quelle, Date-Range-Picker, Suchfeld)
- Tabelle mit Reka UI (`<Table>`-Komponenten)
- Bulk-Selection per Checkbox
- Detail-Drawer rechts (Reka UI Sheet/Drawer), lädt Details on-demand via Partial Reload
- Polling:

```ts
const POLL_MS = 30_000;
let timer: number | null = null;

function startPolling() {
  if (timer || document.visibilityState !== 'visible') return;
  timer = window.setInterval(() => {
    router.reload({ only: ['requests', 'stats'] });
  }, POLL_MS);
}

function stopPolling() {
  if (timer) { clearInterval(timer); timer = null; }
}

onMounted(startPolling);
onBeforeUnmount(stopPolling);
document.addEventListener('visibilitychange', () => {
  document.visibilityState === 'visible' ? startPolling() : stopPolling();
});
```

Keine `fetch`/`axios` – ausschließlich `router.reload({ only: [...] })`.

### Bulk-Aktionen

`POST /reservations/bulk-status` mit `{ ids: number[], status: string }`:

- FormRequest validiert IDs (existieren, alle zum eigenen Restaurant über Policy-Check pro ID)
- Nur **erlaubte** Status-Übergänge via `ReservationStatus::canTransitionTo()` aus PRD-001; ungültige Übergänge werden übersprungen und im Response als `skipped` gemeldet
- Rückgabe: Zahlen `updated` + `skipped`, Inertia redirectet zurück aufs Dashboard mit Flash-Message

---

## Akzeptanzkriterien

- [ ] Dashboard zeigt alle Reservierungen des eigenen Restaurants in Reverse-Chronologie
- [ ] Standard-Filter zeigt `new` + `in_review` ab heute
- [ ] Filter Status, Quelle und Datum kombinieren sich korrekt
- [ ] Freitext-Suche findet über `guest_name` und `guest_email`
- [ ] Detail-Drawer zeigt vollständige Anfrage inkl. Original-Mail-Body bei Email-Source
- [ ] `needs_manual_review = true` ist visuell hervorgehoben
- [ ] Bulk „Als abgelehnt markieren" setzt nur gültige Übergänge, protokolliert übersprungene
- [ ] Polling stoppt wenn Tab in den Hintergrund wechselt, startet beim Zurückkehren
- [ ] User aus Restaurant B sieht keine Reservierungen von Restaurant A (Scope + Policy)
- [ ] Direkter Zugriff auf `/reservations/{id}` eines fremden Restaurants → 403
- [ ] Alle Tests grün, Pint/ESLint/Prettier ohne Findings

---

## Tests

**Feature (`tests/Feature/DashboardTest.php`)**

- `it lists reservations scoped to own restaurant`
- `it applies default filters on first visit`
- `it combines status source and date filters`
- `it searches by guest name and email`
- `it highlights rows needing manual review` (über Resource-Feld)
- `it forbids access to other restaurants reservation`
- `it redirects unauthenticated users to login`
- `it updates allowed statuses in bulk and skips invalid transitions`
- `it paginates results correctly`

**Unit**

- `ReservationRequest::scopeFilter` – jede Filter-Kombination liefert die erwartete SQL (mit `->toSql()`-Snapshot oder Count-Vergleich auf Seed-Daten)

---

## Risiken & offene Fragen

- **Polling vs. Websockets** – V1.0-Entscheidung dokumentiert in [`docs/decisions/polling-vs-websockets-v1.md`](decisions/polling-vs-websockets-v1.md). V1.0 nutzt Polling; konkrete Trigger-Schwellen für die Umstellung auf Laravel Reverb sind dort festgelegt (aktive Tabs, DB-QPS, p95-Latenz, DB-CPU).
- **Performance bei > 10.000 Reservierungen** – Paginierung + Index auf `(restaurant_id, status, created_at)` sollte reichen. Benchmarken vor Pilot.
- **Flutuation durch Polling** – wenn sich die Liste beim Poll verschiebt, kann die UI ungewollt springen. Lösung: Selektion per `id` persistieren, nicht per Zeilen-Index.
- **Timezone-Anzeige** – `desired_at` liegt in UTC. Im Frontend muss die Anzeige in der Restaurant-Zeitzone erfolgen; die Zeitzone wird als Inertia Shared Prop (aus Restaurant) bereitgestellt.

# PRD-013: Warteliste passiv

**Produkt:** reservation-agent
**Version:** V3.0
**Priorität:** P1 – Beifang aus PRD-011, geringer Eigenaufwand
**Entwicklungsphase:** V3-Phase 2
**Voraussetzungen:** PRD-011 (Verfügbarkeits-Modell)

---

## Problem

Bei vollen Slots passiert heute eines von zwei Dingen:

1. Der Owner sagt am Telefon „nein, voll" – der Gast geht woanders hin und ist verloren.
2. Der Owner kritzelt sich auf einen Zettel „Müller Mi 19 Uhr falls was frei wird" – aber wenn um 17 Uhr eine Reservierung canceled, fällt ihm der Zettel nicht ein.

In beiden Fällen werden potenzielle Umsätze verschenkt. Eine Warteliste schließt diese Lücke.

V3 baut eine **passive** Warteliste: Einträge werden gepflegt, der Owner sieht sie, und bei Cancel zeigt das Dashboard einen Banner „1 Wartender könnte rein". **Kein** automatischer E-Mail-Versand an den Wartenden – das ist V4 (PRD-017 Aktive Warteliste mit Notify), weil es Gast-Stammdaten und Annahme-Frist-Logik braucht. V3 liefert das Sichtbarmachen.

## Ziel

Anfragen, deren Wunsch-Slot belegt ist, können vom Owner als `waitlisted` markiert werden. Wartende sind im Dashboard filterbar. Bei Cancel oder Verkleinerung einer bestätigten Reservierung erkennt das System per `SlotAvailability`, ob jetzt ein passender Slot für mindestens einen Wartenden frei ist, und blendet einen Banner mit Direkt-Link auf den Wartenden ein.

---

## Scope V3.0

### In Scope

- Neuer Status `waitlisted` in `ReservationStatus`-Enum
- Status-Übergänge: `new → waitlisted`, `in_review → waitlisted`, `waitlisted → confirmed`, `waitlisted → declined`
- Filter „Warteliste" im Dashboard (zusätzlich zu den bestehenden Status-Filtern)
- Banner-Komponente im Dashboard, wenn ≥ 1 Wartender für einen jetzt freien Slot existiert
- Banner-Polling: nutzt das bestehende Dashboard-Polling-Intervall (30 s), keine Reverb-Integration
- Klick auf Banner öffnet den Wartenden-Drawer mit Vor-Ausfüll der bestätigten Slot-Daten

### Out of Scope

- **Automatischer E-Mail-Versand** an Wartende bei freiem Slot – V4 PRD-017
- **Annahme-Frist** mit Re-Anfrage falls keine Antwort – V4 PRD-017
- **Warteliste-Position-Anzeige** für Gäste – V4
- **Ranking-Logik** (z. B. „Stammgast vor Erstgast") – V4 mit PRD-016
- **Mehrere Wartende pro Slot mit Reihenfolge** – V3 zeigt einfach „1 Wartender könnte rein", ohne Reihenfolge

---

## Technische Anforderungen

### Datenmodell

`ReservationStatus`-Enum (in `app/Enums/`) bekommt einen neuen Wert:

```php
enum ReservationStatus: string
{
    case New = 'new';
    case InReview = 'in_review';
    case Replied = 'replied';
    case Confirmed = 'confirmed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Waitlisted = 'waitlisted';     // NEU
    // ...
}
```

Der Enum wird im `ReservationRequest`-Model bereits als Cast verwendet. Migration ist additiv – keine Schema-Änderung nötig, weil die Spalte als String gespeichert wird.

**Status-Maschine** (in `app/Services/ReservationStatusMachine.php` oder als Methode am Model):

```php
private const ALLOWED_TRANSITIONS = [
    ReservationStatus::New->value => [
        ReservationStatus::InReview, ReservationStatus::Replied,
        ReservationStatus::Declined, ReservationStatus::Waitlisted, // NEU
    ],
    ReservationStatus::InReview->value => [
        ReservationStatus::Replied, ReservationStatus::Declined,
        ReservationStatus::Waitlisted, // NEU
    ],
    ReservationStatus::Waitlisted->value => [
        ReservationStatus::Confirmed, ReservationStatus::Declined,
    ],
    // ...
];
```

### Banner-Logik

Neuer Service `app/Services/Waitlist/WaitlistBanner.php`:

```php
final class WaitlistBanner
{
    public function __construct(
        private readonly SlotAvailability $availability,
    ) {}

    /**
     * Liefert die Liste von Wartenden, deren gewünschter Slot jetzt frei ist.
     * Wird vom Dashboard-Controller in den Inertia-Props mitgeliefert.
     */
    public function eligibleNow(int $restaurantId): Collection
    {
        $waitlisted = ReservationRequest::query()
            ->where('restaurant_id', $restaurantId)
            ->where('status', ReservationStatus::Waitlisted)
            ->where('desired_at', '>=', now())
            ->orderBy('desired_at')
            ->limit(20) // Performance-Cap
            ->get();

        return $waitlisted->filter(function (ReservationRequest $request) {
            $result = $this->availability->forSlot(
                $request->restaurant_id,
                CarbonImmutable::parse($request->desired_at),
                $request->party_size,
            );

            return $result->state !== SlotState::Full;
        })->values();
    }
}
```

`DashboardController@index` ergänzt die Inertia-Props um `waitlistBanner`:

```php
return Inertia::render('Dashboard', [
    // ... bestehende Props ...
    'waitlistBanner' => $waitlistBanner->eligibleNow($request->user()->restaurant_id),
]);
```

Das Polling im Dashboard (alle 30 s, nur wenn Tab visible) lädt diese Prop mit – kein zusätzlicher Endpoint, kein zusätzliches Polling-Intervall.

### UI

`resources/js/components/WaitlistBanner.vue`:

```vue
<template>
  <div v-if="banner.length > 0" class="rounded-lg border-amber-300 bg-amber-50 p-4">
    <p class="text-sm text-amber-900">
      <strong>{{ banner.length }} Wartender</strong> könnte jetzt einen Slot bekommen.
    </p>
    <ul class="mt-2 text-sm">
      <li v-for="entry in banner" :key="entry.id">
        <button @click="open(entry.id)" class="text-amber-900 underline">
          {{ entry.guest_name }} – {{ formatDate(entry.desired_at) }} ({{ entry.party_size }} P.)
        </button>
      </li>
    </ul>
  </div>
</template>
```

Wird im Dashboard oben unter dem Header eingeblendet, wenn `banner.length > 0`. Klick öffnet den bestehenden Reservation-Detail-Drawer mit Status-Übergangs-Buttons.

### Filter im Dashboard

Bestehender Filter-Bar bekommt eine neue Option:

```vue
<FilterChip value="waitlisted" label="Warteliste" />
```

Filter-Logik im `ReservationFilter`-Service (PRD-004) wird um den neuen Status erweitert.

### Status-Übergangs-UI

Im Reservation-Detail-Drawer existiert bereits eine Status-Aktion-Leiste. Neu hinzu:

- Wenn `status = new` oder `in_review`: Button „Auf Warteliste"
- Wenn `status = waitlisted`: zwei Buttons „Bestätigen" (öffnet PRD-005-KI-Antwort-Pfad oder PRD-012-Quick-Form) und „Ablehnen"

---

## Akzeptanzkriterien

- [ ] `ReservationStatus::Waitlisted` ist als Enum-Wert + DB-Cast verfügbar
- [ ] Status-Übergänge `new → waitlisted`, `in_review → waitlisted`, `waitlisted → confirmed`, `waitlisted → declined` sind erlaubt; alle anderen Übergänge **von oder zu** waitlisted werden abgewiesen
- [ ] Filter „Warteliste" zeigt nur Reservierungen mit `status = waitlisted`
- [ ] Banner erscheint, wenn ≥ 1 wartende Reservierung in einem jetzt freien Slot existiert
- [ ] Banner verschwindet, sobald der Wartende auf `confirmed` oder `declined` gesetzt wird
- [ ] Banner aktualisiert sich nach Cancel einer anderen Reservierung innerhalb des nächsten Polling-Tick (max 30 s)
- [ ] Klick auf Banner-Eintrag öffnet den Detail-Drawer der Reservierung
- [ ] User aus anderem Restaurant sieht keinen Banner-Eintrag (Tenant-Scope)
- [ ] Performance: `WaitlistBanner::eligibleNow` läuft in <50 ms bei 20 wartenden Reservierungen
- [ ] `./vendor/bin/pint --test`, `npm run lint`, `npm run format:check` ohne Findings

---

## Tests

**Unit (`tests/Unit/Waitlist/`)**

- `WaitlistBannerTest::it_lists_only_waitlisted_with_free_slot`
- `WaitlistBannerTest::it_excludes_past_desired_at`
- `WaitlistBannerTest::it_excludes_full_slots`
- `WaitlistBannerTest::it_caps_results_at_20`
- `ReservationStatusMachineTest::it_allows_new_to_waitlisted`
- `ReservationStatusMachineTest::it_rejects_confirmed_to_waitlisted`

**Feature (`tests/Feature/Waitlist/`)**

- `WaitlistFlowTest::owner_can_move_request_to_waitlist`
- `WaitlistFlowTest::banner_appears_after_cancel_frees_slot`
- `WaitlistFlowTest::banner_disappears_after_waitlisted_confirmed`
- `WaitlistFlowTest::dashboard_filter_shows_waitlisted_only`
- `WaitlistFlowTest::user_from_other_restaurant_does_not_see_banner`

**Vitest (`resources/js/components/WaitlistBanner.test.ts`)**

- `it renders nothing when banner is empty`
- `it renders one entry per waitlisted item`
- `it emits open event with correct id on click`

---

## Risiken & offene Fragen

- **Banner-Spam** – wenn 20 Wartende in 5 verschiedenen Slots stehen und einer freut wird, könnten alle als „könnten rein" angezeigt werden. Mitigation: Banner zeigt **alle** passenden, aber die UI fasst das in eine Liste und zeigt max 5 mit „… und 3 weitere"-Verkürzung. Hard-Limit 20 in der Query.
- **Cancel-Reihenfolge** – wenn ein Slot frei wird und mehrere Wartende passen, ist die Reihenfolge nach `desired_at` ASC (also der älteste Wunsch zuerst). Das ist eine willkürliche, aber transparente Heuristik. V4 (PRD-017) wird das durch Stammgast-Ranking ersetzen.
- **Polling reicht** – der Banner reagiert mit max 30 s Verzögerung auf Cancel-Events. Das ist akzeptabel: bei Cancel ist niemand am Telefon, der wartet. Reverb-Beschleunigung wäre Komfort, nicht Wert. Reverb kommt in V4 PRD-021.
- **DSGVO** – Wartende sind reguläre `ReservationRequest`-Records, unterliegen denselben Retention-Pfaden wie alle anderen. Kein neuer Datenpfad. PRD-015 (Self-Service-Löschung) deckt sie automatisch ab.
- **Owner ändert Wunsch des Wartenden** – aktuell nicht im Scope. Wenn der Owner sieht „4 Personen 19 Uhr" und denkt „eigentlich passt 18:30 für 6 frei, das könnte er nehmen": muss der Owner anrufen und manuell konfirmieren. Kein Status-Workflow für „neuer Vorschlag".

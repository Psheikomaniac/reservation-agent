# PRD-007: Auto-Versand mit Vertrauensstufen

**Produkt:** reservation-agent
**Version:** V2.0
**Priorität:** P0 – das zentrale V2.0-Feature
**Entwicklungsphase:** V2-Phase 2 (nach PRD-006)

---

## Problem

In V1.0 ist die manuelle Freigabe jeder KI-Antwort architektonisches Prinzip:

> *„Kein KI-generierter Text erreicht ohne menschliche Bestätigung einen Kunden."*
> ([CLAUDE.md](../CLAUDE.md), Kernprinzip)

Dieses Prinzip ist bewusst gewählt – V1.0 baut Vertrauen erst auf. Aber bei produktiv laufenden Restaurants wird die Freigabe-Pflicht zur **operativen Engstelle**:

- Stoßzeiten erzeugen viele Anfragen gleichzeitig
- Nachts oder am Ruhetag ist niemand am Dashboard – Anfragen warten Stunden
- Bei wiederholt unveränderten Freigaben („alles freigeben"-Klick) wird die manuelle Stufe zur Placebo-Sicherheit

Gleichzeitig: **blindes Auto-Send wäre fahrlässig**. Eine einzige halluzinierte Bestätigung an einen Gast, dessen Tisch nicht existiert, schadet dem Restaurant nachhaltiger als jede gesparte Minute.

Es braucht einen Pfad, der **Vertrauen messbar aufbaut, bevor er versendet** – und der harte Sicherheitsnetze auch dann beibehält, wenn der Owner Auto-Send aktiviert.

## Ziel

Pro Restaurant kann der Owner einen Versandmodus wählen: `manual` (V1.0-Verhalten, Default), `shadow` (KI generiert + markiert „wäre versendet worden", versendet aber nicht), `auto` (versendet automatisch nach Sicherheits-Cooldown). Bestimmte Anfrage-Konstellationen erzwingen unabhängig vom Modus immer manuelle Freigabe.

Der Wechsel `manual → shadow → auto` läuft als bewusster Vertrauensaufbau: im Shadow-Modus sammelt das System Daten über die Übernahme-Rate des Owners, der Wechsel auf `auto` zeigt diese Statistik im Confirmation-Dialog. Ein **Killswitch** schaltet jederzeit auf `manual` zurück und stoppt alle eingeplanten, aber noch nicht versendeten Auto-Sends.

---

## Scope V2.0

### In Scope

- Migration: `restaurants.send_mode` (Enum `SendMode`: `manual` | `shadow` | `auto`, Default `manual`)
- Migration: `restaurants.auto_send_party_size_max` (int, Default 10)
- Migration: `restaurants.auto_send_min_lead_time_minutes` (int, Default 90)
- Migration: `reservation_replies.send_mode_at_creation` (Enum-Snapshot zum Generierzeitpunkt)
- Migration: `reservation_replies.shadow_compared_at`, `shadow_was_modified` (boolean) für Übernahme-Statistik
- Migration: neue Status-Werte in `ReservationReplyStatus` Enum: `shadow`, `scheduled_auto_send`, `cancelled_auto`
- Service `AutoSendDecider` mit Hard-Gate-Cascade
- Wert-Objekt `AutoSendDecision` (Status `manual` | `shadow` | `auto_send`, plus `reason`)
- Job `ScheduledAutoSendJob` mit 60-Sekunden-Delay (Cancel-Window)
- Killswitch-Action: `Restaurant::killswitchAutoSend()` – setzt Mode zurück und cancelt alle laufenden Auto-Send-Jobs
- Audit-Tabelle `auto_send_audits` für nachvollziehbare Entscheidungen
- Settings-UI `resources/js/pages/Settings/SendMode.vue` mit Drei-Karten-Layout, Confirmation-Dialogs und Killswitch
- Dashboard-Banner zeigt aktiven Mode
- Detail-Drawer-Erweiterung: Shadow-Replies mit Vergleichs-Block + „Doch manuell freigeben"-Button
- Anpassung `CLAUDE.md` Kernprinzip-Block (V1.0-Verbot wird zu opt-in-Erlaubnis mit Sicherheitsnetzen)
- Anpassung Datenschutzhinweis: Auto-Versand muss in Datenschutzerklärung erwähnt werden

### Out of Scope

- Granulare Toggles pro Antworttyp (Bestätigung/Ablehnung/Alternative) – V2.1 (eigenes Issue, falls erste Pilot-Daten Bedarf zeigen)
- Confidence-basierte Auto-Send-Schwellen (über Hard-Gates hinaus) – V4.0 (gehört zur KI-Lernschleife)
- Time-of-day-Regeln („nicht zwischen 22 und 8 Uhr autosenden") – V2.1
- Auto-Versand für eingehende Replies (PRD-006-Thread-Messages) – V4.0 (Conversational AI)
- A/B-Vergleich KI-Draft vs. Owner-Edit zur Lernsignalisierung – V4.0

---

## Technische Anforderungen

### Enum

`app/Enums/SendMode.php`:

```php
enum SendMode: string
{
    case Manual = 'manual';
    case Shadow = 'shadow';
    case Auto   = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manuelle Freigabe',
            self::Shadow => 'Shadow-Modus (Test)',
            self::Auto   => 'Automatischer Versand',
        };
    }
}
```

`ReservationReplyStatus` aus PRD-001 wird erweitert:

```php
case Shadow             = 'shadow';              // Draft, der NICHT versendet wird, aber als „wäre versendet" markiert ist
case ScheduledAutoSend  = 'scheduled_auto_send'; // Draft im 60s-Cancel-Window
case CancelledAuto      = 'cancelled_auto';      // Auto-Send durch Owner oder Killswitch abgebrochen
```

Status-Übergänge in `ReservationReplyStatus::canTransitionTo()`:

```
draft → shadow                     (Shadow-Modus aktiv, Generierung fertig)
draft → scheduled_auto_send        (Auto-Modus aktiv, Generierung fertig, 60s warten)
draft → approved                   (V1.0: manuelle Freigabe)
shadow → approved                  („Doch manuell freigeben"-Button)
shadow → draft                     (Owner editiert Shadow-Reply → wird zum Draft mit modified=true)
scheduled_auto_send → sent         (60s vorbei, Mailversand erfolgreich)
scheduled_auto_send → cancelled_auto (Owner klickt „Abbrechen" oder Killswitch)
scheduled_auto_send → approved     (Owner überholt das Cancel-Window mit manueller Freigabe)
```

### Datenmodell-Erweiterungen

**`restaurants`:**

| Spalte                            | Typ                | Default     |
|-----------------------------------|--------------------|-------------|
| send_mode                         | string (SendMode)  | `manual`    |
| auto_send_party_size_max          | int                | 10          |
| auto_send_min_lead_time_minutes   | int                | 90          |
| send_mode_changed_at              | datetime null      | null        |
| send_mode_changed_by              | bigint FK null     | null        |

**`reservation_replies`:**

| Spalte                  | Typ                | Notiz                                              |
|-------------------------|--------------------|----------------------------------------------------|
| send_mode_at_creation   | string (SendMode)  | Snapshot, NICHT mit Restaurant-Setting joinen      |
| shadow_compared_at      | datetime null      | wann der Owner die Shadow-Reply angesehen hat      |
| shadow_was_modified     | boolean            | true, wenn Owner den Body editiert hat             |
| auto_send_decision      | json null          | serialisierter `AutoSendDecision` (für Audit)      |
| auto_send_scheduled_for | datetime null      | gesetzt im `scheduled_auto_send`-Status            |

**Neue Tabelle `auto_send_audits`** (append-only):

| Spalte                   | Typ        |
|--------------------------|------------|
| id                       | bigint PK  |
| reservation_reply_id     | bigint FK  |
| restaurant_id            | bigint FK  |
| send_mode                | string     |
| decision                 | string     | `manual` \| `shadow` \| `auto_send` \| `cancelled_auto` |
| reason                   | string     | siehe AutoSendDecider-Reason-Codes                  |
| triggered_by_user_id     | bigint FK null | null = System (Auto-Send), gesetzt bei Killswitch/Cancel |
| created_at               | timestamp  |

Index: `(restaurant_id, created_at)` für PRD-008-Analytics.

### AutoSendDecider

`app/Services/AI/AutoSendDecider.php` – zentraler Service, der zur **einzig autoritativen Quelle** für die Entscheidung wird, ob/wie versendet wird.

```php
final class AutoSendDecider
{
    public function decide(ReservationReply $reply): AutoSendDecision
    {
        $request = $reply->reservationRequest;
        $restaurant = $request->restaurant;

        if ($gate = $this->blockedByHardGate($request, $reply)) {
            return AutoSendDecision::manual($gate);
        }

        return match ($restaurant->send_mode) {
            SendMode::Manual => AutoSendDecision::manual('mode_manual'),
            SendMode::Shadow => AutoSendDecision::shadow('mode_shadow'),
            SendMode::Auto   => AutoSendDecision::autoSend('mode_auto'),
        };
    }

    private function blockedByHardGate(ReservationRequest $request, ReservationReply $reply): ?string
    {
        $restaurant = $request->restaurant;

        // 1. Manuelle Review explizit angefragt (PRD-003: confidence < 1.0 oder Parser-Unklarheit)
        if ($request->needs_manual_review) {
            return 'needs_manual_review';
        }

        // 2. Reply ist Fallback-Text (OpenAI-Fehler)
        if ($this->isFallbackText($reply)) {
            return 'fallback_text';
        }

        // 3. Gruppengröße über dem Restaurant-Limit
        if ($request->party_size > $restaurant->auto_send_party_size_max) {
            return 'party_size_over_limit';
        }

        // 4. Kurzfristige Anfrage – Owner soll persönlich entscheiden
        if ($request->desired_at !== null
            && $request->desired_at->diffInMinutes(now(), false) > -$restaurant->auto_send_min_lead_time_minutes) {
            return 'short_notice';
        }

        // 5. Erstanfrage von dieser E-Mail-Adresse an dieses Restaurant
        if ($this->isFirstTimeGuest($request)) {
            return 'first_time_guest';
        }

        // 6. Email-Quelle mit niedriger Parser-Confidence
        if ($request->source === ReservationSource::Email
            && ($request->raw_payload['confidence'] ?? 0) < 0.9) {
            return 'low_confidence_email';
        }

        return null;
    }

    private function isFallbackText(ReservationReply $reply): bool
    {
        // OpenAiReplyGenerator-Fallback-Text aus PRD-005 ist konstant – exakter Match
        return $reply->body === config('reservation.fallback_reply_text');
    }

    private function isFirstTimeGuest(ReservationRequest $request): bool
    {
        if ($request->guest_email === null) {
            return true;
        }

        return ReservationRequest::query()
            ->where('restaurant_id', $request->restaurant_id)
            ->where('guest_email', $request->guest_email)
            ->where('id', '!=', $request->id)
            ->where('status', ReservationStatus::Confirmed)
            ->doesntExist();
    }
}
```

`AutoSendDecision` ist ein readonly Value-Object:

```php
final readonly class AutoSendDecision
{
    public function __construct(
        public string $decision, // 'manual' | 'shadow' | 'auto_send'
        public string $reason,
    ) {}

    public static function manual(string $reason): self  { return new self('manual', $reason); }
    public static function shadow(string $reason): self  { return new self('shadow', $reason); }
    public static function autoSend(string $reason): self{ return new self('auto_send', $reason); }
}
```

**Reason-Codes (vollständige Liste, dokumentationspflichtig):**

| Code                    | Auslöser                                                |
|-------------------------|---------------------------------------------------------|
| `mode_manual`           | Restaurant.send_mode = manual                           |
| `mode_shadow`           | Restaurant.send_mode = shadow                           |
| `mode_auto`             | Restaurant.send_mode = auto, kein Hard-Gate aktiv       |
| `needs_manual_review`   | PRD-003 hat Parser-Unsicherheit markiert                |
| `fallback_text`         | OpenAI-Generation auf Fallback gefallen (401/429/5xx)   |
| `party_size_over_limit` | `party_size > restaurant.auto_send_party_size_max`      |
| `short_notice`          | `desired_at` < `now() + min_lead_time_minutes`          |
| `first_time_guest`      | Keine vorherige `confirmed`-Reservation dieser Adresse  |
| `low_confidence_email`  | Email-Source, Parser-Confidence < 0.9                   |

### Trigger-Kette (Erweiterung von PRD-005)

```
ReservationRequestReceived
        │
        ▼
GenerateReservationReplyJob (PRD-005)
        │
        ├─► ReservationContextBuilder::build()
        ├─► OpenAiReplyGenerator::generate()
        ├─► ReservationReply::create([... 'status' => 'draft' ...])
        ├─► AutoSendDecider::decide() ◄── neu in PRD-007
        ├─► AutoSendAudit-Eintrag schreiben
        │
        ├─► decision = 'manual'    → Status bleibt 'draft' (V1.0-Pfad)
        ├─► decision = 'shadow'    → Status 'shadow', kein Mailversand, Banner im UI
        └─► decision = 'auto_send' → Status 'scheduled_auto_send',
                                      ScheduledAutoSendJob::dispatch()->delay(60s)
```

`ScheduledAutoSendJob` prüft beim Ausführen:

```php
public function handle(AutoSendDecider $decider): void
{
    $reply = ReservationReply::find($this->replyId);

    // Race-Condition-sicher: Status muss noch scheduled sein
    if ($reply === null || $reply->status !== ReservationReplyStatus::ScheduledAutoSend) {
        return; // Owner hat im Cancel-Window gestoppt
    }

    // Restaurant kann zwischenzeitlich Killswitch ausgelöst haben
    if ($reply->reservationRequest->restaurant->send_mode !== SendMode::Auto) {
        $reply->update(['status' => ReservationReplyStatus::CancelledAuto]);
        $this->writeAudit($reply, 'cancelled_auto', 'killswitch_during_window');
        return;
    }

    // Re-evaluieren der Hard-Gates – State könnte sich geändert haben
    $decision = $decider->decide($reply);
    if ($decision->decision !== 'auto_send') {
        $reply->update(['status' => ReservationReplyStatus::Draft]);
        $this->writeAudit($reply, 'cancelled_auto', "hard_gate_late:{$decision->reason}");
        return;
    }

    SendReservationReplyJob::dispatchSync($reply);
}
```

**Wichtig:** Hard-Gates werden **zweimal** evaluiert – einmal bei Generierung, einmal vor dem tatsächlichen Versand. Zwischenzeitlich kann sich der Zustand ändern (z. B. `desired_at` rutscht durch Wartezeit unter `min_lead_time` → Hard-Gate `short_notice` greift jetzt).

### Killswitch

`POST /restaurants/{id}/send-mode/killswitch`:

```php
public function killswitch(Restaurant $restaurant): RedirectResponse
{
    $this->authorize('manageSendMode', $restaurant);

    DB::transaction(function () use ($restaurant) {
        $restaurant->update([
            'send_mode' => SendMode::Manual,
            'send_mode_changed_at' => now(),
            'send_mode_changed_by' => auth()->id(),
        ]);

        // Alle Replies im Cancel-Window sofort canceln
        $restaurant->reservationReplies()
            ->where('status', ReservationReplyStatus::ScheduledAutoSend)
            ->each(function (ReservationReply $reply) {
                $reply->update(['status' => ReservationReplyStatus::CancelledAuto]);
                AutoSendAudit::write($reply, 'cancelled_auto', 'killswitch');
            });
    });

    return back()->with('flash', 'Auto-Versand sofort gestoppt. Alle ausstehenden Versandvorgänge wurden abgebrochen.');
}
```

Killswitch ist im UI **immer prominent sichtbar**, sobald Mode `shadow` oder `auto` ist.

### Settings-UI

`resources/js/pages/Settings/SendMode.vue`:

- Drei-Karten-Layout, jeweils mit Erklärung, Risiko-Badge und „Aktivieren"-Button
- Aktive Karte visuell hervorgehoben
- Beim Wechsel zu `shadow`: schlanker Confirmation-Dialog
- Beim Wechsel zu `auto`: Confirmation-Dialog **mit Statistik** aus dem Shadow-Modus, falls vorhanden:
  > „In den letzten 30 Tagen wurden 47 von 50 Shadow-Antworten ohne Änderung übernommen (94 %).
  > Auto-Versand bedeutet: ab jetzt gehen Antworten automatisch an Gäste, sofern keine Sicherheitsregel greift.
  > Möchten Sie wirklich aktivieren?"
- Killswitch-Bereich rot hervorgehoben, immer sichtbar wenn Mode ≠ `manual`
- Hard-Gate-Konfiguration (Party-Size-Limit, Min-Lead-Time) als Number-Inputs mit Slider

Policy: **nur Owner** (nicht Staff) darf Mode wechseln (`RestaurantPolicy::manageSendMode`).

### Detail-Drawer-Erweiterung

`Dashboard.vue` Detail-Drawer für Shadow-Replies:

- Neuer Banner oben: „Shadow-Modus – diese Antwort wurde **nicht** versendet"
- Vergleichs-Block: Generierter Text + Hinweis „wäre versendet worden um {scheduled_at}"
- Button: „Doch manuell freigeben & versenden" (führt durch normalen V1.0-Approve-Flow)
- Wenn Owner den Body editiert → `shadow_was_modified = true` wird gesetzt (PRD-008-Analytik)

Im `auto`-Modus für Replies im Cancel-Window:

- Banner: „Wird automatisch versendet in {countdown}s" (Live-Countdown via Composable)
- Button „Versand abbrechen" → setzt Status auf `cancelled_auto`, schreibt Audit, dispatcht keinen Send-Job

---

## Akzeptanzkriterien

### Modus-Verwaltung
- [ ] Default-Mode neuer Restaurants ist `manual` (V1.0-kompatibel, keine Migration ändert bestehende Daten in der Funktion)
- [ ] Mode-Wechsel `manual → shadow` setzt `send_mode_changed_at` und `send_mode_changed_by`
- [ ] Mode-Wechsel `shadow → auto` zeigt Statistik im Dialog (Übernahme-Rate aus letzten 30 Tagen)
- [ ] Nur Owner kann Mode wechseln; Staff erhält 403
- [ ] Killswitch setzt Mode auf `manual` UND cancelt alle `scheduled_auto_send`-Replies in einer Transaktion

### Hard-Gates (jeder Gate einzeln testbar)
- [ ] `needs_manual_review` blockt Auto-Send unabhängig vom Modus
- [ ] `fallback_text` blockt Auto-Send (OpenAI-Fehler-Fallback)
- [ ] `party_size > limit` blockt Auto-Send
- [ ] `short_notice` (< `min_lead_time_minutes` Vorlauf) blockt Auto-Send
- [ ] `first_time_guest` (keine vorherige `confirmed` Reservation derselben Adresse) blockt Auto-Send
- [ ] `low_confidence_email` (< 0.9 Confidence aus PRD-003) blockt Auto-Send

### Modus-Verhalten
- [ ] Im `manual`-Modus: Verhalten 1:1 wie V1.0
- [ ] Im `shadow`-Modus: Reply mit `status = shadow`, `Mail::fake()` erhält **keinen** Send
- [ ] Im `auto`-Modus ohne Hard-Gate: Reply geht 60s nach Generierung automatisch raus
- [ ] Im `auto`-Modus mit Hard-Gate: Status bleibt `draft`, Auto-Send-Audit dokumentiert den Reason

### Cancel-Window
- [ ] Klick auf „Versand abbrechen" innerhalb von 60s → Status `cancelled_auto`, kein Mailversand
- [ ] Owner-Approve innerhalb des Cancel-Windows → V1.0-Pfad greift, Mail geht früher raus
- [ ] Race-Condition: zwei parallele Approves → nur einer schreibt (DB-Transaktion)

### Audit & Sichtbarkeit
- [ ] `auto_send_audits` enthält pro Auto-Send einen Eintrag mit `decision`, `reason`, `triggered_by_user_id`
- [ ] Bei Killswitch werden alle gecancelten Replies einzeln auditiert
- [ ] Dashboard-Banner zeigt aktiven Mode prominent
- [ ] Detail-Drawer zeigt bei Shadow den Vergleichs-Block, bei Cancel-Window den Countdown

### Sicherheit
- [ ] CLAUDE.md-Anpassung dokumentiert: V1.0-Verbot „Automatischer Mailversand ohne Freigabe" wird zu opt-in mit Hard-Gates
- [ ] Datenschutzerklärungs-Hinweis im Settings-UI für `auto` und `shadow`
- [ ] API-Key, IMAP-Passwort, Mail-Bodies erscheinen in keinem Audit-Eintrag oder Log

### Code-Qualität
- [ ] Alle Tests grün (`composer test`, `npm run test`)
- [ ] Pint, ESLint, Prettier ohne Findings
- [ ] Bestehende V1.0-Tests aus PRD-005 bleiben grün

---

## Tests

**Unit (`tests/Unit/AI/AutoSendDeciderTest.php`)**

- `it returns mode_manual when restaurant is in manual mode`
- `it returns mode_shadow when restaurant is in shadow mode`
- `it returns mode_auto when restaurant is in auto mode without hard gates`
- Pro Hard-Gate ein dedizierter Test:
  - `it blocks auto send when needs_manual_review is true`
  - `it blocks auto send for fallback text`
  - `it blocks auto send when party size exceeds limit`
  - `it blocks auto send for short notice requests`
  - `it blocks auto send for first time guests`
  - `it blocks auto send for low confidence email parses`
- `it considers a guest with prior confirmed reservation as returning`
- `it counts a guest with only declined prior reservations as first time` (declined zählt nicht als Vertrauen)

**Unit (`tests/Unit/AI/AutoSendDecisionTest.php`)**

- Value-Object-Konstruktor-Varianten (`manual`, `shadow`, `autoSend`)
- Serialisierung in `auto_send_decision`-JSON-Spalte und Re-Hydration

**Feature (`tests/Feature/AutoSend/SendModeFlowTest.php`)**

- `it dispatches scheduled job with 60s delay in auto mode` (mit `Queue::fake()`-Inspektion)
- `it does not send mail in shadow mode` (mit `Mail::fake()`)
- `it sends mail after 60s delay in auto mode` (Time-Travel mit `Carbon::setTestNow`)
- `it cancels scheduled send when owner clicks cancel`
- `it cancels scheduled send when owner approves manually within window`
- `it re-evaluates hard gates in scheduled job before sending` (z. B. `desired_at` ist zwischenzeitlich kurzfristig geworden)

**Feature (`tests/Feature/AutoSend/KillswitchTest.php`)**

- `it resets mode to manual on killswitch`
- `it cancels all scheduled auto sends within killswitch transaction`
- `it writes audit entry for each cancelled reply`
- `it forbids killswitch for staff users`

**Feature (`tests/Feature/AutoSend/SendModeSettingsTest.php`)**

- `it allows owner to switch from manual to shadow`
- `it shows confirmation dialog with stats when switching to auto from shadow`
- `it forbids staff from changing send mode`
- `it persists party size limit and min lead time changes`
- `it shows mode banner on dashboard when not manual`

**Feature (`tests/Feature/AutoSend/AuditTrailTest.php`)**

- `it writes an audit entry on every auto send decision`
- `it includes reason and triggering user`
- `it never logs guest email or reply body in audits`

---

## Zusammenarbeit mit dem Produktinhaber

Drei Werte sind UI-konfigurierbar, müssen aber sinnvolle Defaults haben:

| Wert                              | Default-Vorschlag | Begründung                                              |
|-----------------------------------|-------------------|---------------------------------------------------------|
| `auto_send_party_size_max`        | 10                | Größere Gruppen brauchen oft Sonderabsprachen           |
| `auto_send_min_lead_time_minutes` | 90                | < 90 min: Owner sollte persönlich kurz prüfen           |
| Cancel-Window-Dauer (`60s`)       | konstant          | Keine Konfiguration im UI – architektonische Konstante  |

Die ersten zwei können vom Owner an sein Restaurant angepasst werden. Die Cancel-Window-Dauer ist bewusst nicht UI-konfigurierbar – sie ist Teil des Sicherheitsmodells, nicht der Geschäftslogik.

**Definition of Done (Produktseite):**

- Pilot-Restaurant kann von `manual` schrittweise zu `auto` wechseln, ohne dass dabei eine fehlerhafte Mail rausgeht
- Mindestens 30 Tage `shadow`-Mode mit > 90 % Übernahme-Rate, bevor `auto` empfohlen wird (UI-Hint, kein Hard-Block)
- Datenschutzerklärung des Restaurants enthält Hinweis auf Auto-Versand

---

## Risiken & offene Fragen

- **Cancel-Window-Dauer ist Tradeoff** – 60 s ist Kompromiss zwischen „Owner kann stoppen" und „Gast wartet nicht zu lange". Bei Pilot-Daten neu bewerten. Eine zu lange Verzögerung untergräbt den USP („sofort beantwortet"); eine zu kurze macht den Killswitch nutzlos für Replies, die gerade rausgehen.
- **Erstmaliger Auto-Send-Fehler kostet Vertrauen** – ein einziger Halluzinations-Fall an einen Erstgast wäre desaströs. Genau deshalb ist `first_time_guest` ein Hard-Gate. Akzeptierte Konsequenz: Stammgäste profitieren, Erstkunden bekommen V1.0-Service.
- **DSGVO** – Auto-Versand ist nach Art. 22 DSGVO eine automatisierte Entscheidung im weiteren Sinne. Für die rechtliche Sauberkeit:
  - Owner muss den Mode aktiv aktivieren (kein Default `auto`)
  - Datenschutzerklärung des Restaurants muss Auto-Versand erwähnen
  - Gast muss Möglichkeit zur manuellen Bearbeitung haben (gegeben durch Antwort an Restaurant-Mailadresse)
- **`isFallbackText`-Match ist string-basiert** – wenn der Fallback-Text in PRD-005 jemals übersetzt oder restaurantspezifisch wird, verliert der Hard-Gate seine Wirkung. Gegenmaßnahme: zusätzlich ein Boolean-Flag `is_fallback` an `reservation_replies` einführen, das `OpenAiReplyGenerator` setzt. **Empfehlung: Flag-Spalte mitziehen** (eine Migration mehr, aber robuster).
- **Killswitch-Race** – zwischen Mode-Reset und Cancel-Schleife könnte ein anderer Job einen `ScheduledAutoSendJob` starten. Der Job re-checkt `Restaurant::send_mode`, bevor er versendet – damit ist die Race abgefangen.
- **CLAUDE.md-Update notwendig** – das aktuelle V1.0-Kernprinzip („Kein KI-generierter Text erreicht ohne menschliche Bestätigung einen Kunden") wird in V2.0 zu „Kein KI-generierter Text erreicht ohne menschliche Bestätigung **oder ausdrückliche Owner-Aktivierung mit Hard-Gates** einen Kunden". Diese Anpassung ist Teil der V2.0-Release-Planung, nicht Teil von PRD-007 selbst, aber muss vor V2.0-Release passiert sein.

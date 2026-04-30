# PRD-014: Sync-Web-Confirm bei eindeutig freien Slots

**Produkt:** reservation-agent
**Version:** V3.0
**Priorität:** P1 – einziger V3-Hebel auf direkte Besucher-Akquise
**Entwicklungsphase:** V3-Phase 2
**Voraussetzungen:** PRD-011 (Verfügbarkeits-Modell), PRD-007 (Auto-Send Hard-Gates)

---

## Problem

In V1.0 + V2.0 landet jede Web-Reservierungsanfrage als `status = new` im Dashboard und wartet auf Owner-Freigabe. Selbst im V2-`auto`-Modus (PRD-007) durchläuft die Anfrage erst die KI-Antwort-Pipeline, dann die Hard-Gates, dann eine Cancel-Window-Phase – frühestens nach 60 Sekunden, oft erst nach Minuten, geht eine Bestätigung raus.

Aus Gastsicht heißt das: „Ich habe gerade ein Reservierungs-Formular abgeschickt … und höre … erstmal nichts. Vielleicht kommt morgen eine Mail." Das ist genau der Moment, in dem viele Gäste das Vertrauen verlieren und parallel woanders anrufen.

Das V3-Brainstorming am 2026-05-01 hat diesen Punkt explizit identifiziert: **„Leute sind eher bereit online direkt zu buchen, wenn die Bestätigung sofort kommt."** Das ist der einzige V3-Hebel, der direkt auf die Konversionsrate des Online-Kanals wirkt.

## Ziel

Web-Reservierungsanfragen, die **deterministisch** frei sind (PRD-011 sagt „Slot frei, Tisch X passt") **und** alle Sync-Confirm-Hard-Gates erfüllen, werden synchron im Submit-Pfad bestätigt: KI-Antwort wird sofort generiert (Sync-Call mit 5 s Hard-Timeout), Mail wird sofort versendet (kein Queue-Delay), Bestätigungs-Seite zeigt „bestätigt – Mail kommt".

Bei jeder Verletzung der Hard-Gates **oder** Timeout des Sync-Pfades fällt das System auf den V1.0-Pfad zurück: `status = new`, normale Owner-Freigabe-Schleife. Der Gast sieht eine generischere Seite „Anfrage eingegangen – wir melden uns".

**Default ist `web_sync_confirm_enabled = false`.** Pro Restaurant opt-in im Settings-Bereich.

---

## Scope V3.0

### In Scope

- Neue Spalte `restaurants.web_sync_confirm_enabled` (Default `false`)
- Service `WebSyncConfirmDecider` – prüft Hard-Gates und entscheidet sync-vs-async
- Sync-Pfad: synchroner OpenAI-Call mit 5 s Timeout, synchroner SMTP-Versand
- Bestehender `StoreReservationRequest`-Controller (PRD-002) wird um Sync-Pfad erweitert (additiv)
- Hard-Gates aus PRD-007 wiederverwendet, plus zwei neue Sync-spezifische Gates:
  - `slot_not_deterministic_free` – PRD-011 sagt nicht eindeutig „frei"
  - `web_sync_confirm_disabled` – Restaurant-Setting nicht aktiviert
- Inertia-Bestätigungsseite zeigt eindeutigen Erfolgs-Text bei Sync-Confirm
- Settings-UI: Toggle „Online-Bestätigung sofort senden, wenn Slot eindeutig frei ist"
- Killswitch: `WEB_SYNC_CONFIRM_GLOBAL_KILL` als ENV-Var stoppt sofort alle Sync-Confirms unabhängig vom Restaurant-Setting

### Out of Scope

- **Auto-Confirm für E-Mail-Anfragen** – PRD-014 betrifft nur den Web-Submit-Pfad. E-Mail-Anfragen laufen weiter über PRD-007 (asynchroner Auto-Send mit Cancel-Window)
- **Auto-Confirm für Telefon-Reservierungen aus PRD-012** – Telefon-Reservierungen sind per Definition vom Owner selbst angelegt und brauchen keine Bestätigung
- **Confidence-basiertes Sync-Confirm** (z. B. „nur wenn KI-Vertrauen > 0.95") – V4
- **Pro-Restaurant-konfigurierbare Hard-Gate-Schwellen** – V4
- **Scheduling von Sync-Confirms** (z. B. „nur zwischen 8 und 22 Uhr") – V4

---

## Technische Anforderungen

### Datenmodell

**Erweiterung `restaurants`:**

| Spalte                       | Typ     | Notiz                                          |
|------------------------------|---------|------------------------------------------------|
| web_sync_confirm_enabled     | boolean | Default `false`, opt-in pro Restaurant         |

**Erweiterung `reservation_replies`:**

| Spalte                       | Typ     | Notiz                                          |
|------------------------------|---------|------------------------------------------------|
| sync_confirm                 | boolean | true wenn über PRD-014-Sync-Pfad versendet     |

`sync_confirm` ermöglicht Analytics-Auswertung „wie oft greift Sync-Confirm" in PRD-008 ohne neuen Audit-Pfad.

### WebSyncConfirmDecider

`app/Services/AutoSend/WebSyncConfirmDecider.php`:

```php
final class WebSyncConfirmDecider
{
    public function __construct(
        private readonly SlotAvailability $availability,
        private readonly LoggerInterface $logger,
    ) {}

    public function decide(ReservationRequest $request): WebSyncConfirmDecision
    {
        // 1. Globaler Killswitch
        if (config('reservations.web_sync_confirm.kill', false)) {
            return WebSyncConfirmDecision::skip('global_kill');
        }

        // 2. Pro-Restaurant-Toggle
        if (! $request->restaurant->web_sync_confirm_enabled) {
            return WebSyncConfirmDecision::skip('web_sync_confirm_disabled');
        }

        // 3. Slot deterministisch frei?
        $slot = $this->availability->forSlot(
            $request->restaurant_id,
            CarbonImmutable::parse($request->desired_at),
            $request->party_size,
        );

        if ($slot->state !== SlotState::Free) {
            return WebSyncConfirmDecision::skip('slot_not_deterministic_free');
        }

        // 4. Hard-Gates aus PRD-007 wiederverwenden
        if ($request->party_size > $request->restaurant->auto_send_party_size_max) {
            return WebSyncConfirmDecision::skip('party_size_over_limit');
        }

        $minLead = $request->restaurant->auto_send_min_lead_time_minutes;
        if (CarbonImmutable::parse($request->desired_at)->diffInMinutes(now()) < $minLead) {
            return WebSyncConfirmDecision::skip('short_notice');
        }

        // first_time_guest und needs_manual_review werden in den Sync-Pfad NICHT übernommen:
        // Web-Form-Anfragen sind strukturiert (kein Parser-Risiko), und first_time_guest ist
        // auf dem Online-Kanal kein sinnvoller Filter (alle Web-Erstgäste wären betroffen).
        // Entscheidung dokumentiert in den Risiken unten.

        return WebSyncConfirmDecision::proceed($slot);
    }
}
```

`WebSyncConfirmDecision` ist ein DTO mit `state: 'proceed' | 'skip'`, `reason: ?string`, `slot: ?SlotAvailabilityResult`.

### Controller-Erweiterung

`StoreReservationRequest`-Controller (PRD-002) bekommt nach der `ReservationRequest::create(...)`-Zeile einen Sync-Confirm-Pfad:

```php
$reservation = ReservationRequest::create($validated);

$decision = $decider->decide($reservation);

if ($decision->state === 'proceed') {
    try {
        // Sync-OpenAI-Call mit Hard-Timeout 5s
        $reply = $generator->generateSync($reservation, timeout: 5);

        // Auto-Tisch-Zuweisung aus PRD-011
        ReservationTableAssignment::create([
            'reservation_request_id' => $reservation->id,
            'table_id' => $decision->slot->suggestedTableId,
            'assigned_at' => now(),
            'assigned_by_user_id' => null,
        ]);

        // Sync-Mail-Versand
        Mail::to($reservation->guest_email)->send(
            new ReservationReplyMail($reply, syncConfirm: true)
        );

        $reservation->update(['status' => ReservationStatus::Confirmed]);
        $reply->update(['sync_confirm' => true, 'status' => 'sent', 'sent_at' => now()]);

        return Inertia::render('PublicForm/ConfirmedSync', [
            'reservation' => $reservation,
        ]);
    } catch (Throwable $e) {
        $this->logger->warning('web sync confirm failed, falling back to v1 path', [
            'reservation_id' => $reservation->id,
            'reason' => $e::class,
            // KEINE Mail-Inhalte / Gäste-Daten geloggt
        ]);
        // Fallthrough zum V1-Pfad
    }
}

// V1-Pfad: Status bleibt new, normale Inertia-Bestätigungsseite
return Inertia::render('PublicForm/Submitted', [
    'reservation' => $reservation,
]);
```

`OpenAiReplyGenerator::generateSync` ist neu. Der bestehende `generate` ist async (für die Job-Pipeline). `generateSync` setzt einen 5 s Timeout am OpenAI-HTTP-Client. Bei Timeout: `OpenAiTimeoutException` → Fallthrough.

### Settings-UI

`resources/js/pages/Settings/AutoSend.vue` (existiert aus PRD-007) bekommt einen zusätzlichen Block:

```
┌──────────────────────────────────────────────────────────────┐
│  Online-Sofort-Bestätigung                                   │
├──────────────────────────────────────────────────────────────┤
│  Wenn Web-Anfragen einen eindeutig freien Slot treffen,      │
│  wird die Bestätigung direkt nach dem Absenden versandt.     │
│                                                              │
│  Greift NICHT bei:                                           │
│  • Slot ist knapp oder belegt                                │
│  • Gruppen größer als 8 Personen (Standard, anpassbar)       │
│  • Vorlauf weniger als 2 Stunden                             │
│                                                              │
│  [✓] Online-Sofort-Bestätigung aktivieren                    │
└──────────────────────────────────────────────────────────────┘
```

### Globaler Killswitch

`config/reservations.php`:

```php
'web_sync_confirm' => [
    'kill' => env('WEB_SYNC_CONFIRM_GLOBAL_KILL', false),
],
```

Setzt `WEB_SYNC_CONFIRM_GLOBAL_KILL=true` in `.env` → kein einziger Sync-Confirm wird mehr ausgelöst, unabhängig vom Restaurant-Setting. Wichtig für Incidents (z. B. OpenAI-Outage führt zu reihenweisen 5 s Timeouts → Sync-Pfad temporär aus).

### Mailable-Anpassung

`ReservationReplyMail` aus PRD-005 + PRD-006 bekommt ein optionales Konstruktor-Argument `syncConfirm: bool`. Im Mail-Template wird im Header die Zeile „Diese Bestätigung wurde automatisch erstellt" eingeblendet, wenn `syncConfirm = true`. Das ist Transparenz dem Gast gegenüber – im Auto-Send aus PRD-007 gibt es das gleiche Pattern.

### Bestätigungsseiten

`PublicForm/ConfirmedSync.vue` – neue Seite, zeigt:

- ✅ Reservierung bestätigt
- Datum, Uhrzeit, Personenzahl als Klartext
- „Bestätigung haben wir an `m…@example.com` geschickt" (Mail maskiert)
- Optional: Footer-Hinweis „diese Bestätigung wurde automatisiert ausgesprochen, weil der Slot frei war"

`PublicForm/Submitted.vue` – die bestehende Seite aus PRD-002, unverändert. Wird beim V1-Fallback gezeigt.

---

## Akzeptanzkriterien

- [ ] Migration für `restaurants.web_sync_confirm_enabled` und `reservation_replies.sync_confirm` läuft vor- und rückwärts
- [ ] Default für neue Restaurants: `web_sync_confirm_enabled = false`
- [ ] Sync-Pfad läuft nur wenn alle vier Bedingungen erfüllt: Toggle on, Slot `Free`, Personenzahl ≤ Cap, Vorlauf ≥ Min-Lead
- [ ] Sync-OpenAI-Call hat Hard-Timeout 5 s, bei Timeout: Fallthrough zum V1-Pfad ohne UI-Fehler
- [ ] Sync-Mail-Versand ist nicht-queued; Versagen führt zu Fallthrough mit Status `new` (und nicht zu UI-Fehler)
- [ ] Globaler Killswitch greift unabhängig vom Restaurant-Setting
- [ ] `sync_confirm = true` wird auf der erzeugten `reservation_reply` gesetzt, sodass PRD-008-Analytics die Sync-Quote messen kann
- [ ] Bei Sync-Confirm wird eine `ReservationTableAssignment` automatisch erzeugt (aus PRD-011-Vorschlag)
- [ ] Bestätigungs-Mail enthält die „automatisiert"-Hinweis-Zeile
- [ ] User aus anderem Restaurant kann das Toggle für ein fremdes Restaurant nicht setzen (Policy)
- [ ] Logs enthalten **keine** PII bei Sync-Pfad-Fehlern
- [ ] `./vendor/bin/pint --test`, `npm run lint`, `npm run format:check` ohne Findings

---

## Tests

**Unit (`tests/Unit/AutoSend/`)**

- `WebSyncConfirmDeciderTest::it_skips_when_global_kill_is_active`
- `WebSyncConfirmDeciderTest::it_skips_when_restaurant_toggle_is_off`
- `WebSyncConfirmDeciderTest::it_skips_when_slot_is_not_deterministic_free`
- `WebSyncConfirmDeciderTest::it_skips_when_party_size_over_limit`
- `WebSyncConfirmDeciderTest::it_skips_when_short_notice`
- `WebSyncConfirmDeciderTest::it_proceeds_when_all_gates_pass`

**Feature (`tests/Feature/AutoSend/`)**

- `WebSyncConfirmFlowTest::sync_confirm_succeeds_and_sends_mail` (`Mail::fake` mit `assertSent`)
- `WebSyncConfirmFlowTest::sync_confirm_falls_back_on_openai_timeout` (`OpenAI::fake` mit `throw new OpenAiTimeoutException`)
- `WebSyncConfirmFlowTest::sync_confirm_falls_back_on_smtp_failure` (`Mail::fake` mit `shouldFailNext()`)
- `WebSyncConfirmFlowTest::sync_confirm_creates_table_assignment`
- `WebSyncConfirmFlowTest::v1_path_when_disabled_for_restaurant`
- `WebSyncConfirmFlowTest::confirmed_sync_page_renders_when_proceed`
- `WebSyncConfirmFlowTest::submitted_page_renders_when_skipped_or_failed`

**Integration mit PRD-007**

- `WebSyncConfirmFlowTest::email_source_does_not_use_sync_confirm` – nur `web_form` als Source-Trigger

---

## Risiken & offene Fragen

- **Sync-OpenAI-Call kann teuer sein** – jeder erfolgreich aktivierte Sync-Confirm kostet einen API-Call innerhalb der Submit-Latenz. Bei OpenAI-Stau (>5 s) Timeout-Fallthrough greift sauber, aber wenn OpenAI generell langsam ist, würde jede Web-Anfrage 5 s warten. Mitigation: Killswitch + Monitoring der Sync-Quote in PRD-008. Bei Quote < 50 % erfolgreicher Sync-Confirms pro Tag: Toggle in den Settings als „nicht zuverlässig" markieren.
- **`first_time_guest` als Hard-Gate übernommen?** – Bewusste Entscheidung: **nein**. PRD-007 hat `first_time_guest` als Gate, weil der Auto-Send-Pfad eine längere Latenz toleriert und eine zweite Schutz-Schicht braucht. Beim Web-Sync ist die Frage stattdessen: „ist der Slot deterministisch frei?" – wenn ja, wäre `first_time_guest`-Filter eine Strafe für 90 % aller Online-Erstkunden, die den Hauptkanal überhaupt erst bringen sollen. Falls Pilot-Daten das widerlegen, wird's in V3.1 als zuschaltbarer Gate ergänzt.
- **`needs_manual_review` als Hard-Gate übernommen?** – `needs_manual_review` wird im IMAP-Parser (PRD-003) gesetzt, also nur für E-Mail-Source. Web-Form-Anfragen haben es per Definition nie gesetzt. Gate ist daher implizit erfüllt.
- **CLAUDE.md-Anpassung erneut nötig?** – Das Kernprinzip wurde in V2.0 bereits zu „… ohne menschliche Bestätigung **oder ausdrückliche Owner-Aktivierung mit Hard-Gates**" erweitert. PRD-014 fügt einen neuen Auto-Pfad hinzu, fällt aber unter denselben Disclaimer (Owner-Aktivierung + Hard-Gates). **Keine** weitere CLAUDE.md-Anpassung nötig. Das wird im Spec-Self-Review explizit verifiziert.
- **Race-Condition mit PRD-012-Telefon-Reservierung** – wenn parallel ein Owner am Telefon dieselben Slot reserviert: PRD-011-Locking greift in PRD-012, aber der Sync-Confirm-Pfad ruft `forSlot` (lesend) und macht dann `create` – ohne Lock. Fix: der Sync-Pfad muss seinen eigenen `lockForUpdate()` auf den vorgeschlagenen Tisch halten, analog zu PRD-012. Wird in Akzeptanzkriterien aufgenommen.
- **Logging-Lücke** – wenn Sync-Pfad versagt, soll der Owner sehen, **warum** im Dashboard. Aktuell wird nur ins Application-Log geschrieben, nicht ins Reservierungs-Detail. V3.0 lebt mit dem Compromise (Logs reichen für Pilot-Debugging). V3.1 könnte einen Drawer-Status „Sync-Confirm fehlgeschlagen: Timeout" zeigen.

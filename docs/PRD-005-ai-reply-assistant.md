# PRD-005: KI-Antwort-Assistent

**Produkt:** reservation-agent
**Version:** V1.0
**Priorität:** P1 – das eigentliche Differenzierungsmerkmal, aber erst nach den Basics wertvoll
**Entwicklungsphase:** Woche 4

---

## Problem

Selbst wenn alle Anfragen gebündelt in einem Dashboard liegen (PRD-004), bleibt das Formulieren der Antwort der zeitintensivste Schritt: Datum gegen Öffnungszeiten prüfen, Kapazität checken, höflichen Text schreiben, ggf. Alternativ-Uhrzeit vorschlagen. Hier soll die KI den Gastronomen entlasten – aber ohne die Kontrolle über Verfügbarkeit oder Versand abzugeben.

## Ziel

Für jede neue `ReservationRequest` erzeugt das System automatisch einen Antwortvorschlag. Der Vorschlag basiert auf **deterministisch berechneten** Verfügbarkeitsdaten (von Laravel, nicht von der KI) und wird in der pro Restaurant konfigurierten Tonalität formuliert. Der Gastronom sieht den Vorschlag im Dashboard, bearbeitet ihn bei Bedarf und versendet ihn per Klick.

---

## Scope V1.0

### In Scope

- Listener auf `ReservationRequestReceived` dispatcht `GenerateReservationReplyJob`
- `ReservationContextBuilder` Service baut deterministisches Context-JSON
- `OpenAiReplyGenerator` Service erzeugt Antworttext via `openai-php/laravel`
- System-Prompt und User-Prompt-Struktur fest definiert, Tonalität aus Restaurant übernommen
- Persistierung als `ReservationReply` mit `status = draft`
- Snapshot des Context-JSON in `ai_prompt_snapshot` (Reproduzierbarkeit & Debugging)
- UI-Erweiterung im Dashboard-Detail-Drawer:
  - Vorschlag anzeigen
  - Textfeld zum Bearbeiten
  - Button „Freigeben & Versenden"
  - Status-Badge „KI-Vorschlag verfügbar"
- `POST /reservation-replies/{id}/approve` – setzt `status = approved`, triggert Mailversand
- `SendReservationReplyJob` versendet über Laravel Mail
- Fallback-Verhalten bei OpenAI-Fehlern (401/429/5xx/Timeout)
- Tests mit `OpenAI::fake()` und `Mail::fake()`

### Out of Scope

- Automatischer Versand ohne Freigabe (V2.0 – erst wenn Vertrauen etabliert)
- Lernen aus manuellen Edits des Gastronomen (V2.0, RAG/Fine-Tuning)
- Mehrsprachige Antworten (V2.0)
- Alternativ-Vorschläge für „ausgebucht"-Fälle mit mehreren Zeitslots (V1.1)

---

## Technische Anforderungen

### Trigger-Kette

```
ReservationRequestReceived (Event aus PRD-002/003)
        │
        ▼
GenerateReservationReplyListener   (synchron, queued-dispatch)
        │
        ▼
GenerateReservationReplyJob        (queue: default)
        │
        ├─► ReservationContextBuilder::build() → Context-JSON
        ├─► OpenAiReplyGenerator::generate($context) → String
        └─► ReservationReply::create([... 'status' => 'draft'])
```

### ReservationContextBuilder

`app/Services/AI/ReservationContextBuilder.php`:

- Input: `ReservationRequest $request`
- Output: assoziatives Array mit:

```php
[
    'restaurant' => [
        'name'     => '...',
        'tonality' => 'casual', // formal|casual|family
    ],
    'request' => [
        'guest_name'  => '...',
        'party_size'  => 4,
        'desired_at'  => '2026-05-12 19:30', // Lokalzeit des Restaurants
        'message'     => '...',
    ],
    'availability' => [
        'is_open_at_desired_time' => true,
        'seats_free_at_desired'   => 12,
        'alternative_slots'       => [
            '2026-05-12 19:00',
            '2026-05-12 20:00',
        ],
        'closed_reason'           => null, // z. B. 'ruhetag' | 'ausserhalb_oeffnungszeiten'
    ],
]
```

- `is_open_at_desired_time` prüft die `opening_hours` aus PRD-001
- `seats_free_at_desired` = `restaurant.capacity` minus Summe der `party_size` aller bereits **bestätigten** Reservierungen in einem ±2h-Fenster um `desired_at`
- `alternative_slots` – bis zu 3 Slots im Abstand von 30 Min, die frei und innerhalb der Öffnungszeiten sind
- **Laravel rechnet. Keine dieser Zahlen stammt je von der KI.**

### OpenAiReplyGenerator

`app/Services/AI/OpenAiReplyGenerator.php`:

```php
final class OpenAiReplyGenerator
{
    public function __construct(
        private readonly \OpenAI\Client $client,
        private readonly LoggerInterface $logger,
    ) {}

    public function generate(array $context): string
    {
        try {
            $response = $this->client->chat()->create([
                'model' => config('reservation.openai_model', 'gpt-4o-mini'),
                'temperature' => 0.4,
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt($context['restaurant']['tonality'])],
                    ['role' => 'user',   'content' => json_encode($context, JSON_UNESCAPED_UNICODE)],
                ],
            ]);

            return trim($response->choices[0]->message->content ?? $this->fallback());
        } catch (\Throwable $e) {
            $this->logger->warning('openai reply generation failed', [
                'error' => $e->getMessage(),
                // NIEMALS API-Key oder vollen Context loggen
            ]);
            return $this->fallback();
        }
    }

    private function fallback(): string
    {
        return "Vielen Dank für Ihre Anfrage. Wir melden uns in Kürze persönlich bei Ihnen.";
    }
}
```

### Konfiguration des System-Prompts

`config/reservation.php`:

```php
return [
    'openai_model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    'tonality_prompts' => [
        'formal' => 'Du schreibst im Namen eines gehobenen Restaurants. ...',
        'casual' => 'Du schreibst im Namen eines entspannten Bistros. ...',
        'family' => 'Du schreibst im Namen eines familienfreundlichen Restaurants. ...',
    ],
];
```

Die konkreten Prompt-Texte werden in **Phase 2 dieses PRD** (siehe „Zusammenarbeit mit dem Produktinhaber") festgelegt.

### System-Prompt-Regeln (konstant, tonalitätsunabhängig)

Der System-Prompt MUSS zusätzlich zum Tonalitäts-Block folgende harten Regeln enthalten:

1. Antworte ausschließlich auf Deutsch.
2. Verwende NUR die im User-JSON enthaltenen Zahlen und Zeiten. Erfinde keine eigenen.
3. Wenn `is_open_at_desired_time = false`, biete höflich die `alternative_slots` an oder verweise auf `closed_reason`.
4. Wenn `seats_free_at_desired < request.party_size`, lehne höflich ab und biete Alternativen an.
5. Antworte in maximal 120 Wörtern.
6. Keine Emojis, keine Hashtags, keine Marketing-Phrasen.
7. Beginne mit Anrede („Guten Tag [Name],"), ende mit Grußformel und Restaurant-Name.

### Versand-Pfad

`POST /reservation-replies/{reply}/approve`:

1. Policy: `reply->reservationRequest->restaurant_id` gehört zum eingeloggten User
2. `reply->status = approved`, `approved_by = auth()->id()`, `approved_at = now()`
3. Falls der Gastronom den Text im Drawer editiert hat: neuer `body` überschreibt den Draft
4. `SendReservationReplyJob::dispatch($reply)`
5. Job versendet via `Mail::to($reply->reservationRequest->guest_email)->send(new ReservationReplyMail($reply))`
6. Bei Erfolg: `reply->status = sent`, `sent_at = now()`, `reservationRequest->status = replied`
7. Bei Mailversand-Fehler: `reply->status = failed`, `error_message` gesetzt, sichtbar im Dashboard

---

## Akzeptanzkriterien

- [ ] Neue Reservierung → innerhalb von 60 s liegt ein `ReservationReply` mit `status = draft` in der DB
- [ ] `ai_prompt_snapshot` enthält das vollständige Context-JSON
- [ ] Dashboard-Detail-Drawer zeigt den Vorschlag und erlaubt Bearbeiten
- [ ] „Freigeben & Versenden" → Mail geht raus, Status wechselt auf `sent`/`replied`
- [ ] Editierter Text (statt Original-Vorschlag) wird tatsächlich versendet
- [ ] OpenAI-Timeout → Fallback-Text gespeichert, Request bleibt `needs_manual_review = true`
- [ ] OpenAI-401 → Admin-Benachrichtigung im Dashboard („OpenAI-Key prüfen"), keine Retries
- [ ] OpenAI-429 → einmaliger Retry nach 60 s, dann Fallback
- [ ] API-Key taucht in **keinem** Log-Eintrag auf
- [ ] Bei `is_open_at_desired_time = false` enthält der generierte Text niemals eine Zusage
- [ ] Bei `seats_free < party_size` enthält der Text niemals eine Zusage
- [ ] Alle Tests grün

---

## Tests

**Unit (`tests/Unit/ReservationContextBuilderTest.php`)**

- Happy Path: offenes Zeitfenster, genug Plätze → `is_open = true`, `seats_free > party_size`
- Außerhalb der Öffnungszeiten → `is_open = false`, `closed_reason = 'ausserhalb_oeffnungszeiten'`, `alternative_slots` befüllt
- Ruhetag → `closed_reason = 'ruhetag'`, `alternative_slots = []`
- Voll belegt → `seats_free < party_size`, `alternative_slots` aus nahe gelegenen Zeitfenstern
- Restaurant-Zeitzone ≠ Server-Zeitzone → Kapazitäts-Fenster liegt korrekt

**Unit (`tests/Unit/OpenAiReplyGeneratorTest.php`)**

Mit `OpenAI::fake([...])`:

- Prompt wird mit System + User + JSON korrekt zusammengebaut
- Response-Content wird getrimmt und zurückgegeben
- Exception (Throwable) → Fallback-String
- API-Key erscheint nicht im Log (mit Test-Key `sk-test-LEAK-CHECK-12345`)

**Feature (`tests/Feature/ReservationReplyFlowTest.php`)**

- `it creates a draft reply when a new reservation request is received` (mit `OpenAI::fake()`)
- `it stores the context snapshot` 
- `it sends an approved reply via mail` (mit `Mail::fake()`)
- `it uses the edited body when operator modified the draft`
- `it marks reply as failed when mail sending throws`
- `it forbids approval of replies from other restaurants`
- `it uses the fallback text when openai fails` (OpenAI-Fake mit Exception)

---

## Zusammenarbeit mit dem Produktinhaber

Die Tonalitäts-Prompts (`formal`, `casual`, `family`) in `config/reservation.php` bestimmen die Produktqualität stärker als jeder Code – sie müssen vom Produktinhaber / Restaurant-Betreiber mit-formuliert werden. **Platzhalter** stehen im Config-File, die finalen Texte werden vor PRD-005-Go-Live mit einem Pilot-Restaurant getestet und iteriert.

**Definition of Done für die Prompts:**

- Jede Tonalität produziert auf 5 Test-Szenarien (frei, voll, Ruhetag, außerhalb Öffnungszeit, Großgruppe) eine Antwort, die der Betreiber ohne Änderung versenden würde
- Keine Antwort enthält erfundene Daten (Stichprobe: 20 Generationen, 0 Halluzinationen)

---

## Risiken & offene Fragen

- **KI halluziniert trotz Regeln** – Absicherung durch die `ai_prompt_snapshot`, manuelle Freigabe und klare System-Prompt-Regeln. Bei jedem beobachteten Halluzinations-Fall: Test mit dem exakten Context anlegen, Prompt nachschärfen.
- **Kosten** – `gpt-4o-mini` kostet pro Antwort < 0,002 €; bei 500 Anfragen/Monat vernachlässigbar. Höhere Modelle (Opus/Sonnet) erst auf Kundenwunsch.
- **Latenz** – Synchroner OpenAI-Call dauert ~3–10 s. Deshalb als Queue-Job (nicht im Request-Lifecycle des öffentlichen Formulars).
- **Datenschutz** – Gast-Daten werden an OpenAI übertragen. Muss in AGB/Datenschutzerklärung erwähnt sein; BYOK-Modell hilft, Verantwortung zu klären. Alternative: Azure OpenAI (V1.1), Anthropic Claude via BYOK (V1.1).
- **Freigabe-UX** – wenn der Gastronom immer nur „Freigeben" klickt, wird der Workflow zur Placebo-Sicherheit. Gegenmaßnahme: Im UI Deltas gegenüber dem Vorschlag hervorheben, wenn editiert wurde.

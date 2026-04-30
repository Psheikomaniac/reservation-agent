# PRD-006: Mail-Threading

**Produkt:** reservation-agent
**Version:** V2.0
**Priorität:** P0 – Voraussetzung für PRD-007 (Auto-Versand)
**Entwicklungsphase:** V2-Phase 1

---

## Problem

In V1.0 erkennt PRD-003 jede eingehende Mail als neue Reservierungsanfrage. Wenn ein Gast auf eine vom System versendete Bestätigungsmail antwortet (z. B. *„Vielen Dank, sehen uns Freitag!"* oder *„Können wir doch noch auf 19:30 verschieben?"*), entsteht eine **zweite** Reservierung im Dashboard – obwohl es ein Folgekommentar zur ersten ist.

Das hat drei Folgen:

1. **Dubletten** – der Gastronom muss manuell mergen, was zeitintensiv und fehleranfällig ist.
2. **Falsche Statistiken** – jede Reply zählt als neue Anfrage in PRD-008-Reports.
3. **Auto-Versand wäre gefährlich** – ohne Threading würde PRD-007 im `auto`-Modus auf eine Reply wie *„Danke!"* mit einer kompletten Reservierungsbestätigung antworten. Das ist peinlich und kostet Vertrauen.

PRD-003 hat dieses Problem explizit nach V2.0 verschoben (siehe [`docs/PRD-003-email-ingestion.md`](PRD-003-email-ingestion.md) Zeile 39 und 151).

## Ziel

Eingehende Mails werden anhand der RFC-2822-Threading-Header (`Message-ID`, `In-Reply-To`, `References`) und einer Subject-Marker-Heuristik einer bereits existierenden `ReservationRequest` zugeordnet. Treffer landen als `ReservationMessage` an der bestehenden Reservierung – kein neuer Request entsteht. Outbound-Mails (von PRD-005 und PRD-007) tragen ihrerseits eine stabile Message-ID und einen Subject-Marker, damit Replies sauber zuordenbar sind.

Wo Threading nicht eindeutig auflösbar ist, fällt das System auf das V1.0-Verhalten zurück: neuer `ReservationRequest` mit `needs_manual_review = true`. Niemals wird eine Reply einer *falschen* Reservierung zugeordnet (Spoofing-Sicherheit).

---

## Scope V2.0

### In Scope

- Neue Tabelle `reservation_messages` für die Thread-Historie pro Reservierung (in- und outbound)
- Erweiterung `reservation_replies.outbound_message_id` (eindeutige RFC-2822-Message-ID jeder versendeten Mail)
- Service `ThreadResolver` mit vier Resolution-Strategien (`In-Reply-To`, `References`, Subject-Marker, Heuristik)
- Cross-Check Absender-Adresse vs. `guest_email` der Ziel-Reservierung (Anti-Spoofing)
- Automatisches Einfügen des Subject-Markers `[Res #<id>]` in jede outbound Mail (PRD-005-Mailable + PRD-007-Mailable)
- Stabile Message-ID-Generation in `ReservationReplyMail` via `withSymfonyMessage`
- Erweiterung des Dashboard-Detail-Drawers um eine Thread-Ansicht (chronologisch: Original-Anfrage → ausgehende Antworten → eingehende Replies)
- Erweiterung von `EmailReservationParser` und `FetchReservationEmailsJob` um den `ThreadResolver`-Schritt **vor** der Anlage einer neuen Reservierung
- Idempotenz: gleiche `Message-ID` wird nie doppelt importiert (egal ob als neue Reservation oder als Thread-Message)

### Out of Scope

- Forwarding-Heuristik (Gast leitet Bestätigung an seinen Partner weiter, der antwortet aus anderer Adresse) – V3.0
- Conversational Auto-Reply (KI antwortet automatisch auf eine Gast-Reply) – V4.0
- Inhaltsbasierte Status-Übergänge aus Replies (*„Ich storniere"* → `cancelled`) – V4.0
- Threading für Web-Form-Anfragen (Web-Anfragen sind atomar, kein Thread-Konzept)

---

## Technische Anforderungen

### Datenmodell

**Neue Tabelle `reservation_messages`** (Migration additiv, kein Schema-Bruch):

| Spalte                  | Typ            | Notiz                                                  |
|-------------------------|----------------|--------------------------------------------------------|
| id                      | bigint PK      |                                                        |
| reservation_request_id  | bigint FK      | indexiert                                              |
| direction               | string         | `in` \| `out`                                          |
| message_id              | string unique  | RFC-2822-Message-ID, eindeutig über alle Restaurants   |
| in_reply_to             | string null    | aus Header                                             |
| references              | text null      | gesamte References-Kette (Whitespace-separated)        |
| subject                 | string         |                                                        |
| from_address            | string         | Absender (Outbound: System-Mail, Inbound: Gast)        |
| to_address              | string         | Empfänger                                              |
| body_plain              | text           | Plaintext-Repräsentation                               |
| raw_headers             | text           | komplette Headers (für Audit + spätere Heuristiken)    |
| sent_at                 | datetime null  | bei `direction = out`                                  |
| received_at             | datetime null  | bei `direction = in`                                   |
| created_at, updated_at  | timestamps     |                                                        |

Index: `(reservation_request_id, created_at)` für Drawer-Query.
Unique: `message_id` (verhindert doppelten Import).

**Erweiterung `reservation_replies`:**

| Spalte                | Typ           | Notiz                                                  |
|-----------------------|---------------|--------------------------------------------------------|
| outbound_message_id   | string null   | RFC-2822-Message-ID der versendeten Mail; FK-loose zu `reservation_messages.message_id` |

`outbound_message_id` ist `null` solange `status = draft`, wird beim Versand gesetzt.

### ThreadResolver

`app/Services/Email/ThreadResolver.php` – ein zustandsloser Service mit nur einer öffentlichen Methode:

```php
final class ThreadResolver
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Versucht, eine eingehende Mail einer existierenden Reservierung zuzuordnen.
     * Liefert null, wenn keine eindeutige Zuordnung möglich ist.
     */
    public function resolveForIncoming(\Webklex\PHPIMAP\Message $message, int $restaurantId): ?ReservationRequest
    {
        // Strategie 1: In-Reply-To → bekannte outbound Message-ID
        if ($id = $this->byInReplyTo($message, $restaurantId)) {
            return $this->verifySender($id, $message);
        }

        // Strategie 2: References → eine bekannte Message-ID in der Kette
        if ($id = $this->byReferences($message, $restaurantId)) {
            return $this->verifySender($id, $message);
        }

        // Strategie 3: Subject-Marker [Res #<id>]
        if ($id = $this->bySubjectMarker($message, $restaurantId)) {
            return $this->verifySender($id, $message);
        }

        // Strategie 4: Heuristik: Subject "Re: <bekanntes Subject>" + gleicher Absender + < 30 Tage
        if ($id = $this->byHeuristic($message, $restaurantId)) {
            return $this->verifySender($id, $message);
        }

        return null;
    }

    private function verifySender(ReservationRequest $request, \Webklex\PHPIMAP\Message $message): ?ReservationRequest
    {
        $from = strtolower(trim((string) $message->getFrom()[0]?->mail ?? ''));
        $expected = strtolower(trim((string) $request->guest_email));

        if ($from === '' || $expected === '' || $from !== $expected) {
            $this->logger->warning('thread resolver: sender mismatch, falling back to new reservation', [
                'reservation_id' => $request->id,
                'message_id'     => (string) $message->getMessageId(),
                // Adressen werden NICHT geloggt
            ]);
            return null;
        }

        return $request;
    }
}
```

**Strategie-Details:**

- **`byInReplyTo`** – sucht in `reservation_messages.message_id` (direction = out) nach dem Wert von `In-Reply-To`. Bei Treffer wird die zugehörige `ReservationRequest` geladen, eingeschränkt auf das Restaurant.
- **`byReferences`** – splittet den `References`-Header an Whitespace, sucht für jede ID nach einer outbound Message. Erste Übereinstimmung gewinnt.
- **`bySubjectMarker`** – Regex `/\[Res #(\d+)\]/` im Subject. Falls die ID zu einer Reservation des Restaurants gehört: Treffer.
- **`byHeuristic`** – nur als Fallback: Subject beginnt mit `Re: `, Rest des Subjects matcht das Subject einer outbound Mail dieser Reservation aus den letzten 30 Tagen, **und** die Absender-Adresse entspricht `guest_email`. Diese Strategie ist die schwächste und wird bewusst nur als letzter Versuch eingesetzt.

`verifySender` ist nicht-verhandelbar: jede positive Resolution wird zusätzlich gegen die Gast-Mail geprüft. Spoofing eines `In-Reply-To` auf fremde Reservierungen wird so abgewehrt.

### Integration in den Fetch-Job

`FetchReservationEmailsJob` aus PRD-003 wird angepasst (additiv, kein Patternbruch):

```php
foreach ($messages as $message) {
    if ($this->alreadyImported($message->getMessageId())) {
        continue;
    }

    if ($threadParent = $this->threadResolver->resolveForIncoming($message, $this->restaurantId)) {
        $this->appendAsThreadMessage($threadParent, $message);
        $message->setFlag('Seen');
        continue;
    }

    // V1.0-Pfad: neue Reservation anlegen
    $parsed = $this->parser->parse($message);
    // ... unverändert
}
```

`alreadyImported` prüft `reservation_messages.message_id` UND `reservation_requests.email_message_id` (PRD-003-Spalte). Damit ist Idempotenz auch über den Modus-Wechsel hinweg garantiert.

### Outbound-Pfad

`app/Mail/ReservationReplyMail.php` (aus PRD-005) wird erweitert:

```php
public function build(): self
{
    return $this
        ->subject($this->buildSubjectWithMarker())
        ->view('mail.reservation-reply')
        ->with(['reply' => $this->reply])
        ->withSymfonyMessage(function (Email $email) {
            $messageId = $this->generateMessageId();
            $email->getHeaders()->addIdHeader('Message-ID', $messageId);

            // Falls bereits frühere Outbound-Mails existieren: References-Kette setzen
            if ($previous = $this->previousMessageIds()) {
                $email->getHeaders()->addTextHeader('References', implode(' ', $previous));
                $email->getHeaders()->addTextHeader('In-Reply-To', end($previous));
            }

            $this->reply->update(['outbound_message_id' => $messageId]);
        });
}

private function buildSubjectWithMarker(): string
{
    $marker = sprintf('[Res #%d]', $this->reply->reservation_request_id);
    $subject = $this->originalSubject ?? sprintf('Reservierung bei %s', $this->restaurant->name);

    return str_contains($subject, $marker) ? $subject : "{$subject} {$marker}";
}

private function generateMessageId(): string
{
    return sprintf('<reservation-%d-%s@%s>',
        $this->reply->id,
        bin2hex(random_bytes(8)),
        config('mail.from.address') ? parse_url('mailto:'.config('mail.from.address'))['path'] ?? 'localhost' : 'localhost'
    );
}
```

**Wichtig:** `generateMessageId` nutzt `random_bytes` (vgl. CLAUDE.md-Verbote: keine eigene Krypto, aber `random_bytes` ist Laravel-Standard).

Nach erfolgreichem Versand (`SendReservationReplyJob`) wird zusätzlich ein `ReservationMessage` mit `direction = out` angelegt, damit der Thread auch im Detail-Drawer vollständig sichtbar ist.

### Dashboard-Erweiterung

`resources/js/pages/Dashboard.vue` Detail-Drawer:

- Neuer Tab/Abschnitt „Verlauf"
- Lädt `reservation_messages` der Reservation chronologisch (separater Endpoint `GET /reservations/{id}/messages`, Resource-basiert)
- Pro Eintrag: Richtung-Icon (↑ outbound, ↓ inbound), Datum, Subject, Body (Plaintext), Absender
- Outbound-Mails zeigen zusätzlich den User, der die Antwort freigegeben hat (PRD-005)

API-Resource `ReservationMessageResource` mappt nur die Felder, die das UI braucht – `raw_headers` bleibt server-seitig.

---

## Akzeptanzkriterien

- [ ] Outbound-Mail enthält stabile, eindeutige `Message-ID` (`<reservation-{id}-{random}@{domain}>`)
- [ ] Outbound-Subject enthält automatisch den `[Res #<id>]`-Marker
- [ ] Zweite Outbound-Mail an dieselbe Reservation setzt korrekten `References`-Header und `In-Reply-To`
- [ ] Inbound-Reply mit passender `In-Reply-To` wird als `reservation_message` an existierende Reservation gehängt – **kein neuer Request**
- [ ] Inbound-Reply ohne Threading-Header, aber mit `[Res #<id>]` im Subject und passender Absender-Adresse wird zugeordnet
- [ ] Inbound-Reply mit gespooftem `In-Reply-To` (Absender ≠ `guest_email`) → Sender-Mismatch greift, Resolution liefert null, Fallback auf neuer Request mit `needs_manual_review = true`
- [ ] Idempotenz: gleiche `Message-ID` (in- oder outbound) wird nie zweimal in die DB aufgenommen
- [ ] Detail-Drawer zeigt Thread chronologisch
- [ ] Bestehende V1.0-Tests aus PRD-003 bleiben grün
- [ ] Alle neuen Tests grün
- [ ] `./vendor/bin/pint --test` ohne Findings, `npm run lint` und `npm run format:check` ohne Findings

---

## Tests

**Unit (`tests/Unit/Email/ThreadResolverTest.php`)**

- `it resolves by in-reply-to header` – Outbound-Message in DB, Inbound mit passendem `In-Reply-To` → Treffer
- `it resolves by references chain` – `In-Reply-To` unbekannt, aber `References`-Kette enthält bekannte ID
- `it resolves by subject marker` – nur `[Res #42]` im Subject, keine Header → Treffer
- `it resolves by heuristic when within 30 days`
- `it does NOT resolve by heuristic when older than 30 days`
- `it rejects spoofed in-reply-to (sender mismatch)` – Treffer wird durch Sender-Check verworfen
- `it returns null when no strategy matches`
- `it does not match across restaurants` – outbound Message gehört zu Restaurant A, Inbound trifft Restaurant B → keine Resolution

**Feature (`tests/Feature/Email/ThreadingFlowTest.php`)**

- `it appends reply as thread message instead of creating new reservation`
- `it falls back to new reservation when sender does not match`
- `it generates stable message id for outbound mail` (mit `Mail::fake()` und Header-Inspection)
- `it inserts subject marker for outbound mail`
- `it builds references chain on second outbound mail`
- `it dedupes by message id even with thread fallback`
- `it lists thread messages in detail drawer chronologically`

**Fixtures**

`tests/Fixtures/emails/threading/`:
- `reply-with-in-reply-to.eml`
- `reply-with-references-only.eml`
- `reply-with-subject-marker-only.eml`
- `reply-spoofed-in-reply-to.eml` (`In-Reply-To` zeigt auf bekannte ID, aber Absender weicht ab)
- `reply-heuristic-match.eml`
- `reply-no-match.eml`

---

## Risiken & offene Fragen

- **Spoofing über gefälschte Absender** – wenn ein Angreifer sowohl `In-Reply-To` als auch die `From`-Adresse fälscht, würde die Reply einer fremden Reservation zugeordnet. Das ist kein Threading-Problem, sondern ein Mail-Authentizitäts-Problem (DKIM/SPF). V2.0 löst es nicht; V3.0 sollte DKIM-Validierung erwägen.
- **Mailclients normalisieren Subjects unterschiedlich** – `Re:`, `RE:`, `AW:`, `Antw:`, `Re[2]:`. Die Heuristik nimmt das in Kauf und vergleicht Subjects nach Strip-Pattern (`/^(re|aw|antw)(\[\d+\])?:\s*/i`).
- **References-Header kann lang werden** – RFC empfiehlt Trimming auf ~1000 Zeichen. Wir behalten die DB-Spalte als `text`, aber outbound trimmen wir auf die letzten 10 Message-IDs der Kette.
- **Thread-Drift** – wenn ein Gast in einer Reply ein völlig neues Anliegen schreibt („Ach übrigens, übermorgen 6 Personen?"), wird das aktuell als Thread-Message und nicht als neue Reservation gespeichert. Das ist akzeptabler V2.0-Tradeoff – PRD-007-Auto-Send schaltet sich bei diesem Pattern ohnehin nicht ein, weil keine neue `ReservationRequest` entsteht und damit keine `desired_at` zur Auto-Send-Entscheidung vorliegt. PRD-008-Analytics zeigt aber Thread-Volumen, sodass Drift erkennbar wird.
- **DSGVO** – `reservation_messages.body_plain` und `raw_headers` enthalten personenbezogene Daten. Sie unterliegen derselben Aufbewahrungsfrist wie `ReservationRequest` (siehe Decision in [`docs/decisions/failed-email-imports-retention.md`](decisions/failed-email-imports-retention.md)).

# PRD-003: E-Mail-Ingestion (IMAP)

**Produkt:** reservation-agent
**Version:** V1.0
**Priorität:** P0 – zweite Reservierungsquelle neben dem Web-Formular
**Entwicklungsphase:** Woche 2–3

---

## Problem

Ein großer Teil der Reservierungen kommt bei Restaurants per E-Mail an – teils Freitext, teils aus generischen Kontaktformularen, teils aus Portalen, die per Mail weiterleiten. Heute werden diese Mails manuell gelesen, ins System getippt und beantwortet. Das ist fehleranfällig und skaliert nicht.

## Ziel

Das System holt in regelmäßigen Abständen Mails aus einem IMAP-Postfach des Restaurants, versucht Datum, Uhrzeit, Personenzahl und Kontaktdaten heuristisch zu extrahieren und legt für jede Mail einen `ReservationRequest` mit `source = email` an. Mails, die nicht eindeutig parsbar sind, landen mit Teilinformationen und `needs_manual_review = true` im Dashboard. Unparsbare Mails werden in einer Quarantäne-Tabelle aufgehoben, der Fetch-Lauf bricht **nicht** ab.

---

## Scope V1.0

### In Scope

- Scheduled Job `FetchReservationEmailsJob`, läuft alle 5 Minuten pro Restaurant mit konfiguriertem IMAP
- IMAP-Zugriff via `webklex/laravel-imap`
- Abruf **unread** Mails, Markierung als gelesen nach erfolgreicher Verarbeitung (nicht löschen)
- Parser-Service `EmailReservationParser` mit heuristischer Extraktion
- Unsichere Treffer → `needs_manual_review = true`
- Idempotenz via eindeutiger `message_id` (Unique-Index auf `reservation_requests.raw_payload->message_id` oder separate Spalte)
- Fehlerhafte Mails landen in `failed_email_imports` (Tabelle aus PRD-001)
- IMAP-Credentials verschlüsselt gespeichert (Laravel Encrypted Cast)
- Schedule-Eintrag in `routes/console.php` bzw. `app/Console/Kernel.php`
- Tests mit Fixture-Mails

### Out of Scope

- OAuth / XOAUTH2 für Google/Microsoft – V1.0 nutzt IMAP mit App-Passwort
- NLP-basierte Extraktion (spaCy, LLM-Parsing) – V1.0 bleibt rein regex/heuristisch
- Antwort-Threading (Zuordnung von späteren Mails zu derselben Reservierung) – V2.0
- Anhänge (PDF, Screenshots) – V2.0

---

## Technische Anforderungen

### Job

`app/Jobs/FetchReservationEmailsJob.php`:

- Constructor: `public function __construct(public int $restaurantId)`
- Lädt Restaurant, baut IMAP-Client via `Client::make([...])`, verbindet
- Holt `inbox()->messages()->unseen()->get()`
- Pro Message:
  1. `message_id` aus Header extrahieren – falls bereits in DB: Skip
  2. `EmailReservationParser::parse($message)` aufrufen
  3. Bei Erfolg: `ReservationRequest::create([...])` mit `source = email`, `needs_manual_review` je nach Parser-Confidence
  4. Message als `seen` markieren
  5. Event `ReservationRequestReceived` dispatchen
- Bei Exception pro Message: Eintrag in `failed_email_imports`, Loop läuft weiter
- Bei Verbindungsfehler: Exception bubbled hoch → Laravel-Retry (max. 3, exponentielles Backoff)

### Parser

`app/Services/Email/EmailReservationParser.php`:

- Input: `\Webklex\PHPIMAP\Message`
- Output: DTO `ParsedReservation` mit Feldern: `guestName`, `guestEmail`, `guestPhone?`, `partySize?`, `desiredAt?`, `message`, `confidence` (float 0–1), `messageId`
- Regex-basierte Extraktion:
  - **Personenzahl**: `/(?:für|for)?\s*(\d{1,2})\s*(Personen|Pers\.?|Gäste|People|Guests)/i`
  - **Datum**: ISO, deutsch (`01.05.2026`, `1. Mai 2026`), englisch (`May 1, 2026`) – via Carbon-Parser mit mehreren Try-Formaten
  - **Uhrzeit**: `/(\d{1,2})[:.](\d{2})\s*(Uhr|h|am|pm)?/`
  - **E-Mail**: Absender-Header als Fallback, im Body gefundene Mails bevorzugen wenn Absender eine No-Reply-Adresse ist
  - **Name**: Absender-Display-Name, bei Leerzeichen-Pattern im Body-Anfang („Mein Name ist ...")
- Confidence-Score:
  - 1.0: Datum + Uhrzeit + Personenzahl sauber extrahiert → `needs_manual_review = false`
  - 0.5–0.9: 1–2 Felder sauber → `needs_manual_review = true`
  - < 0.5: nur Freitext → `needs_manual_review = true`, `desired_at = null`

### Sicherheit

- `imap_password` am Restaurant mit `'encrypted'` Cast in `$casts`
- Passwörter niemals in Logs (`Log::info`-Aufrufe nur mit `host` + `username`, nie mit Credentials)
- Mail-Body in Logs nur auf Level `debug`, und nur in lokaler Umgebung

### Idempotenz

Zwei Optionen – in PRD-003 entschieden: **separate Spalte**.

- Migration ergänzt `reservation_requests` um `email_message_id` (string, nullable, unique)
- Parser liefert `messageId`, Job schreibt ihn in die Spalte
- Unique-Index verhindert Doppelimport auch bei Race Conditions

### Scheduling

`routes/console.php`:

```php
Schedule::call(function () {
    Restaurant::whereNotNull('imap_host')->each(function ($r) {
        FetchReservationEmailsJob::dispatch($r->id);
    });
})->everyFiveMinutes()->withoutOverlapping();
```

---

## Akzeptanzkriterien

- [ ] Schedule läuft alle 5 Minuten, dispatcht einen Job pro Restaurant mit IMAP-Config
- [ ] Neue Mail im Testpostfach → nach nächstem Lauf existiert `ReservationRequest` mit `source = email`
- [ ] Klartext-Mail mit sauberem Datum/Uhrzeit/Personenzahl → `needs_manual_review = false`
- [ ] HTML-Mail wird in Plaintext konvertiert und korrekt geparst
- [ ] Mail mit Umlauten (`ä ö ü ß`) kommt korrekt in DB an (UTF-8 durchgängig)
- [ ] Mail ohne erkennbares Datum → Request angelegt, `needs_manual_review = true`, `desired_at = null`
- [ ] Zweiter Lauf importiert dieselbe Mail **nicht** noch einmal (Idempotenz)
- [ ] Mail, die Parser crashen lässt → Eintrag in `failed_email_imports`, Job läuft weiter
- [ ] IMAP-Verbindung schlägt fehl → Job retried 3× mit Backoff, dann Exception ins Log (ohne Credentials)
- [ ] IMAP-Passwort liegt in DB verschlüsselt vor, nicht als Klartext
- [ ] Alle Tests grün

---

## Tests

**Unit (`tests/Unit/EmailReservationParserTest.php`)**

Mit Fixture-Mails in `tests/Fixtures/emails/`:

- `sauber-deutsch.eml` – „Hallo, ich hätte gerne einen Tisch für 4 Personen am 12.05.2026 um 19:30. Gruß, Anna Müller" → alle Felder, confidence = 1.0
- `html-nur.eml` – HTML-Mail → Plaintext-Konvertierung, dieselben Felder
- `kein-datum.eml` – „Guten Tag, haben Sie am Wochenende noch was frei?" → confidence < 0.5, `desired_at = null`
- `englisch.eml` – „Table for 6 on May 1st, 2026 at 7pm" → geparst
- `umlaut-im-namen.eml` – Absender `Müller, Jürgen <j@x.de>` → UTF-8 korrekt
- `no-reply-absender.eml` – Absender `noreply@portal.de`, Body enthält `kunde@gmail.com` → kunde@gmail.com wird Primary

**Feature (`tests/Feature/FetchReservationEmailsJobTest.php`)**

- `it skips mails without imap config` (Restaurant ohne `imap_host`)
- `it is idempotent for the same message-id`
- `it writes unparsable mails to failed_email_imports`
- `it marks processed mails as seen`
- `it dispatches ReservationRequestReceived per successful parse`

Für Feature-Tests wird die IMAP-Verbindung per Mock ersetzt (`webklex/laravel-imap` erlaubt das Ersetzen des `Client` via Service Container).

---

## Risiken & offene Fragen

- **Parser-Qualität bei Freitext** – heuristische Regeln werden nie 100 % treffen. Die Sicherheit liegt darin, bei Unsicherheit `needs_manual_review = true` zu setzen und dem Gastronomen die Originalmail im Dashboard zu zeigen. Kein Gast darf eine KI-Antwort erhalten, die auf falsch geparsten Daten basiert.
- **Antworten auf unsere Antworten** – wenn der Gast auf eine vom System versendete Mail antwortet (PRD-005), wird das aktuell als **neue** Reservierung erkannt. Threading kommt erst in V2.0; für V1.0 akzeptieren wir Dubletten im Dashboard und verlassen uns auf manuellen Merge.
- **Portal-Mails mit strukturierten Daten** – manche Portale senden JSON/Tabellen in Mails. V1.0 parst sie wie Freitext. Dedizierte Template-Parser (pro Portal) sind V2.0.
- **DSGVO** – Mails enthalten personenbezogene Daten. Aufbewahrungsfrist und Löschprozess für `failed_email_imports` müssen vor Go-Live festgelegt werden (Vorschlag: 30 Tage automatische Löschung).

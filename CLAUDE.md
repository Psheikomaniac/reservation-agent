# CLAUDE.md – reservation-agent

## Projektübersicht

**reservation-agent** ist ein zentrales Reservierungssystem für die Gastronomie mit KI-Unterstützung bei der Antwortgenerierung. Reservierungsanfragen aus verschiedenen Quellen (eigenes Web-Formular, E-Mail-Postfach) werden gebündelt in einem Dashboard dargestellt. Ein KI-Assistent erzeugt Antwortvorschläge, die der Gastronom vor dem Versand freigibt.

**Zielgruppe:** Inhabergeführte Restaurants und kleine Gastronomie-Ketten (1–5 Standorte), die Reservierungen aktuell manuell über mehrere Kanäle bearbeiten.

**Stand:** V1.0 in Planung – siehe [`docs/README.md`](docs/README.md).

---

## Kernarchitekturprinzip (NICHT verletzen)

```
Laravel entscheidet (deterministisch)    KI formuliert (Text)
─────────────────────────────────        ──────────────────────
Tischverfügbarkeit (DB-Abfrage)     →    Freundlicher Antworttext
Öffnungszeiten-Prüfung                   Formulierung: Bestätigung/Ablehnung
Kapazitäts-/Slot-Regeln                  Tonalität passend zum Restaurant
Status-Übergänge (new→replied→...)       Auf Deutsch, höflich, eindeutig
```

**Die KI entscheidet niemals über Verfügbarkeit.** Sie erhält ein fertiges JSON-Objekt mit Verfügbarkeitsdaten von Laravel und formuliert daraus einen Text. Jede automatische Antwort durchläuft in V1.0 einen **Freigabe-Workflow** – kein KI-generierter Text erreicht ohne menschliche Bestätigung einen Kunden.

---

## Technischer Stack

| Schicht         | Technologie                              |
|-----------------|------------------------------------------|
| Backend         | PHP 8.2+, Laravel 12                     |
| Frontend        | Inertia.js v2, Vue 3.5, TypeScript       |
| Styling         | Tailwind CSS v4, Reka UI                 |
| Routing (FE)    | Ziggy (`route()`-Helper in Vue)          |
| DB              | SQLite (lokal), MySQL/Postgres (Prod)    |
| Queue           | `database`-Driver lokal, `sync` in Tests |
| KI              | `openai-php/laravel` (BYOK)              |
| E-Mail-Ingest   | `webklex/laravel-imap`                   |
| Mail-Versand    | Laravel Mail (SMTP aus `.env`)           |
| Tests           | Pest 3 + `RefreshDatabase`               |
| Lint/Format     | Laravel Pint, ESLint, Prettier           |

---

## Laravel-Konventionen

### Verzeichnisstruktur

```
app/
├── Enums/                  (z. B. ReservationStatus, ReservationSource)
├── Events/                 (ReservationRequestReceived, ReservationReplyApproved)
├── Http/
│   ├── Controllers/
│   ├── Requests/           (FormRequests – Validierung gehört hierher)
│   └── Resources/          (API Resources – JSON-Contract)
├── Jobs/                   (FetchReservationEmails, GenerateReservationReply)
├── Models/
├── Policies/               (RestaurantPolicy, ReservationRequestPolicy)
└── Services/               (ReservationContextBuilder, OpenAiReplyGenerator, ...)
```

### Standards

- **PSR-12** via Laravel Pint (`./vendor/bin/pint`)
- **PHP 8.2+ Features**: `readonly`, Enums, Named Arguments, Match-Expressions
- **Type Declarations**: alle Parameter und Rückgabewerte typisiert – kein `mixed` ohne Kommentar
- **Final Classes** als Standard für Services
- **Eloquent/Query Builder** – kein direktes SQL
- **FormRequests** für Validierung – Validierung NIE in Controllern
- **API Resources** für JSON-Antworten – kein direktes Model-Rendering
- **Enums** in `app/Enums/` mit Eloquent Casts (wie Plotdesk `AiFeedbackStatus`)
- **Events** für Domain-Aktionen, Listener dispatchen Jobs
- **Services zustandslos** – Constructor-Injection, keine statischen Caller

### Migrations

- Eine Migration pro Schema-Änderung
- Bestehende Migrations **niemals rückwirkend ändern** – neue Migration schreiben
- Immer `up()` UND `down()` implementieren
- Keine Seed-Daten in Migrations (dafür sind Seeders da)

### Fehlerbehandlung

- Externe Calls (OpenAI-API, IMAP, SMTP) immer in try/catch
- **Graceful Degradation**: API-Fehler → Platzhalter-Antwort, Request bleibt `needs_manual_review`, kein Datenverlust
- Exceptions über `LoggerInterface` loggen – **niemals API-Keys, IMAP-Passwörter oder Mail-Inhalte roh loggen**
- HTTP-Mapping OpenAI: 401 → Admin-Benachrichtigung, 429 → 1× Retry (60 s), 5xx/Timeout → Fallback

---

## Frontend-Konventionen

### Directory Conventions

| Concern                     | Location                              |
|-----------------------------|---------------------------------------|
| Inertia pages               | `resources/js/pages/`                 |
| Shared Vue components       | `resources/js/components/`            |
| UI primitives (shadcn/reka) | `resources/js/components/ui/`         |
| TypeScript interfaces       | `resources/js/types/index.d.ts`       |
| Web routes                  | `routes/web.php`                      |
| Feature tests               | `tests/Feature/`                      |

### Patterns

- **Inertia pages** werden über `Inertia::render('PageName', [...props])` gerendert. Pfadkonvention: `Inertia::render('Dashboard')` → `resources/js/pages/Dashboard.vue`.
- **Partial Reloads** (`router.reload({ only: ['requests'] })`) sind das Standard-Mittel für Aktualisierungen – **keine eigenen `fetch`/`axios`-Calls aus Vue**. Der Server ist Single Source of Truth.
- **Polling**: Im Dashboard läuft ein Poll nur so lange, wie eine Pending-Condition existiert (z. B. offene Anfragen), und stoppt sobald die Bedingung wegfällt. Intervall 30 s.
- **Optimistic UI** nur für nicht-kritische Aktionen (z. B. Status-Filter-Chips), nicht für Status-Änderungen an Reservierungen.
- **Ziggy**: nach neuen Routes `php artisan ziggy:generate` ausführen, wenn TypeScript Route-Types benötigt werden.

---

## Test-Strategie

### Pflichtregeln

- **Kein Feature ohne Tests** – PRs ohne Tests werden nicht gemerged
- **Kein Merge bei failing Tests** – CI muss grün sein
- Feature-Tests mit `uses(RefreshDatabase::class)` pro Datei
- Unit-Tests laufen ohne Datenbank – Repositories/Services werden gemockt

### Tools

- **Pest 3** mit Laravel-Plugin
- **`Queue::fake()`** wenn getestet wird, dass Jobs dispatched werden (ohne sie auszuführen)
- **`OpenAI::fake([...])`** aus `openai-php/laravel` für KI-Mocks
- **`Mail::fake()`** für Mail-Versand-Tests
- **Fixture-Mails** für IMAP-Parser-Tests (Klartext, HTML, mit Umlauten, mit/ohne strukturierter Anfrage)

### Was MUSS getestet werden

**`ReservationContextBuilder`:**
- Verfügbare Slots rund um Wunschzeit bei voller/halber/leerer Auslastung
- Außerhalb der Öffnungszeiten → keine Slots, klarer Grund
- Restaurant geschlossen (Ruhetag) → keine Slots
- Edge: Anfrage für Vergangenheit → abgewiesen mit Validierungsfehler

**`OpenAiReplyGenerator`:**
- Prompt wird korrekt aus Context-JSON zusammengestellt (**ohne echten API-Call**)
- Fallback-Text bei jedem Fehlertyp (401, 429, 5xx, Timeout)
- API-Key taucht nicht in generierten Strings, Logs oder Exceptions auf

**`FetchReservationEmails` Job:**
- Parser erkennt Datum, Uhrzeit, Personenzahl aus Beispiel-Mails
- Unklarheit → `needs_manual_review = true`, keine Halluzination
- Idempotenz: gleiche Message-ID wird nicht doppelt importiert
- Fehlerhafte Mail landet in `failed_email_imports`, Import läuft weiter

**`StoreReservationRequest` Controller/FormRequest:**
- Happy Path: gültige Anfrage erzeugt `ReservationRequest` mit `status = new`, `source = web_form`
- Validierungsfehler: Datum in Vergangenheit, Personenzahl < 1 oder > 20, ungültige E-Mail
- Honeypot gefüllt → 200 OK ohne Persistenz (Bot darf keinen Unterschied sehen)
- Rate-Limiting: > N Requests pro IP/Minute → 429

**Dashboard (Feature-Test):**
- Benutzer sieht nur Reservierungen seines Restaurants (Policy-Durchsetzung)
- Filter kombinieren sich korrekt (Status × Quelle × Datum)
- Nicht authentifizierter User → Redirect auf Login

**Status-Maschine:**
- Nur erlaubte Übergänge (`new → in_review → replied → confirmed`, `new → declined`, etc.)
- Idempotenz: doppelte Freigabe versendet nicht zweimal

---

## Sicherheit

- **Secrets nur in `.env`**: `OPENAI_API_KEY`, `IMAP_PASSWORD`, `MAIL_PASSWORD`. Im UI maskiert anzeigen (letzte 4 Zeichen).
- **IMAP-Passwort** im Restaurant-Model über Laravel **Encrypted Casts** speichern, falls pro Restaurant konfigurierbar.
- **Öffentlicher Reservierungs-Endpoint** (`POST /r/{slug}/reservations`) mit Rate-Limiting (`throttle:10,1`) und Honeypot-Feld.
- **Kein KI-Output direkt an Kunden** – immer Freigabe durch Gastronom (siehe Kernprinzip).
- **Autorisierung via Policy** (`RestaurantPolicy`, `ReservationRequestPolicy`) – **niemals** `where('restaurant_id', auth()->user()->restaurant_id)` im Controller. Tenant-Scope gehört in Policies und globale Eloquent Scopes.
- **CSRF-Schutz** aktiv auf allen Inertia/Web-Routes (Laravel-Default).
- **Logs**: niemals Mail-Bodies, Gäste-Namen oder -E-Mails in Log-Level `info` – nur IDs.

---

## Verbote (NIEMALS tun)

- ❌ KI über Tischverfügbarkeit entscheiden lassen
- ❌ Automatischer Mailversand ohne menschliche Freigabe (in V1.0)
- ❌ API-Key / IMAP-Passwort in Logs, Exceptions oder JSON-Responses
- ❌ Direktes SQL statt Eloquent/Query Builder
- ❌ Validierung in Controllern (gehört in FormRequests)
- ❌ Tenant-Scope im Controller statt in Policies / globalen Scopes
- ❌ Tests modifizieren um failing Tests zu kaschieren
- ❌ Bestehende Migrations rückwirkend ändern
- ❌ Features aus „Bewusst NICHT in V1.0" heimlich einbauen
- ❌ `fetch`/`axios`-Calls aus Vue-Pages – immer Inertia Partial Reloads
- ❌ Eigene Krypto – `Str::random()`, `bin2hex(random_bytes())` oder Laravel-Encrypter nutzen

---

## Git-Workflow

### Branch-Naming

```
feature/001-project-foundation
feature/003-email-ingestion
fix/dashboard-filter-reset
refactor/reservation-context-builder
```

### Commit-Messages (Imperativ, Englisch)

```
Add ReservationRequest model with status enum
Implement IMAP fetch job with idempotent message-id guard
Fix honeypot bypass in public reservation form
Add Pest feature tests for dashboard authorization
```

### PR-Checkliste (vor Merge)

- [ ] Alle Tests grün (`composer test`)
- [ ] Laravel Pint ohne Fehler (`./vendor/bin/pint --test`)
- [ ] ESLint/Prettier ohne Fehler (`npm run lint && npm run format:check`)
- [ ] Keine TODOs im Produktionscode
- [ ] Akzeptanzkriterien aus dem PRD abgehakt
- [ ] Code Review (durch Claude oder Mensch) durchgeführt
- [ ] Bei neuen Routes: `php artisan ziggy:generate` ausgeführt

---

## Commands

```bash
# Development – startet alle vier Services parallel (Server, Queue, Logs, Vite)
composer run dev

# Tests
php artisan test                  # Alle Tests
./vendor/bin/pest                 # Alternative
composer test                     # Mit Config-Clear

# Lint & Format
./vendor/bin/pint                 # PHP Auto-Fix
./vendor/bin/pint --test          # PHP Dry-Run
npm run lint                      # ESLint Auto-Fix
npm run format                    # Prettier Auto-Fix
npm run build                     # TS-Check + Vite Build

# DB
php artisan migrate
php artisan migrate:fresh --seed

# Queue
php artisan queue:work --once     # Einen Job manuell verarbeiten

# Ziggy (nach neuen Routes)
php artisan ziggy:generate
```

---

## Offene V1.0-Entscheidungen

Diese Entscheidungen werden im jeweiligen PRD final festgelegt:

| Frage                                          | Festzulegen in |
|------------------------------------------------|----------------|
| Produktions-DB: MySQL oder Postgres?           | PRD-001        |
| Öffnungszeiten-Schema (JSON-Struktur)          | PRD-001        |
| Tonalitäts-Optionen der KI (formell/locker/…)  | PRD-005        |
| Max. Personenzahl pro Anfrage (Default 20)     | PRD-002        |
| IMAP-Fetch-Intervall (Default 5 min)           | PRD-003        |

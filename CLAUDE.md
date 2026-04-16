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

## Branch-Strategie (verbindlich)

Das Repository nutzt **zwei langlebige Branches** und kurzlebige Issue-Branches:

```
main   ──●─────────────────────●─────────  (stabil, nur „Release-Bündel")
          \                   /
dev     ───●──●──●──●──●──●──●─────────── (Integrations-Branch, hier wird gearbeitet)
              \  \  \  \
               feature/<nr>-… (kurzlebig, ein Branch pro Issue)
```

- **`main`** – immer eine stabile, lauffähige Version. **Niemals direkt committen, niemals direkt aus Issue-Branches mergen.** `main` wird ausschließlich aktualisiert, wenn `dev` eine sinnvoll geschnürte Menge an Features (z. B. ein abgeschlossener PRD oder eine definierte Release-Welle) erreicht hat. Dieser Schritt erfolgt **nur nach expliziter Freigabe** durch den Repo-Owner.
- **`dev`** – der laufende Integrations-Branch. Alle Issue-Branches gehen **von `dev` ab** und werden **nach `dev` zurückgemerged**. `dev` ist die Single Source of Truth für „was ist gerade im Bau".
- **`feature/<nr>-…` / `fix/<nr>-…` / `docs/<nr>-…` / `refactor/<nr>-…`** – ein Branch pro GitHub-Issue, wird **immer aus aktuellem `dev`** abgeleitet. Lebensdauer: vom Issue-Start bis zum PR-Merge nach `dev`. Danach lokal und remote löschen.

### Wann geht etwas nach `main`?

`dev` → `main` wird als eigener PR mit dem Titel `Release: <kurz-bezeichnung>` erstellt. Trigger:
- Ein vollständiger PRD ist auf `dev` durchimplementiert, getestet und reviewed
- Oder eine definierte Anzahl zusammenhängender Features ist auf `dev` grün
- Owner gibt explizit das Release frei

Bis dahin bleibt `main` **unangetastet**.

## Issue-getriebener Arbeits-Workflow (verbindlich)

Jede Code-Änderung folgt diesem Ablauf – **eine Schleife pro GitHub-Issue**:

1. **Issue auswählen** – das *kleinste noch offene* Issue aus `Psheikomaniac/reservation-agent` ziehen, das nicht durch andere Issues blockiert ist. Bevorzugt nach Label `foundation`, dann nach Issue-Nummer aufsteigend.
2. **Issue lesen** – Goal, Acceptance Criteria, Technical Notes und referenziertes PRD vollständig erfassen. Bei Unklarheit: nachfragen, nicht raten.
3. **Branch anlegen – aus aktuellem `dev`**:
   ```bash
   git checkout dev && git pull --ff-only origin dev
   git checkout -b feature/<issue-nr>-<kurz-slug>
   ```
   Naming: `feature/<nr>-…`, `fix/<nr>-…`, `docs/<nr>-…`, `refactor/<nr>-…`.
4. **Umsetzen** – streng nach den Acceptance Criteria. Bestehende Patterns fortführen, keine neuen einführen. So gut wie möglich – Lücken transparent machen, nicht kaschieren.
5. **Testen** – passende Pest-Tests (Unit + Feature) schreiben oder erweitern. `composer test`, `./vendor/bin/pint --test`, `npm run lint`, `npm run format:check` müssen grün sein.
6. **Commit** – atomare Commits in Imperativ-Englisch, jede Commit-Message referenziert das Issue (`Refs #<nr>` oder `Closes #<nr>` beim letzten Commit der Schleife).
7. **Push** – Branch zum Remote pushen.
8. **Pull Request** – PR **gegen `dev`** (nicht `main`!) erstellen via `gh pr create --base dev`. Titel: kurz und Issue-Nummer enthalten. Body:
   - `## Summary` – Was wurde umgesetzt (Bezug auf Acceptance Criteria)
   - `## Why` – Kontext aus Issue/PRD
   - `Closes #<nr>` als letzte Zeile, damit das Issue beim Merge automatisch geschlossen wird
   - `## Test plan` – Bullet-Liste der ausgeführten Checks
9. **Code Review** – Selbst-Review oder Claude-Review nach den Kriterien aus `/Users/private/CLAUDE.md` § 7. Gefundene Findings als PR-Kommentare festhalten und falls kritisch direkt fixen, dann erneut reviewen.
10. **Merge nach `dev`** – sobald Review sauber und CI grün, PR nach `dev` mergen. Issue-Branch lokal und remote löschen.
11. **Issue-Kommentar** – nach Merge ein abschließender Kommentar am Issue via `gh issue comment <nr>`:
    - Link zum PR
    - Stichpunktliste der erledigten Acceptance Criteria
    - Offene Punkte / Folge-Issues, falls vorhanden
12. **Erst dann das nächste Issue ziehen** – niemals zwei Issues parallel auf demselben Branch.

### Begleitregeln

- **Niemals** auf `main` oder `dev` direkt committen – jede Änderung läuft über PR.
- **Niemals** Issue-Branches direkt nach `main` mergen – Zwischenstation ist immer `dev`.
- **Niemals** mehrere Issues in einem PR bündeln – ein Issue, ein PR.
- Wenn ein Issue zu groß wirkt: Kommentar am Issue mit Vorschlag zur Aufteilung, dann auf Bestätigung warten.
- Bei blockierenden Abhängigkeiten (Issue X braucht Y): Kommentar am Issue, das blockierende Issue zuerst ziehen.
- Bei zerstörerischen Aktionen (Force-Push, Branch-Löschung auf `main`/`dev`, Migrations-Reset): vorher fragen.
- `dev` → `main` ist ein **expliziter Release-Schritt** und braucht Freigabe.

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

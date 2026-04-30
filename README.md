# reservation-agent – KI-gestütztes Reservierungssystem für die Gastronomie V1.0

[![CI](https://github.com/Psheikomaniac/reservation-agent/actions/workflows/ci.yml/badge.svg?branch=dev)](https://github.com/Psheikomaniac/reservation-agent/actions/workflows/ci.yml)

Ein zentrales Dashboard, das Reservierungsanfragen aus mehreren Kanälen bündelt und mit KI-Unterstützung beantwortet. **Zielgruppe:** inhabergeführte Restaurants und kleine Ketten, die Reservierungen aktuell manuell über mehrere Quellen bearbeiten.

---

## Problem

Gastronomen erhalten Reservierungsanfragen über viele Kanäle: die eigene Webseite, per E-Mail, über Portale. Das manuelle Prüfen, Beantworten und Abgleichen mit dem Tischplan kostet Zeit – und Anfragen, die zu spät beantwortet werden, gehen verloren.

## Lösung

Alle Anfragen landen in einem Dashboard. Ein KI-Assistent erzeugt auf Basis der **deterministisch berechneten** Tischverfügbarkeit einen Antwortvorschlag in der gewünschten Tonalität. Der Gastronom kontrolliert den Text, korrigiert bei Bedarf und gibt ihn per Klick frei.

---

## Kernprinzip

```
Laravel entscheidet (deterministisch)    KI formuliert (Text)
─────────────────────────────────        ──────────────────────
Tischverfügbarkeit                  →    Freundlicher Antworttext
Öffnungszeiten                           Formulierung der Antwort
Kapazitäts-Regeln                        Tonalität des Restaurants
Status-Übergänge                         Auf Deutsch, höflich
```

**Die KI entscheidet niemals über Verfügbarkeit. Sie formuliert nur.** Jede Antwort durchläuft in V1.0 einen Freigabe-Workflow.

---

## Features V1.0

| Nr. | Feature                                | PRD |
|-----|----------------------------------------|-----|
| 001 | Projekt-Grundstruktur & Datenmodell    | [PRD-001](docs/PRD-001-project-foundation.md) |
| 002 | Öffentliches Web-Reservierungs-Formular| [PRD-002](docs/PRD-002-web-reservation-form.md) |
| 003 | E-Mail-Ingestion (IMAP)                | [PRD-003](docs/PRD-003-email-ingestion.md) |
| 004 | Reservierungs-Dashboard                | [PRD-004](docs/PRD-004-reservation-dashboard.md) |
| 005 | KI-Antwort-Assistent                   | [PRD-005](docs/PRD-005-ai-reply-assistant.md) |

Die vollständige Übersicht mit Roadmap, Abhängigkeiten und Phasen steht in [`docs/README.md`](docs/README.md).

---

## Tech-Stack

| Schicht         | Technologie                              |
|-----------------|------------------------------------------|
| Backend         | PHP 8.2+, Laravel 12                     |
| Frontend        | Inertia.js v2, Vue 3.5, TypeScript       |
| Styling         | Tailwind CSS v4, Reka UI                 |
| DB              | SQLite (lokal), MySQL/Postgres (Prod)    |
| Queue           | `database`-Driver lokal, `sync` in Tests |
| KI              | `openai-php/laravel` (BYOK)              |
| E-Mail-Ingest   | `webklex/laravel-imap`                   |
| Tests           | Pest 3                                   |
| Lint/Format     | Laravel Pint, ESLint, Prettier           |

---

## Quickstart

> **Hinweis:** Das Projekt befindet sich in der Planungsphase. Der Quickstart wird funktionsfähig, sobald PRD-001 umgesetzt ist.

```bash
# 1. Dependencies installieren
composer install
npm install

# 2. .env vorbereiten
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed

# 3. Entwicklungsumgebung starten (Server + Queue + Vite + Logs)
composer run dev
```

### Ziggy (Route-Helper für TypeScript)

Route-Namen aus `routes/web.php` werden per `tightenco/ziggy` ins Frontend gespiegelt. Die Laufzeit-Daten liefert die Blade-Direktive `@routes` (in `resources/views/app.blade.php`). Für TypeScript-Autocomplete wird zusätzlich `resources/js/ziggy.d.ts` committed.

Nach dem Anlegen oder Umbenennen von Routes:

```bash
php artisan ziggy:generate --types-only resources/js/ziggy.d.ts
```

Die CI (`ziggy-drift`-Job) schlägt fehl, wenn die committete Datei veraltet ist.

Erforderliche Umgebungsvariablen (Details in [PRD-001](docs/PRD-001-project-foundation.md)):

```env
OPENAI_API_KEY=
OPENAI_MODEL=gpt-4o-mini
IMAP_HOST=
IMAP_USERNAME=
IMAP_PASSWORD=
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_USERNAME=
MAIL_PASSWORD=
```

---

## Verzeichnisstruktur (Ziel nach PRD-001)

```
reservation-agent/
├── app/
│   ├── Enums/              (ReservationStatus, ReservationSource)
│   ├── Events/             (Domain-Events)
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Requests/       (FormRequests)
│   │   └── Resources/      (API Resources)
│   ├── Jobs/               (Queue-Jobs)
│   ├── Models/
│   ├── Policies/
│   └── Services/           (ReservationContextBuilder, OpenAiReplyGenerator, ...)
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── docs/                   (PRDs)
├── resources/
│   └── js/
│       ├── pages/          (Inertia Pages)
│       └── components/
├── routes/
│   └── web.php
├── tests/
│   ├── Feature/
│   └── Unit/
├── CLAUDE.md               (Verhaltensregeln für Claude)
└── README.md
```

---

## Roadmap nach V1.0

Detail-Begründung & Versions-Themen für V3+ stehen im V3–V7-Roadmap-Spec: [`docs/superpowers/specs/2026-04-29-v3-plus-roadmap-design.md`](docs/superpowers/specs/2026-04-29-v3-plus-roadmap-design.md).

| Feature                                          | Version | Status                                                              |
|--------------------------------------------------|---------|---------------------------------------------------------------------|
| Mail-Threading                                   | V2.0    | scoped → [PRD-006](docs/PRD-006-mail-threading.md)                  |
| Automatischer Mailversand (opt-in mit Hard-Gates)| V2.0    | scoped → [PRD-007](docs/PRD-007-auto-send-trust-modes.md)           |
| Analytics / Reporting                            | V2.0    | scoped → [PRD-008](docs/PRD-008-dashboard-analytics.md)             |
| Export CSV/PDF                                   | V2.0    | scoped → [PRD-009](docs/PRD-009-export-csv-pdf.md)                  |
| Push / Sound-Alerts / Daily Digest (Code-Pfad)   | V2.0    | scoped → [PRD-010](docs/PRD-010-push-and-sound-alerts.md) (Sound-Assets nach V3.0 verschoben) |
| PRD-010 CC0-Sound-Assets                         | V3.0    | aus V2.0 verschoben (Asset-Auswahl, kein Code-Blocker)              |
| Tisch-Liste mit Belegungsstatus                  | V3.0    | (Operative Vollständigkeit)                                         |
| Manuelle Erfassung (Telefon / Walk-in)           | V3.0    |                                                                     |
| Warteliste passiv                                | V3.0    | aktive Warteliste mit Notify in V4                                  |
| WebSockets via Laravel Reverb (V3-Surfaces)      | V3.0    | Dashboard-Bestand vorerst Polling – siehe [decision](docs/decisions/polling-vs-websockets-v1.md) |
| DSGVO-Voll-Workflow Art. 15–22                   | V3.0    | siehe [decision](docs/decisions/failed-email-imports-retention.md)  |
| Tischplan grafisch                               | später  | aus V3 verschoben, Pilot-Feedback abwarten                          |
| Stammgast-Profil + Tags + Sprache                | V4.0    | Anker für V4 (Gast-zentriert)                                       |
| Aktive Warteliste mit Notify                     | V4.0    | aus V3 verschoben (braucht CRM-Daten)                               |
| No-Show-Tracking                                 | V4.0    |                                                                     |
| Mehrsprachige KI-Antworten (erkennen)            | V4.0    |                                                                     |
| KI lernt aus manuellen Edits (RAG)               | V4.0    | (KI-Differenzierung)                                                |
| Tonalität pro Restaurant statt global            | V5.0    | siehe [decision](docs/decisions/ai-tonality-prompts.md)             |
| Mehrsprachige Antworten ausgebaut (Pflege-UI)    | V5.0    |                                                                     |
| Lokale KI (Ollama / vLLM)                        | V5.0    | siehe [decision](docs/decisions/openai-data-protection.md)          |
| Embeddable Widget / iframe-Variante              | V5.0    | (eigene Reichweite, selbst-getrieben)                               |
| Promptbar / Erklärbarkeit im Dashboard           | V5.0    | baut auf RAG aus V4 auf                                             |
| OpenTable / TheFork / Quandoo Anbindung          | V6.0    | aus V5 verschoben (externe Wellen)                                  |
| Google Reserve / Google Business                 | V6.0    | aus V5 verschoben (externe Welle)                                   |
| Multi-Standort-Verwaltung > 5 Standorte          | V6.0    | aus V3 verschoben (Skalierungs-Feature)                             |
| Voll-CRM-Outreach (Geburtstag, Reaktivierung)    | V6.0    | aus V4-Idealbild verschoben                                         |
| Anzahlung / Deposit / No-Show-Gebühr             | V7.0    | markt-validierungspflichtig                                         |
| POS- / Kassensystem-Integration                  | V7.0    | markt-validierungspflichtig                                         |
| Schicht- / Personal-Bezug                        | V7.0    |                                                                     |
| Mobile App                                       | V7.0    | eigene Produkt-Linie                                                |
| White-Label / Partner-Modell                     | V7.0    |                                                                     |

---

## Branch-Strategie

```
main   ──●─────────────●─────────  stabil, nur Release-Bündel
          \           /
dev     ───●──●──●──●─────────────  Integrations-Branch (hier wird gearbeitet)
              \  \  \
               feature/<nr>-…       ein Branch pro GitHub-Issue
```

- **`main`** bleibt stabil und wird nur per Release-PR aus `dev` aktualisiert (nach expliziter Freigabe).
- **`dev`** ist der laufende Integrations-Branch. Alle Issue-Branches werden aus `dev` abgeleitet und dorthin zurückgemerged.
- Pro GitHub-Issue ein kurzlebiger Branch (`feature/<nr>-…`, `fix/<nr>-…`, `docs/<nr>-…`, `refactor/<nr>-…`), der per PR gegen `dev` gemerged und anschließend gelöscht wird.

Details und der vollständige Issue-Workflow stehen in [`CLAUDE.md`](CLAUDE.md).

---

## Self-Review pro Issue (Pflicht)

**Jeder PR gegen `dev` durchläuft vor dem Merge einen Self-Review.** Ohne sauberen Self-Review wird kein Issue als abgeschlossen betrachtet – auch dann nicht, wenn CI grün ist.

**Ablauf (Skill `code-review:code-review <PR#>`):**

1. Eligibility-Check: PR offen, kein Draft, noch kein Review-Comment vorhanden.
2. Fünf parallele Review-Lenses:
   - `CLAUDE.md`-Konformität
   - Bug-Scan auf den Diff
   - Git-History-Kontext (was wurde hier früher absichtlich entfernt / hinzugefügt?)
   - Kommentare aus früheren PRs an denselben Pfaden
   - Code-Comments vs. Implementierung (halten Docblocks ihre Versprechen?)
3. Scoring jedes Findings (0–100, parallel). Findings mit Score < 80 fallen raus.
4. Verbleibende Findings als PR-Kommentar mit `gh pr comment` festhalten und Permalink zur Stelle (`<sha>/path#L<from>-L<to>`).
5. Findings beheben, neuer Push, CI grün, **dann** mergen. Anschließend Issue manuell schließen mit Abschlusskommentar (Auto-Close greift bei `dev`-Merges nicht).

**Warum so streng:** Der Loop hat in PR #167 (`Closes #63`) einen tautologischen `assertSame(null, null)`-Test gefangen, der ohne Review auf `dev` gelandet wäre und alle nachfolgenden PRD-005-Issues mit falschen Annahmen über Env-Wiring belastet hätte. Der Aufwand pro Issue (~5–10 min) ist proportional zum Nutzen.

---

## Bekannte Fehler & Fixes

Nicht-triviale Fehler, in die wir im Projekt schon einmal gelaufen sind, werden in [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) gesammelt – mit Symptom, Ursache, Fix, Datum, Tags und PR-Referenz. Vor dem Debuggen eines diffusen Bugs (z. B. „Inertia-Klick passiert nichts", „Navigation crasht stumm") lohnt sich ein Blick in die Datei: wahrscheinlich sind wir schon einmal darüber gestolpert.

Wenn du selbst auf einen Fehler triffst, der mehr als 10 Minuten Suche gekostet hat oder dessen Ursache nicht aus dem Code-Diff ersichtlich war: **Eintrag ergänzen** (Vorlage steht oben in der Datei). So wird die nächste Suche kürzer.

---

## Status

- ✅ Konzept & PRDs (V1.0)
- ⏳ Umsetzung PRD-001 – Projekt-Grundstruktur
- ⏳ Umsetzung PRD-002 bis PRD-005

Verhaltens- und Architekturregeln für Claude: [`CLAUDE.md`](CLAUDE.md).

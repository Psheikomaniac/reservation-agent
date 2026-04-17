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

## Bewusst NICHT in V1.0

| Feature                                 | Version |
|-----------------------------------------|---------|
| OpenTable / TheFork / Quandoo Anbindung | V2.0    |
| Google Reserve / Google Business        | V2.0    |
| Automatischer Mailversand ohne Freigabe | V2.0    |
| Tischplan / grafische Sitzordnung       | V2.0    |
| Zahlungs-Hinterlegung / No-Show-Gebühr  | V2.0    |
| Mehrsprachige Antworten                 | V2.0    |
| Analytics / Reporting                   | V2.0    |
| Multi-Standort-Verwaltung > 5 Standorte | V3.0    |
| Mobile App                              | V3.0    |
| Lokale KI (Ollama/Llama)                | V3.0    |

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

## Status

- ✅ Konzept & PRDs (V1.0)
- ⏳ Umsetzung PRD-001 – Projekt-Grundstruktur
- ⏳ Umsetzung PRD-002 bis PRD-005

Verhaltens- und Architekturregeln für Claude: [`CLAUDE.md`](CLAUDE.md).

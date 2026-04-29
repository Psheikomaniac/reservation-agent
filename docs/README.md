# reservation-agent – PRD-Übersicht

KI-gestütztes Reservierungssystem für die Gastronomie.
**Zielgruppe:** inhabergeführte Restaurants und kleine Ketten (1–5 Standorte), die Reservierungen aktuell manuell über mehrere Kanäle bearbeiten.

---

## PRDs V1.0

| Nr. | Titel                                                   | Priorität | Phase     | Abhängigkeiten |
|-----|---------------------------------------------------------|-----------|-----------|----------------|
| [001](PRD-001-project-foundation.md)     | Projekt-Grundstruktur & Datenmodell   | P0 | Woche 1    | –              |
| [002](PRD-002-web-reservation-form.md)   | Öffentliches Web-Reservierungs-Formular | P0 | Woche 2    | 001            |
| [003](PRD-003-email-ingestion.md)        | E-Mail-Ingestion (IMAP)              | P0 | Woche 2–3  | 001            |
| [004](PRD-004-reservation-dashboard.md)  | Reservierungs-Dashboard              | P0 | Woche 3    | 001, 002, 003  |
| [005](PRD-005-ai-reply-assistant.md)     | KI-Antwort-Assistent                  | P1 | Woche 4    | 001, 004       |

---

## Entwicklungs-Roadmap V1.0

```
Woche 1   │ PRD-001 │ Laravel-Skeleton, Entities, Migrations, Auth
Woche 2   │ PRD-002 │ Öffentliches Formular + Bestätigung
          │ PRD-003 │ IMAP-Fetch-Job + Parser (Start)
Woche 3   │ PRD-003 │ IMAP-Parser finalisieren, Error-Handling
          │ PRD-004 │ Dashboard-Listing, Filter, Detail-Drawer
Woche 4   │ PRD-005 │ KI-Kontext-Builder, Prompt, Freigabe-Workflow, Mailversand
Woche 5   │  –      │ Testing, Bugfixing, erstes Pilot-Restaurant
```

---

## PRDs V2.0

V2.0 baut auf V1.0 auf und schließt die wichtigsten Lücken, die ohne Pilotbetrieb absehbar schmerzen werden – plus den meistgewünschten Komfort-Features.

| Nr. | Titel                                                     | Priorität | Phase | Abhängigkeiten      |
|-----|-----------------------------------------------------------|-----------|-------|---------------------|
| [006](PRD-006-mail-threading.md)            | Mail-Threading                                | P0 | V2-Phase 1 | 003                |
| [007](PRD-007-auto-send-trust-modes.md)     | Auto-Versand mit Vertrauensstufen             | P0 | V2-Phase 2 | 005, 006           |
| [008](PRD-008-dashboard-analytics.md)       | Dashboard-Analytics                           | P1 | V2-Phase 3 | 004, 006, 007      |
| [009](PRD-009-export-csv-pdf.md)            | Export CSV/PDF                                | P2 | V2-parallel | 004                |
| [010](PRD-010-push-and-sound-alerts.md)     | Push & Sound-Alerts                           | P2 | V2-parallel | 004                |

### Phasen-Reihenfolge

```
Phase 1 (Fundament)
└── PRD-006  Mail-Threading
              │
              ▼
Phase 2 (Kernfeature)
└── PRD-007  Auto-Versand (Manual / Shadow / Auto)
              │
              ▼
Phase 3 (Sichtbar machen)
└── PRD-008  Dashboard-Analytics

Parallel zu Phase 2/3 (unabhängig)
├── PRD-009  Export CSV/PDF
└── PRD-010  Push & Sound-Alerts
```

**Architekturkonsequenz V2.0:** PRD-007 hebt das V1.0-Verbot „kein automatischer Mailversand ohne Freigabe" als opt-in mit Hard-Gates auf. Die Anpassung von [`CLAUDE.md`](../CLAUDE.md) ist Teil des V2.0-Release-PRs (siehe Risiken-Abschnitt in PRD-007).

---

## Kernarchitektur-Prinzip

```
Laravel entscheidet (deterministisch)    KI formuliert (Text)
─────────────────────────────────        ──────────────────────
Tischverfügbarkeit (DB-Abfrage)     →    Freundlicher Antworttext
Öffnungszeiten-Prüfung                   Formulierung: Bestätigung/Ablehnung
Kapazitäts-/Slot-Regeln                  Tonalität passend zum Restaurant
Status-Übergänge (new→replied→...)       Auf Deutsch, höflich, eindeutig
```

**Die KI entscheidet niemals über Verfügbarkeit. Sie formuliert nur.**
Jede automatische Antwort durchläuft einen Freigabe-Workflow – kein KI-generierter Text erreicht ohne menschliche Bestätigung einen Kunden.

---

## Roadmap nach V1.0

| Feature                                          | Version | Status                                |
|--------------------------------------------------|---------|---------------------------------------|
| Mail-Threading                                   | V2.0    | scoped → [PRD-006](PRD-006-mail-threading.md) |
| Automatischer Mailversand (opt-in mit Hard-Gates)| V2.0    | scoped → [PRD-007](PRD-007-auto-send-trust-modes.md) |
| Analytics / Reporting (Counters & 30-Tage-Trend) | V2.0    | scoped → [PRD-008](PRD-008-dashboard-analytics.md) |
| Export CSV/PDF                                   | V2.0    | scoped → [PRD-009](PRD-009-export-csv-pdf.md) |
| Push-Benachrichtigungen / Sound-Alerts / Digest  | V2.0    | scoped → [PRD-010](PRD-010-push-and-sound-alerts.md) |
| Tischplan / grafische Sitzordnung                | V3.0    | (Robustheit + Tischplan)              |
| WebSockets via Laravel Reverb (Polling-Ablöse)   | V3.0    | siehe [decision](decisions/polling-vs-websockets-v1.md) |
| DSGVO-UI für Betroffenen-Löschanträge            | V3.0    | siehe [decision](decisions/failed-email-imports-retention.md) |
| Multi-Standort-Verwaltung > 5 Standorte          | V3.0    |                                       |
| KI lernt aus manuellen Edits (RAG / Fine-Tuning) | V4.0    | (KI-Differenzierung)                  |
| Tonalität / Prompt pro Restaurant statt global   | V4.0    | siehe [decision](decisions/ai-tonality-prompts.md) |
| Mehrsprachige Antworten                          | V4.0    |                                       |
| Lokale KI (Ollama / vLLM)                        | V4.0+   | siehe [decision](decisions/openai-data-protection.md) |
| Embeddable Widget / iframe-Variante              | V5.0    | (Reichweite)                          |
| OpenTable / TheFork / Quandoo Anbindung          | V5.0    | (Reichweite)                          |
| Google Reserve / Google Business                 | V5.0    | (Reichweite)                          |
| Mobile App                                       | V5.0+   | eigene Produkt-Linie                  |
| Zahlungs-Hinterlegung / No-Show-Gebühr           | offen   | nicht versionsgebunden                |

---

## Preismodell V1.0 (Entwurf)

| Plan         | Preis       | Limit                                       |
|--------------|-------------|---------------------------------------------|
| Starter      | 39 €/Monat  | 1 Standort, 100 Reservierungen/Monat        |
| Professional | 79 €/Monat  | 1 Standort, unbegrenzt, eigenes E-Mail-Branding |
| Business     | 149 €/Monat | bis 5 Standorte, unbegrenzt                 |

> Finales Preismodell wird vor dem Pilot-Onboarding in V1.0 festgelegt.

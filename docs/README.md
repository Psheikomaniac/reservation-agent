# reservation-agent – PRD-Übersicht V1.0

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

## Entwicklungs-Roadmap

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

## Preismodell V1.0 (Entwurf)

| Plan         | Preis       | Limit                                       |
|--------------|-------------|---------------------------------------------|
| Starter      | 39 €/Monat  | 1 Standort, 100 Reservierungen/Monat        |
| Professional | 79 €/Monat  | 1 Standort, unbegrenzt, eigenes E-Mail-Branding |
| Business     | 149 €/Monat | bis 5 Standorte, unbegrenzt                 |

> Finales Preismodell wird vor dem Pilot-Onboarding in V1.0 festgelegt.

# Entscheidung: 30-Tage-Retention für `failed_email_imports` (DSGVO)

**Status:** angenommen
**Datum:** 2026-04-24
**Bezug:** PRD-003 (E-Mail-Ingestion) § Risiken & offene Fragen, Issue #41

## Kontext

Die Tabelle `failed_email_imports` ist die Quarantäne des E-Mail-Ingest-Jobs (`FetchReservationEmailsJob`). Sobald eine Mail den Parser crashen lässt oder aus anderen Gründen keinen `ReservationRequest` erzeugt, landet sie mit `raw_headers`, `raw_body` und einem `error`-String in dieser Tabelle. Der Fetch-Lauf bricht nicht ab – die Mail wird aufgehoben, damit der Betreiber das Parser-Problem in Ruhe analysieren kann.

`raw_headers` und `raw_body` enthalten typischerweise personenbezogene Daten im Sinne der DSGVO: Absender-Adresse, Name, Uhrzeit, eventuell Telefonnummer, freier Text des Gastes. Art. 5 Abs. 1 lit. e DSGVO verlangt eine explizite, begründete Aufbewahrungsdauer und eine technische Umsetzung der Löschung. Ohne beides dürfen wir nicht produktiv gehen.

PRD-003 hat 30 Tage als Vorschlag in die Offene-Fragen-Liste gestellt. Diese Entscheidung schreibt ihn als verbindliche Richtlinie für V1.0 fest.

## Entscheidung

**V1.0 löscht Einträge in `failed_email_imports` hart (kein Soft-Delete) nach 30 Tagen.** Die Löschung erfolgt über einen täglich laufenden Laravel-Job (`PruneFailedEmailImportsJob`), steuerbar über zwei Config-Keys in `config/reservations.php`:

| Key                                                     | Default | Zweck                                                                   |
|----------------------------------------------------------|---------|--------------------------------------------------------------------------|
| `reservations.failed_email_imports.prune.enabled`        | `true`  | Feature-Flag zum Deaktivieren in Ausnahmefällen (z. B. Incident-Forensik) |
| `reservations.failed_email_imports.prune.retention_days` | `30`    | Aufbewahrungsfrist in Tagen                                              |

Beide Keys sind via `env()` überschreibbar:

- `FAILED_EMAIL_IMPORTS_PRUNE_ENABLED`
- `FAILED_EMAIL_IMPORTS_RETENTION_DAYS`

Der Schedule-Eintrag läuft einmal pro Nacht um 03:00 mit `withoutOverlapping()`. Fehler im Job führen nicht zum Daten-Rollback – die nächste Nacht räumt erneut auf.

## Audit-Log

Jeder Lauf schreibt genau eine strukturierte Log-Zeile auf Level `info`:

```
channel:   default
message:   reservation.failed_email_imports.pruned
context:   { deleted_count: int, retention_days: int, oldest_deleted_at: iso8601|null, cutoff: iso8601 }
```

**Nicht geloggt werden:** `message_id`, Absender, `raw_headers`, `raw_body`, `error`, `restaurant_id`, Mail-Inhalte jeglicher Art. Das Audit-Log beantwortet ausschließlich die Frage *„Haben wir heute Nacht X Einträge gelöscht und wie alt war der älteste?"* – ausreichend für eine DSGVO-Rechenschaftspflicht (Art. 5 Abs. 2), ohne neue Personendaten in das Log-System zu tragen.

`oldest_deleted_at` ist informativ (hilft, verpasste Läufe zu erkennen) und enthält keine personenbezogenen Daten, da es nur ein Zeitstempel ist.

## Begründung

- **30 Tage** sind im deutschsprachigen Raum eine etablierte Hausnummer für „operative E-Mail-Quarantäne" (vgl. Spam-Quarantänen bei gängigen Mail-Providern). Kürzer (7/14 Tage) killt das Feld-Debugging durch den Betreiber bei seltenen Parser-Fehlern; länger (60/90 Tage) erhöht die DSGVO-Exposition ohne Mehrwert, weil Parser-Bugs normalerweise innerhalb weniger Tage sichtbar werden.
- **Hart statt Soft-Delete:** Die Tabelle ist eine Quarantäne, kein Archiv. Soft-Delete würde den Löschanspruch nach Art. 17 DSGVO nicht erfüllen – der Datensatz bliebe logisch vorhanden, nur ausgeblendet. Das wollen wir nicht.
- **Feature-Flag statt Config-only:** Im Incident-Fall (z. B. systematischer Parser-Bug, der über Tage importiert wird und forensisch aufgeklärt werden soll) muss der Betreiber das Pruning per Deployment-Env-Var kurzfristig aussetzen können, ohne Code zu ändern. Das Flag kippt sichtbar, nicht still.
- **Keine Per-Restaurant-Konfiguration** in V1.0. Solange alle Pilot-Restaurants auf derselben Instanz laufen und dieselbe DSGVO-Grundlage gilt, ist eine globale Frist das einfachste, juristisch und technisch sauber umsetzbare Modell.
- **Tägliche Ausführung, nicht stündlich:** Die Datenmenge ist klein (Quarantäne-Tabelle), die Frist ist tage-, nicht stundenskaliert. Ein täglicher Lauf hält den Log-Stream ruhig und ist für Incident-Rekonstruktion fein genug.

## Datenschutz-Dokumentation (DPA / Datenschutzerklärung)

Die folgenden Formulierungen sind in die öffentliche Datenschutzerklärung des Betreibers und in den Auftragsverarbeitungsvertrag (DPA) aufzunehmen. Das ist **kein** Teil dieses Code-Repositorys, sondern eine operative Vorleistung vor Go-Live.

- **Verarbeitungszweck:** Technische Fehlerdiagnose des E-Mail-Ingest-Parsers.
- **Datenkategorien:** Vollständiger Inhalt der Quarantäne-Mail inkl. Absender, ggf. Telefon, Freitext.
- **Rechtsgrundlage:** Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse: Funktionsfähigkeit des Reservierungssystems). Interessenabwägung dokumentieren.
- **Speicherdauer:** 30 Tage ab Eintreffen der Mail, danach automatische, nicht wiederherstellbare Löschung.
- **Auskunfts-/Löschrecht:** Betroffene können jederzeit die sofortige Löschung eines Quarantäne-Eintrags vor Ablauf der 30-Tage-Frist verlangen. Ausführung: manueller `DELETE` durch den Betreiber; kein UI in V1.0.

## Trigger für Änderung des Zeitfensters

Wechsel von 30 Tagen auf eine andere Frist wird dann angefasst, sobald **einer** dieser Punkte greift:

1. **Betreiber-Feedback:** Parser-Fehler werden nach eigener Aussage regelmäßig erst nach > 20 Tagen bemerkt. Dann ist 30 Tage zu knapp, Verlängerung auf 45 oder 60 Tage sinnvoll.
2. **Aufsichtsrechtliche Vorgabe:** Landesdatenschutzbehörde oder Branchenverband gibt eine abweichende Höchst-/Mindestfrist vor.
3. **Menge:** Die Tabelle wächst dauerhaft über 10.000 Einträge an, was auf einen systematischen Parser-Bug hinweist. Dann ist der Bug zu fixen, nicht das Fenster zu verkürzen – die Entscheidung hier bleibt unverändert, der Ingest wird vorübergehend pausiert.

Jede Änderung erfordert Update dieser Datei, Anpassung der Datenschutzerklärung und ein Folge-Issue.

## Nicht in diesem Dokument

- UI für Betroffenen-Löschantrag (V3.0, eigenes Issue – siehe Update unten)
- Export von Quarantäne-Einträgen zur Offline-Analyse (nicht geplant)
- Separate Retention-Frist für `reservation_requests` (eigene Entscheidung, wenn relevant)

## Update April 2026 – Verschiebung der DSGVO-UI auf V3.0

Diese Entscheidung hat in ihrer ursprünglichen Fassung die Betroffenen-Löschantrags-UI als „V2.0, eigenes Issue" geführt. Im Rahmen der V2.0-Roadmap-Brainstorming-Session (siehe [`docs/README.md`](../README.md) Abschnitt „Roadmap nach V1.0") wurde V2.0 bewusst auf das Schließen der zentralen Pilot-Pain-Points fokussiert (Threading, Auto-Versand, Analytics, Export, Push). Die DSGVO-UI wandert in V3.0 zusammen mit anderen operativen Robustheits-Themen (Reverb, Multi-Standort > 5).

**Begründung:** Die 30-Tage-Auto-Löschung aus dieser Entscheidung erfüllt Art. 17 DSGVO bereits ohne UI – Betroffene können einen Löschantrag heute schon manuell durch den Restaurant-Owner per Tinker oder DB-Query bearbeiten lassen. Eine eigene UI ist Komfort, kein Compliance-Engpass. Die Verschiebung schiebt also keine rechtliche Pflicht auf.

**Folgen für diese Entscheidung:** keine. Die Retention-Logik (30 Tage, hart) und das Audit-Log bleiben unverändert. Nur der „Nicht in diesem Dokument"-Hinweis ändert seine Versionsangabe.

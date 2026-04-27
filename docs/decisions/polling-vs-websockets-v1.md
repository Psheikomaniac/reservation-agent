# Entscheidung: Polling statt WebSockets in V1.0

**Status:** angenommen
**Datum:** 2026-04-27
**Bezug:** PRD-004 (Reservierungs-Dashboard), Issue #60

## Kontext

Das Dashboard (PRD-004) muss neue Reservierungsanfragen zeitnah anzeigen. Zwei Mechanismen stehen zur Wahl:

- **Polling** via Inertia Partial Reload alle 30 s, nur solange `document.visibilityState === 'visible'`. Tab im Hintergrund → kein Traffic.
- **WebSockets** via Laravel Reverb mit Broadcasting-Events (z. B. `ReservationRequestReceived`).

PRD-004 listet die Frage explizit unter "Risiken & offene Fragen": Polling kostet bei vielen aktiven Tabs spürbar DB-Last; WebSockets kosten zusätzliche Infrastruktur und Komplexität (Reverb-Prozess, Auth-Channel-Konfiguration, Reconnect-Logik, Skalierungs-Setup).

## Entscheidung

**V1.0 nutzt Polling.** Kein WebSocket-Stack, kein Reverb, keine Broadcasting-Events. Die Entscheidung kippt automatisch, sobald einer der unten definierten Trigger zieht.

Die V2.0-Implementierung — wenn sie kommt — basiert auf **Laravel Reverb** (Erstanbieter-Stack, läuft im selben Prozessmodell wie der Rest, benötigt keine externe Pusher-Anbindung).

## Begründung

- **Zero zusätzliche Infrastruktur.** Polling ist clientseitiges JavaScript + bestehende Inertia-Partial-Reloads. Kein neuer Service, kein neuer Port, kein neuer Health-Check, kein neuer Failure-Modus im Pilot.
- **Zielgruppe ist klein.** V1.0 zielt auf inhabergeführte Restaurants und Ketten mit 1–5 Standorten. Realistisch sind 1–3 Tabs gleichzeitig pro Restaurant (Inhaber + Service). > 50 Tabs sind in der Pilot-Phase ein hypothetisches Szenario.
- **Visibility-Gating dämpft die Last.** Hintergrund-Tabs zählen nicht. Damit ist die effektive Polling-Last deutlich niedriger als "alle jemals geöffneten Tabs × 2 QPS/min".
- **Inertia ist Single Source of Truth.** Polling läuft über `router.reload({ only: [...] })` — der Server liefert dieselbe Datenform wie beim ersten Render. Keine Divergenz zwischen "Initial-Page" und "Live-Update", die bei WebSocket-Eventströmen typischerweise erst durchexerziert werden muss.
- **Reverb addiert Operations-Overhead.** Eigener Prozess, Channel-Authorisierung pro Restaurant (Tenant-Scope auf private Channels), Reconnect-Strategie, Monitoring. Lohnt sich, sobald Polling teuer wird — vorher nicht.

## Trigger für Umstellung auf WebSockets

Umschalten auf Reverb-basiertes Broadcasting, sobald **einer** dieser Punkte über mindestens **eine Stunde sustained** erreicht ist (nicht bei einzelnen Spikes). Messgrundlage: Application-Logs + DB-Server-Metriken.

1. **Aktive Tabs pro Restaurant: > 50 gleichzeitig.** Messung über eine clientseitig generierte Tab-ID, die der Poll-Request als Header mitschickt; serverseitig 5-Minuten-Fenster pro `restaurant_id`. Rationale: ab ~50 sichtbaren Tabs liefert ein 30-s-Poll mehr Last als ein dauerhafter WebSocket pro Tab.
2. **Polling-attributierte DB-QPS: > 30 QPS sustained.** Konkret die Summe aus Dashboard-`index`- und Stats-Query, identifiziert über einen Query-Tag oder eine dedizierte Route. Rationale: bei 30 QPS bestimmt Polling den Read-Traffic des Systems — Reverb mit serverseitigem Push wäre dann pro Update billiger als 30 Reload-Roundtrips pro Sekunde.
3. **p95-Latenz `GET /dashboard`: > 500 ms.** Reine Server-Renderzeit, ohne Netzwerk. Rationale: ab 500 ms wirkt das Dashboard träge, unabhängig von der Ursache — wenn Polling hier signifikant beiträgt, lohnt der Wechsel.
4. **DB-CPU sustained: > 60 %.** Ganzheitlicher Last-Indikator. Rationale: Headroom für IMAP-Fetch-Bursts und KI-Antwort-Generierung muss erhalten bleiben; Polling darf die Sättigung nicht selbst herbeiführen.

Wird ein Trigger ausgelöst, ist die Entscheidung "WebSockets aktivieren" binnen zwei Wochen zu treffen und zu dokumentieren (Update dieser Datei + Folge-Issue mit Reverb-Setup).

## Implementierung bei Nachrüstung (Skizze)

- `composer require laravel/reverb` + `php artisan reverb:install`
- Neuer Daemon: `php artisan reverb:start` (zusätzlicher Service-Eintrag im Deployment)
- Private Channel `restaurant.{id}` mit `Broadcast::channel(...)` und Tenant-Check via Policy
- `ReservationRequestReceived` Event implementiert `ShouldBroadcast`; Listener entfällt — Inertia-Frontend abonniert den Channel direkt via Echo
- Polling im Dashboard wird **nicht entfernt**, sondern auf > 5 min Intervall gestellt als Reconciliation-Layer (WebSocket-Reconnect-Lücken auffangen)
- Kein API-Change am Dashboard-Endpoint — der Reload-Pfad bleibt funktional und ist Fallback bei Verbindungsverlust

## Nicht in diesem Dokument

- Konkrete Reverb-Konfiguration (eigenes PRD/Issue sobald Trigger greift)
- Pusher als Hosted-Alternative (verworfen — Reverb ist Laravel-Erstanbieter, keine zusätzliche Vendor-Abhängigkeit nötig)
- Server-Sent Events (SSE) als Mittelweg (verworfen — adressiert dieselben Pain Points wie WebSockets, ohne deren Bidirektionalität zu nutzen, und Laravel hat keinen Erstanbieter-Stack dafür)

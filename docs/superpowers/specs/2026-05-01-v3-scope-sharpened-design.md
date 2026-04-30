# V3-Scope geschärft – Design-Spec

**Datum:** 2026-05-01
**Status:** Akzeptiert. Supersedet den V3-Abschnitt von [`2026-04-29-v3-plus-roadmap-design.md`](2026-04-29-v3-plus-roadmap-design.md). V4–V7 dort gelten weiter, ergänzt um zwei aus V3 verschobene Bausteine.
**Auslöser:** V2.0 ist auf `main` released. Akquise für ein erstes V2-Pilotrestaurant läuft. Parallel begleitet ein befreundeter Restaurant-Inhaber den V3-Bau als Early-Access-Beobachter, ohne Echt-Pilot-Status. Daraus folgt eine inhaltliche Neuschneidung von V3, die in der April-Spec nicht abgedeckt war.

---

## 1. Was sich gegenüber der April-Spec ändert

| Bereich | April-Spec | Mai-Spec | Begründung |
|---|---|---|---|
| **Theme V3** | „Operative Vollständigkeit" – alle Reservierungen in einem System | Bleibt, aber mit verschobenem Kern: **Verfügbarkeit live erkennbar machen** statt nur „Telefon-/Walk-in-Erfassung möglich" | Pain-Point ist nicht Eingabe-Geschwindigkeit, sondern „kann ich am Telefon verlässlich sagen, ob in 4 Tagen Samstag 19 Uhr noch frei ist". |
| **PRD-011** | Tisch-Liste mit Belegungsstatus, ~1.5–2 W | **Verfügbarkeits-Modell + Tisch-Liste**, ~3–4 W | Tisch-Daten sind nur Mittel zum Zweck. Der eigentliche Wert ist die Verfügbarkeits-Berechnung als jederzeit abfragbare Sicht – nicht eine CRUD-Tabelle. |
| **PRD-012** | Manuelle Erfassung Telefon/Walk-in | Bleibt, aber **mit Live-Verfügbarkeit als Konsument von PRD-011** | Telefon-Eingabe ohne Live-Antwort wäre nutzlos – das ist der Kunden-Pain. |
| **PRD-013** | Warteliste passiv | Bleibt unverändert | Beifang, der Verfügbarkeits-Modell ohnehin nutzt. |
| **PRD-014** | Reverb für V3-Surfaces | **Inhalt verschoben in V4 als PRD-021. Slot 014 in V3 neu besetzt mit „Sync-Web-Confirm"** | Reverb-Operations-Last (eigener Daemon, Channel-Auth, Reconnect, Monitoring) lohnt sich erst mit mehreren parallelen Bedienern. Ein Restaurant + Owner = Polling reicht. |
| **PRD-015** | DSGVO Art. 15–22 voll, ~3–4 W | **Reduziert auf Art. 15 + 17 mit Endkunden-UI**, ~1.5–2 W. Voller Umfang in V4 als PRD-022 | Art. 15 (Auskunft) + Art. 17 (Löschung) sind die zwei Artikel, die Endkunden in der Gastro-Praxis tatsächlich auslösen. Berichtigung/Einschränkung/Übertragbarkeit/Widerspruch sind ohne Stammgast-Profil aus PRD-016 ohnehin sinnarm. |
| **Neu** | – | **PRD-014 Sync-Web-Confirm:** Web-Form-Anfragen, die deterministisch frei sind, werden direkt nach Submit bestätigt. Mail in <60 s. | „Online buchen ist attraktiv, wenn die Bestätigung sofort kommt" ist der einzige V3-Hebel auf Besucher-Akquise. Knüpft technisch an PRD-007 (Auto-Send) an, mit härteren Hard-Gates: nicht „Owner hat `auto` aktiviert", sondern „Slot ist objektiv frei + alle PRD-007-Gates grün". |

**Verschiebungen sind in V4 fest verankert** – nicht „spätere Version unbestimmt":

- **PRD-021 (V4)** – Reverb breit, alle Surfaces. Voraussetzung: ≥ 2 Pilot-Restaurants × 4 Wochen Bedien-Daten → Trigger aus [`docs/decisions/polling-vs-websockets-v1.md`](../../decisions/polling-vs-websockets-v1.md) gezogen.
- **PRD-022 (V4)** – DSGVO voll (Art. 16/18/20/21/22). Voraussetzung: Stammgast-Profil aus PRD-016 existiert, sodass „alle Daten zu Person X" eine sinnvolle Aggregation ergibt.

---

## 2. V3-Schnitt (final)

| Nr. | Titel | Aufwand | Reihenfolge |
|-----|---|---|---|
| **PRD-011** | Verfügbarkeits-Modell + Tisch-Liste | ~3–4 W | 1. (Fundament für 012/013/014) |
| **PRD-012** | Manuelle Erfassung Telefon/Walk-in mit Live-Verfügbarkeit | ~1.5 W | 2. (nach 011) |
| **PRD-013** | Warteliste passiv | ~1 W | 3. (nach 011) |
| **PRD-014** | Sync-Web-Confirm bei eindeutig freien Slots | ~1.5 W | parallel zu 012/013 möglich, sobald 011 grün |
| **PRD-015** | DSGVO-Endkunden-UI (Art. 15 + 17) | ~1.5–2 W | parallel ab Tag 1, **kein** PRD-011-Block |

**Gesamt:** ~9–11 Wochen, parallelisierbar auf ~6–8 Kalenderwochen wie ursprünglich gespect, wenn 014 + 015 parallel zu 012 + 013 laufen.

### Phasen-Reihenfolge

```
Phase 1 (Fundament)
└── PRD-011  Verfügbarkeits-Modell + Tisch-Liste
              │
              ├──────────────┬──────────────┐
              ▼              ▼              ▼
Phase 2 (Konsumenten)
├── PRD-012  Telefon-/Walk-in-Erfassung
├── PRD-013  Warteliste passiv
└── PRD-014  Sync-Web-Confirm

Parallel ab Tag 1 (kein 011-Block)
└── PRD-015  DSGVO-Endkunden-UI Art. 15 + 17
```

---

## 3. Verfügbarkeits-Modell – Architektur-Skizze

Die zentrale Frage: **Wie wird der Verfügbarkeits-Check zu einer jederzeit abfragbaren Sicht, ohne dass jeder Konsument den `ReservationContextBuilder` neu aufruft?**

### 3.1 Bestehender Stand

`ReservationContextBuilder` (PRD-005) berechnet pro Reservierungsanfrage einen Slot-Vorschlag. Er ist:

- **stateless** – pro Aufruf eine DB-Query auf `reservations`-Tabelle für den Zielslot,
- **per-Request** – nicht für interaktive UI-Aufrufe gedacht,
- **liefert nur 1 Antwort** – nicht „alle Slots des Tages mit Belegung".

### 3.2 V3-Erweiterung

Wir trennen zwei Sichten:

- **`SlotAvailability`-Service (neu, PRD-011):** Berechnet für ein Restaurant + Datum einen kompletten Tag in 30-Minuten-Slots. Pro Slot: gesamte Kapazität, belegte Plätze, freie Plätze, freie Tische. Wird von Tisch-Liste, Telefon-Form, Web-Form-Sync-Check und Warteliste-Banner verwendet. Single Source of Truth.
- **`ReservationContextBuilder` (bestehend):** Bleibt für die KI-Antwort-Pipeline zuständig. Ruft intern den neuen `SlotAvailability` auf statt eigener Slot-Query. Output-Format unverändert (kein PRD-005-Bruch).

### 3.3 Datenmodell-Erweiterung

**Neue Tabelle `tables`** (Tisch-Stammdaten pro Restaurant):

| Spalte | Typ | Notiz |
|---|---|---|
| id | bigint PK | |
| restaurant_id | bigint FK | indexiert |
| label | string | „Tisch 7", „Terrasse 3" |
| seats | unsignedTinyInteger | Plätze, 1–20 |
| room_tag | string null | „Innen", „Terrasse", „Raucherbereich" |
| sort_order | unsignedSmallInteger | Anzeige-Reihenfolge in Liste |
| active | boolean | inaktive Tische zählen nicht in Verfügbarkeit |
| created_at, updated_at | timestamps | |

**Neue Tabelle `reservation_table_assignments`** (welche Reservierung belegt welche Tische):

| Spalte | Typ | Notiz |
|---|---|---|
| id | bigint PK | |
| reservation_request_id | bigint FK | indexiert |
| table_id | bigint FK | indexiert |
| assigned_at | datetime | wann zugewiesen |
| assigned_by_user_id | bigint FK null | null = Auto-Vorschlag |

Eine Reservierung kann mehrere Tische belegen (zusammengeschobene Tische bei größeren Gruppen). Ein Tisch in einem Slot kann nur einer Reservierung gehören.

### 3.4 Slot-Logik (zusammengefasst, Detail in PRD-011)

Ein Slot ist frei für eine Anfrage, wenn:

1. Restaurant am Ziel-Datum geöffnet ist (`opening_hours` aus PRD-001).
2. Slot innerhalb der Öffnungszeiten liegt.
3. Mindestens **eine Tisch-Kombination** existiert, die die Personenzahl deckt und im Ziel-Slot **plus Pufferzeit** (Default 90 min, konfigurierbar pro Restaurant) keine andere Reservierung hat.
4. Pufferzeit gilt symmetrisch: 90 min nach Ende der Vor-Reservierung am selben Tisch und 90 min vor Start der Folge-Reservierung.

**Auswirkung auf bestehenden Code:** `ReservationContextBuilder` lieferte bisher nur „Slot frei oder nicht" anhand einer einfachen Personenzahl-Summe pro Slot. Mit Tischen wird die Logik strenger – ein Slot kann insgesamt 40 freie Plätze haben, aber für eine 8er-Gruppe trotzdem belegt sein, wenn kein 8er-Tisch oder keine zwei zusammenschiebbaren Tische frei sind. PRD-011 muss eine Migration der Bestandsdaten beinhalten: für Pilot-Daten aus V1+V2 wird ein „Default-Tisch"-Stamm pro Restaurant generiert, damit die Slot-Logik ohne manuelles Tisch-Pflegen weiter funktioniert.

---

## 4. PRD-Übersicht (Bullet)

Detail siehe pro PRD-Datei.

### PRD-011 – Verfügbarkeits-Modell + Tisch-Liste

- Datenmodell: `tables`, `reservation_table_assignments`, optional `restaurants.slot_buffer_minutes`
- Service `SlotAvailability` mit Methoden `forDay($date)`, `forSlot($date, $time, $partySize)`, `freeTablesAt($date, $time, $partySize)`
- Tisch-Liste-UI: `resources/js/pages/Tables.vue` – Stammdaten-Tabelle (CRUD) + Tages-Belegungs-Sicht
- Auto-Tisch-Vorschlag bei manueller Zuweisung (kleinster passender Tisch / kleinste Kombi)
- Migrations-Seed: pro Bestands-Restaurant ein „Default-Tisch" mit Sitzplätzen = max(`party_size`) + 4

### PRD-012 – Manuelle Erfassung Telefon/Walk-in mit Live-Verfügbarkeit

- Internes Form `resources/js/pages/Reservations/Quick.vue` – Single-Screen, Tastatur-fokussiert
- Smart-Defaults: heute, jetzt+1h, 2 Personen
- Live-Verfügbarkeit: bei jeder Eingabe-Änderung (debounced 250 ms) `SlotAvailability::forSlot(...)` per Inertia Partial Reload
- Anzeige: ✅ frei + Tisch-Vorschlag / ⚠️ knapp / ❌ belegt + nächste 3 freie Slots als Vorschlag
- Persistierung: `ReservationRequest` mit `source = phone` oder `walk_in`, Status `confirmed` direkt (keine KI-Antwort, keine Mail)
- Auto-Tisch-Zuweisung beim Speichern (kleinster passender Tisch)

### PRD-013 – Warteliste passiv

- Status `waitlisted` als neuer Übergang aus `new` und `in_review`
- Filter „Warteliste" im Dashboard
- Banner im Dashboard, wenn ein bestätigter Slot durch Cancel frei wird und ≥ 1 Wartender für überschneidenden Slot existiert: „1 Wartender für Mi 19 Uhr, jetzt frei"
- **Kein** automatischer E-Mail-Versand (das ist V4 PRD-017)
- Banner verlinkt direkt auf den Wartenden-Detail-Drawer

### PRD-014 – Sync-Web-Confirm bei eindeutig freien Slots

- Erweiterung von `StoreReservationRequest`-Controller (PRD-002): nach Persistierung **synchron** prüfen, ob alle Bedingungen für Sofort-Bestätigung greifen
- Hard-Gates (alle müssen erfüllt sein): Slot deterministisch frei (PRD-011 sagt "frei"), Personenzahl ≤ Restaurant-Default (PRD-007 `party_size_over_limit`-Logik), Vorlauf ≥ Restaurant-Mindest-Vorlauf (`short_notice` aus PRD-007), Restaurant-Setting `web_sync_confirm_enabled = true`
- Bei allen Gates grün: Status direkt `confirmed`, KI-Antwort sofort generieren (sync, nicht via Job, weil Antwortzeit-kritisch), Mail via `Mail::send` direkt (nicht queued)
- Bei einem Gate rot: V1.0-Pfad (Status `new`, Owner-Freigabe nötig)
- UI: Bestätigungs-Seite zeigt entweder „bestätigt – Mail kommt" oder „Anfrage eingegangen – wir melden uns"
- Killswitch über `web_sync_confirm_enabled`-Flag pro Restaurant; Default `false` für Bestands-Restaurants

### PRD-015 – DSGVO-Endkunden-UI Art. 15 + 17

- **Art. 15 (Auskunft):** Jede outbound Bestätigungs-Mail enthält einen signed Link „Welche Daten haben wir von dir?" – Link führt auf öffentliche HTML-Seite, kein Login. Zeigt: Reservierungs-Inhalt, Sende-Datum, optional Notizen wenn vom Owner für Gast freigegeben (V3 Default: nicht freigegeben).
- **Art. 17 (Löschung):** Auf derselben Seite Button „Meine Daten löschen". Hard-Delete von `reservation_request`, allen `reservation_messages` und `reservation_replies`. Audit-Log-Eintrag mit `restaurant_id`, `deleted_at`, **ohne** Personendaten.
- **Owner-Sicht:** Im Dashboard Such-Maske „Reservierungen zu E-Mail X" (existiert in Filter-Form bereits, wird ergänzt um Bulk-Lösch-Button für die Treffer).
- Self-Service-Link signed, 30 Tage gültig, in jeder ausgehenden Mail erneuert.
- Audit-Tabelle `gdpr_audits` mit `action` (`view`, `delete`), `restaurant_id`, `created_at`, **ohne** PII.

---

## 5. Was V3 explizit NICHT enthält

Gilt für die ganze V3-Welle, gilt **nicht** als Roadmap-Verschiebung, weil die Inhalte in V4+ schon bestehen:

- **Aktive Warteliste mit Notify-Mail** – V4 PRD-017
- **Reverb / WebSockets breit** – V4 PRD-021 (verschoben aus V3-014)
- **Voller DSGVO-Workflow Art. 16/18/20/21/22** – V4 PRD-022 (verschoben aus V3-015)
- **Grafischer Tischplan** – spätere Version, weiterhin offen
- **Stammgast-Profil** – V4 PRD-016
- **No-Show-Tracking** – V4 PRD-018
- **Mehrsprache + RAG** – V4 PRD-019/020
- **Tonalität pro Restaurant** – V5
- **Marktplatz-Integrationen** – V6
- **Grafischer Tischplan** – „später", noch nicht versioniert (Pilot-Feedback abwarten)

---

## 6. Risiken pro V3-PRD

| PRD | Risiko | Plan B |
|---|---|---|
| 011 | Slot-Logik mit Pufferzeit + Tisch-Kombi ist schnell ein Performance-Problem bei vielen Tischen × vielen Slots | Eager-Loading aller Reservierungen für den Tag in einer Query, im PHP weiterrechnen statt N Sub-Queries. Falls trotzdem zu langsam: Per-Day-Cache mit Invalidation bei Reservierungs-Änderungen. |
| 012 | „Live-Verfügbarkeit" debounced 250 ms kann bei langsamer DB UI-Lag erzeugen | `SlotAvailability::forSlot` muss pro Aufruf < 100 ms liefern. Wenn nicht: Per-Day-Cache aus 011-Plan B greift hier ebenfalls. |
| 013 | Banner-Logik ist nicht trivial (cancel triggert keine Echtzeit-UI ohne Reverb) | Polling im Dashboard (V1-Mechanismus) erkennt den Cancel innerhalb 30 s und blendet den Banner ein. Reverb wäre schöner, ist aber V4. |
| 014 | Sync-Mail-Versand kann beim ersten Submit timeoutten, wenn SMTP träge ist | Hard-Timeout 5 s für Sync-Versand. Bei Timeout: Status auf `confirmed` belassen, Mail in queued Job legen, UI zeigt „bestätigt – Mail folgt". Schlechter als Sync, aber keine Fehlanzeige. |
| 015 | Self-Service-Lösch-Link kann von Spam-Bots ausgelöst werden, die irgendwo eine Mail-Inbox plündern | Signed-URL hat 30 Tage Gültigkeit, einmalige Aktion (Löschen ist idempotent). Confirm-Schritt vor dem Löschen verlangt zusätzlich Eingabe des Reservierungs-Datums – kein Spam-Bot wird das raten. |

---

## 7. Implementierungs-Reihenfolge & Issues

Pro PRD ein eigenes GitHub-Issue (Workflow aus `CLAUDE.md`). Reihenfolge:

1. **Issue: PRD-011** – Schemata, Service, UI, Tests. Voraussetzung für 012/013/014.
2. **Issue: PRD-015** – kann ab Tag 1 starten, kein Block durch 011.
3. **Issue: PRD-012** – nach 011 grün.
4. **Issue: PRD-014** – nach 011 grün.
5. **Issue: PRD-013** – nach 011 grün.

Issues 012, 013, 014 parallelisierbar – jedes ist <2 Wochen, jedes hat einen klaren Owner-Schnitt, jedes referenziert PRD-011 nur lesend.

V3-Release nach `main` erfolgt erst, wenn **alle fünf** PRDs auf `dev` gemerged + Pilot-Smoketest beim befreundeten Restaurant durchlaufen.

---

## 8. Was dieses Dokument explizit NICHT ist

- **Keine** Architektur-Entscheidung über Slot-Cache. Der Cache wird in PRD-011 entschieden, sobald Performance-Messungen vorliegen.
- **Keine** Detail-PRD. Detail-PRDs liegen als `PRD-011-…md` bis `PRD-015-…md` parallel zu diesem Dokument.
- **Keine** Verbindlichkeit für V4-Inhalte. V4-Detail wird gestartet, wenn V3 produktiv und ≥ 2 Pilot-Restaurants 4–6 Wochen Daten geliefert haben.
